<?php
/**
 * generate_teaser.php
 *
 * Назначение файла:
 * - точка входа для AJAX-запроса «Создать тизер» в личном кабинете продавца;
 * - формирует из данных анкеты структурированный payload, дополняет его снимком сайта компании;
 * - вызывает Together.ai (модель Qwen) для генерации текстов по строго заданной схеме;
 * - пост-обрабатывает ответы AI (нормализация чисел, доп. предложения, локализация блоков);
 * - рендерит HTML карточки, сохраняет снепшот в БД и возвращает JSON в интерфейс.
 *
 * Основные этапы исполнения:
 * 1. Проверка авторизации пользователя и наличия актуальной анкеты.
 * 2. buildTeaserPayload() — консолидирует данные анкеты, JSON-поля и снимок сайта в единый массив.
 * 3. buildTeaserPrompt() — формирует промпт для Together.ai, чтобы получить валидный JSON с блоками тизера.
 * 4. callTogetherCompletions() — отправляет запрос в Together.ai и возвращает текст ответа модели.
 * 5. parseTeaserResponse() / normalizeTeaserData() — разбирают JSON, дозаполняют пустые блоки фактами.
 * 6. ensureOverviewWithAi() и ensureProductsLocalized() — запускают дополнительные обращения к модели,
 *    чтобы лид-блок и «Продукты и клиенты» выглядели как готовый текст на русском языке.
 * 7. renderTeaserHtml() — собирает карточки, графики и списки, готовые к показу и печати.
 * 8. persistTeaserSnapshot() — кэширует HTML и метаданные в БД для повторного показа без генерации.
 *
 * Любые новые шаги (например, дополнительная нормализация полей) лучше добавлять между normalizeTeaserData()
 * и renderTeaserHtml(), чтобы сохранялась последовательность «данные → AI → пост-обработка → рендер».
 */
require_once 'config.php';
require_once __DIR__ . '/investor_utils.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    exit;
}

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Сессия недействительна.']);
    exit;
}

$requestPayload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($requestPayload)) {
    $requestPayload = [];
}
$action = $requestPayload['action'] ?? 'teaser';

$apiKey = TOGETHER_API_KEY;
if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API-ключ together.ai не настроен.']);
    exit;
}

try {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT *
        FROM seller_forms
        WHERE user_id = ?
          AND status IN ('submitted','review','approved')
        ORDER BY submitted_at DESC, updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $form = $stmt->fetch();

    if (!$form) {
        echo json_encode(['success' => false, 'message' => 'Нет отправленных анкет для формирования тизера.']);
        exit;
    }

    $formPayload = buildTeaserPayload($form);

    if ($action === 'investors') {
        $investorPool = buildInvestorPool($formPayload, $apiKey);
        if (empty($investorPool)) {
            echo json_encode(['success' => false, 'message' => 'Не найдены подходящие инвесторы.']);
            exit;
        }

        $html = renderInvestorSection($investorPool);
        $snapshot = [
            'html' => $html,
            'generated_at' => date('c'),
        ];
        persistInvestorSnapshot($form, $formPayload, $snapshot);

        echo json_encode([
            'success' => true,
            'html' => $html,
            'generated_at' => $snapshot['generated_at'],
        ]);
        exit;
    }

    $prompt = buildTeaserPrompt($formPayload);
    $rawResponse = callTogetherCompletions($prompt, $apiKey);

    $teaserData = parseTeaserResponse($rawResponse);
    $teaserData = normalizeTeaserData($teaserData, $formPayload);
    $teaserData = ensureOverviewWithAi($teaserData, $formPayload, $apiKey);
    $teaserData = ensureProductsLocalized($teaserData, $formPayload, $apiKey);
    $html = renderTeaserHtml($teaserData, $formPayload['asset_name'] ?? 'Актив', $formPayload);

    $snapshot = persistTeaserSnapshot($form, $formPayload, [
        'html' => $html,
        'generated_at' => date('c'),
        'model' => TOGETHER_MODEL,
    ]);

    echo json_encode([
        'success' => true,
        'html' => $html,
        'generated_at' => $snapshot['generated_at'] ?? null,
    ]);
} catch (Exception $e) {
    error_log('Teaser generation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Не удалось создать тизер. Попробуйте позже.']);
}

/**
 * Собирает данные анкеты для передачи в AI.
 * В приоритете data_json — он содержит самую свежую версию опросника.
 * Если JSON отсутствует, достраиваем payload из отдельных колонок таблицы.
 * Также добавляем служебные поля (_meta) и моментальный снимок сайта.
 */
function buildTeaserPayload(array $form): array
{
    $data = [];

    if (!empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (empty($data)) {
        $mapping = [
            'asset_name' => 'asset_name',
            'deal_share_range' => 'deal_subject',
            'deal_goal' => 'deal_purpose',
            'asset_disclosure' => 'asset_disclosure',
            'company_description' => 'company_description',
            'presence_regions' => 'presence_regions',
            'products_services' => 'products_services',
            'company_brands' => 'company_brands',
            'own_production' => 'own_production',
            'production_sites_count' => 'production_sites_count',
            'production_sites_region' => 'production_sites_region',
            'production_area' => 'production_area',
            'production_capacity' => 'production_capacity',
            'production_load' => 'production_load',
            'contract_production_usage' => 'contract_production_usage',
            'contract_production_region' => 'contract_production_region',
            'contract_production_logistics' => 'contract_production_logistics',
            'offline_sales_presence' => 'offline_sales_presence',
            'offline_sales_points' => 'offline_sales_points',
            'offline_sales_regions' => 'offline_sales_regions',
            'offline_sales_area' => 'offline_sales_area',
            'offline_sales_third_party' => 'offline_sales_third_party',
            'offline_sales_distributors' => 'offline_sales_distributors',
            'online_sales_presence' => 'online_sales_presence',
            'online_sales_share' => 'online_sales_share',
            'online_sales_channels' => 'online_sales_channels',
            'main_clients' => 'main_clients',
            'sales_share' => 'sales_share',
            'personnel_count' => 'personnel_count',
            'company_website' => 'company_website',
            'additional_info' => 'additional_info',
            'financial_results_vat' => 'financial_results_vat',
            'financial_source' => 'financial_source',
        ];

        foreach ($mapping as $key => $column) {
            $data[$key] = $form[$column] ?? '';
        }

        $data['production'] = !empty($form['production_volumes']) ? (json_decode($form['production_volumes'], true) ?: []) : [];
        $data['financial']  = !empty($form['financial_results']) ? (json_decode($form['financial_results'], true) ?: []) : [];
        $data['balance']    = !empty($form['balance_indicators']) ? (json_decode($form['balance_indicators'], true) ?: []) : [];
    }

    $data['_meta'] = [
        'form_id' => $form['id'],
        'status' => $form['status'],
        'submitted_at' => $form['submitted_at'],
    ];

    if (!empty($data['company_website'])) {
        $snapshot = fetchCompanyWebsiteSnapshot($data['company_website']);
        if ($snapshot) {
            $data['company_website_snapshot'] = $snapshot;
        }
    }

    return $data;
}

/**
 * Формирует промпт для AI.
 * Структура ответа описана явно и строго — модель должна вернуть JSON
 * с заранее известными ключами, чтобы дальнейший парсинг был детерминированным.
 * Дополнительно подмешиваются выдержки с корпоративного сайта, если они есть.
 */
function buildTeaserPrompt(array $payload): string
{
    $assetName = $payload['asset_name'] ?? 'Неизвестный актив';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $siteNote = '';
    if (!empty($payload['company_website']) && !empty($payload['company_website_snapshot'])) {
        $siteNote = "\nДополнительные сведения с сайта {$payload['company_website']}:\n" .
            $payload['company_website_snapshot'] .
            "\n";
    }

    return <<<PROMPT
Ты — инвестиционный банкир. Подготовь лаконичный тизер компании "{$assetName}" для потенциальных инвесторов.

Важно:
- Отвечай строго на русском языке.
- Используй данные анкеты (если поле пустое, пиши «уточняется») и при необходимости дополни их публичными отраслевыми фактами (без выдумывания конкретных чисел, если они неупомянуты).
- Соблюдай структуру данных. Все текстовые поля — короткие абзацы, списки — массивы строк.

Структура ответа — строго валидный JSON:
{
  "overview": {
      "title": "...",
      "summary": "...",
      "key_metrics": ["...", "..."]
  },
  "company_profile": {
      "industry": "...",
      "established": "...",
      "headcount": "...",
      "locations": "...",
      "operations": "...",
      "unique_assets": "..."
  },
  "products": {
      "portfolio": "...",
      "differentiators": "...",
      "key_clients": "...",
      "sales_channels": "..."
  },
  "market": {
      "trend": "...",
      "size": "...",
      "growth": "...",
      "sources": ["...", "..."]
  },
  "financials": {
      "revenue": "...",
      "ebitda": "...",
      "margins": "...",
      "capex": "...",
      "notes": "..."
  },
  "highlights": {
      "bullets": ["...", "...", "..."]
  },
  "deal_terms": {
      "structure": "...",
      "share_for_sale": "...",
      "valuation_expectation": "...",
      "use_of_proceeds": "..."
  },
  "next_steps": {
      "cta": "...",
      "contact": "...",
      "disclaimer": "..."
  }
}

Данные анкеты:
{$json}
{$siteNote}
PROMPT;
}

/**
 * Вызывает together.ai Completion API.
 * Оборачивает cURL-запрос, проверяет код ответа и пробует разные форматы
 * JSON, которые может вернуть Together (старый output.choices и новый choices).
 */
function callTogetherCompletions(string $prompt, string $apiKey): string
{
    $body = json_encode([
        'model' => TOGETHER_MODEL,
        'prompt' => $prompt,
        'max_tokens' => 600,
        'temperature' => 0.2,
        'top_p' => 0.9,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.together.ai/v1/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('Сеть недоступна: ' . curl_error($ch));
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400) {
        throw new RuntimeException('Ответ API: ' . $response);
    }

    $decoded = json_decode($response, true);
    if (isset($decoded['output']['choices'][0]['text'])) {
        return $decoded['output']['choices'][0]['text'];
    }

    if (isset($decoded['choices'][0]['text'])) {
        return $decoded['choices'][0]['text'];
    }

    return $response;
}

/**
 * Парсит ответ AI в массив.
 * Если парсер не смог прочитать JSON, возвращаем минимальный каркас overview
 * с текстом, чтобы интерфейс всегда показал хотя бы что-то.
 */
function parseTeaserResponse(string $text): array
{
    $clean = trim($text);
    // Удаляем кодовые блоки ```json ... ```
    if (str_starts_with($clean, '```')) {
        $clean = preg_replace('/^```[a-z]*\s*/i', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
    }

    $clean = trim($clean);

    $json = json_decode($clean, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
        return $json;
    }

    return [
        'overview' => [
            'title' => 'Резюме',
            'summary' => constrainToRussianNarrative(sanitizeAiArtifacts($clean)),
            'key_metrics' => [],
        ],
    ];
}

/**
 * Рендерит HTML для тизера.
 * На этом этапе уже всё нормализовано — остаётся собрать карточки, графики
 * и вспомогательные блоки (кнопки, подсказки, подписи и т.п.).
 */
function renderTeaserHtml(array $data, string $assetName, array $payload = []): string
{
    $blocks = [];

    if (!empty($data['overview'])) {
        $overview = $data['overview'];
        $blocks[] = renderCard('Обзор возможности', [
            'subtitle' => htmlspecialchars($overview['title'] ?? $assetName, ENT_QUOTES, 'UTF-8'),
            'text' => nl2br(htmlspecialchars($overview['summary'] ?? '', ENT_QUOTES, 'UTF-8')),
            'list' => $overview['key_metrics'] ?? [],
        ], 'overview');
    }

    if (!empty($data['company_profile'])) {
        $profile = $data['company_profile'];
        $bullets = array_filter([
            formatMetric('Отрасль', $profile['industry'] ?? ''),
            formatMetric('Год основания', $profile['established'] ?? ''),
            formatMetric('Персонал', $profile['headcount'] ?? ''),
            formatMetric('Локации', $profile['locations'] ?? ''),
            formatMetric('Операционная модель', $profile['operations'] ?? ''),
            formatMetric('Уникальные активы', $profile['unique_assets'] ?? ''),
        ]);
        if ($bullets) {
            $blocks[] = renderCard('Профиль компании', [
                'list' => $bullets,
            ], 'profile');
        }
    }

    if (!empty($data['products'])) {
        $products = $data['products'];
        $bullets = array_filter([
            formatMetric('Продукты и услуги', $products['portfolio'] ?? ''),
            formatMetric('Дифференциаторы', $products['differentiators'] ?? ''),
            formatMetric('Ключевые клиенты', $products['key_clients'] ?? ''),
            formatMetric('Каналы продаж', $products['sales_channels'] ?? ''),
        ]);
        if ($bullets) {
            $blocks[] = renderCard('Продукты и клиенты', [
                'list' => $bullets,
            ], 'products');
        }
    }

    if (!empty($data['market'])) {
        $market = $data['market'];
        $marketText = formatMarketBlockText($market);
        $blocks[] = renderCard('Рынок и тенденции', [
            'text' => nl2br(escapeHtml($marketText['text'])),
            'footer' => escapeHtml($marketText['footer']),
        ], 'market');
    }

    if (!empty($data['financials'])) {
        $financials = $data['financials'];
        $bullets = array_filter([
            formatMetric('Выручка', $financials['revenue'] ?? ''),
                formatMetric('Прибыль от продаж', $financials['ebitda'] ?? ''),
            formatMetric('Маржинальность', $financials['margins'] ?? ''),
            formatMetric('CAPEX', $financials['capex'] ?? ''),
            $financials['notes'] ?? '',
        ]);
        $blocks[] = renderCard('Финансовый профиль', [
            'list' => $bullets,
        ], 'financial');

        $timeline = buildTeaserTimeline($payload);
        if ($timeline) {
            $blocks[] = renderTeaserChart($timeline);
        }
    }

    if (!empty($data['highlights']['bullets'])) {
        $blocks[] = renderCard('Инвестиционные преимущества', [
            'list' => $data['highlights']['bullets'],
        ], 'highlights');
    }

    if (!empty($data['deal_terms'])) {
        $deal = $data['deal_terms'];
        $bullets = array_filter([
            formatMetric('Структура сделки', $deal['structure'] ?? ''),
            formatMetric('Предлагаемая доля', $deal['share_for_sale'] ?? ''),
            formatMetric('Ожидания по оценке', $deal['valuation_expectation'] ?? ''),
            formatMetric('Использование средств', $deal['use_of_proceeds'] ?? ''),
        ]);
        if ($bullets) {
            $blocks[] = renderCard('Параметры сделки', [
                'list' => $bullets,
            ], 'deal');
        }
    }

    if (!empty($data['next_steps'])) {
        $next = $data['next_steps'];
        $bullets = array_filter([
            $next['cta'] ?? '',
            $next['contact'] ?? '',
        ]);
        $blocks[] = renderCard('Следующие шаги', [
            'list' => $bullets,
            'footer' => $next['disclaimer'] ?? '',
        ], 'next');
    }

    if (empty($blocks)) {
        $blocks[] = renderCard('Тизер', [
            'text' => 'AI вернул нестандартный ответ. Содержание: ' . escapeHtml(json_encode($data, JSON_UNESCAPED_UNICODE)),
        ], 'fallback');
    }

    return '<div class="teaser-grid">' . implode('', $blocks) . '</div>';
}

function renderCard(string $title, array $payload, string $variant = ''): string
{
    $variantAttr = $variant !== '' ? ' data-variant="' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . '"' : '';
    $html = '<div class="teaser-card"' . $variantAttr . '>';
    $icon = getTeaserIcon($title);
    $html .= '<div class="teaser-card__icon">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';

    if (!empty($payload['subtitle'])) {
        $html .= '<p class="teaser-card__subtitle">' . $payload['subtitle'] . '</p>';
    }

    if (!empty($payload['text'])) {
        $html .= '<p>' . $payload['text'] . '</p>';
    }

    if (!empty($payload['list']) && is_array($payload['list'])) {
        $html .= '<ul>';
        foreach ($payload['list'] as $item) {
            if (empty($item)) {
                continue;
            }
            $html .= '<li>' . escapeHtml($item) . '</li>';
        }
        $html .= '</ul>';
    }

    if (!empty($payload['footer'])) {
        $html .= '<p class="teaser-card__footer">' . escapeHtml($payload['footer']) . '</p>';
    }

    $html .= '</div>';
    return $html;
}

function escapeHtml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatMetric(string $label, string $value): string
{
    if (trim($value) === '') {
        return '';
    }
    return "{$label}: {$value}";
}

function getTeaserIcon(string $title): string
{
    $map = [
        'Обзор возможности' => '📊',
        'Профиль компании' => '🏢',
        'Продукты и клиенты' => '🧩',
        'Рынок и тенденции' => '🌍',
        'Финансовый профиль' => '💰',
        'Инвестиционные преимущества' => '✨',
        'Параметры сделки' => '🤝',
        'Следующие шаги' => '➡️',
    ];
    return $map[$title] ?? '📌';
}

/**
 * Пытается получить краткое содержание с сайта компании.
 */
function fetchCompanyWebsiteSnapshot(string $url): ?string
{
    $normalized = trim($url);
    if ($normalized === '') {
        return null;
    }
    if (!preg_match('~^https?://~i', $normalized)) {
        $normalized = 'https://' . $normalized;
    }

    $ch = curl_init($normalized);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'SmartBizSellBot/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if ($html === false || $html === null) {
        return null;
    }

    $text = strip_tags($html);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    return mb_substr($text, 0, 1500) . (mb_strlen($text) > 1500 ? '…' : '');
}

function extractNumericValue(string $raw): ?float
{
    $normalized = str_replace([' ', ' '], '', $raw);
    $normalized = str_replace(',', '.', $normalized);
    if (!preg_match('/-?\d+(\.\d+)?/', $normalized, $matches)) {
        return null;
    }
    $number = (float)$matches[0];
    $lower = mb_strtolower($raw);
    if (str_contains($lower, 'млрд')) {
        $number *= 1000;
    } elseif (str_contains($lower, 'тыс')) {
        $number /= 1000;
    }
    return $number;
}

function buildTeaserTimeline(array $payload): ?array
{
    if (empty($payload['financial']) || !is_array($payload['financial'])) {
        return null;
    }
    $periods = [
        '2022_fact' => '2022',
        '2023_fact' => '2023',
        '2024_fact' => '2024',
        '2025_budget' => '2025E',
        '2026_budget' => '2026E',
    ];
    $metrics = [
        'revenue' => ['title' => 'Выручка', 'unit' => 'млн ₽'],
        'sales_profit' => ['title' => 'Прибыль от продаж', 'unit' => 'млн ₽'],
    ];
    $series = [];

    foreach ($metrics as $key => $meta) {
        if (empty($payload['financial'][$key]) || !is_array($payload['financial'][$key])) {
            continue;
        }
        $row = $payload['financial'][$key];
        $points = [];
        foreach ($periods as $column => $label) {
            if (empty($row[$column])) {
                continue;
            }
            $value = extractNumericValue((string)$row[$column]);
            if ($value === null) {
                continue;
            }
            $points[] = [
                'label' => $label,
                'value' => $value,
            ];
        }
        if (count($points) >= 2) {
            $series[] = [
                'title' => $meta['title'],
                'unit' => $meta['unit'],
                'points' => $points,
            ];
        }
    }

    return $series ?: null;
}

function seriesHasLabel(array $series, string $label): bool
{
    foreach ($series as $metric) {
        foreach ($metric['points'] as $point) {
            if ($point['label'] === $label) {
                return true;
            }
        }
    }
    return false;
}

function valueForLabel(array $points, string $label): ?float
{
    foreach ($points as $point) {
        if ($point['label'] === $label) {
            return $point['value'];
        }
    }
    return null;
}

function renderTeaserChart(array $series): string
{
    $periodOrder = ['2022', '2023', '2024', '2025E', '2026E', '2027E'];
    $labels = [];
    foreach ($periodOrder as $label) {
        if (seriesHasLabel($series, $label)) {
            $labels[] = $label;
        }
    }
    foreach ($series as $metric) {
        foreach ($metric['points'] as $point) {
            if (!in_array($point['label'], $labels, true)) {
                $labels[] = $point['label'];
            }
        }
    }
    if (count($labels) < 2) {
        return '';
    }

    $apexPayload = [
        'categories' => $labels,
        'unit' => 'млн ₽',
        'series' => [],
        'colors' => ['#6366F1', '#0EA5E9', '#F97316', '#10B981'],
    ];

    foreach ($series as $index => $metric) {
        $dataPoints = [];
        foreach ($labels as $label) {
            $value = valueForLabel($metric['points'], $label);
            $dataPoints[] = $value !== null ? round($value, 2) : null;
        }
        $apexPayload['series'][] = [
            'name' => $metric['title'] . (isset($metric['unit']) ? ' (' . $metric['unit'] . ')' : ''),
            'data' => $dataPoints,
        ];
    }

    if (empty($apexPayload['series'])) {
        return '';
    }

    $chartJson = htmlspecialchars(json_encode($apexPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

    $html = '<div class="teaser-card teaser-chart-card" data-variant="chart">';
    $html .= '<div class="teaser-card__icon">📈</div>';
    $html .= '<h3>Динамика финансов</h3>';
    $html .= '<div class="teaser-chart" data-chart="' . $chartJson . '"></div>';
    $html .= '<p class="teaser-chart__note">Показатели указаны в млн ₽. Источник: анкета продавца (факт + бюджет).</p>';
    $html .= '</div>';
    return $html;
}

function normalizeTeaserData(array $data, array $payload): array
{
    $placeholder = 'Дополнительные сведения доступны по запросу.';
    $assetName = $payload['asset_name'] ?? 'Актив';
    $companyDesc = trim((string)($payload['company_description'] ?? ''));

    $data['overview'] = [
        'title' => $data['overview']['title'] ?? $assetName,
        'summary' => buildHeroSummary(
            $data['overview']['summary'] ?? null,
            $payload,
            $placeholder
        ),
        'key_metrics' => normalizeArray($data['overview']['key_metrics'] ?? [
            formatMetric('Персонал', $payload['personnel_count'] ?? 'уточняется'),
            formatMetric('Доля продаж онлайн', $payload['online_sales_share'] ?? 'уточняется'),
        ]),
    ];

    $data['company_profile'] = [
        'industry' => $data['company_profile']['industry'] ?? ($payload['products_services'] ?? $placeholder),
        'established' => $data['company_profile']['established'] ?? ($payload['production_area'] ? 'Бизнес с развитой инфраструктурой' : $placeholder),
        'headcount' => $data['company_profile']['headcount'] ?? ($payload['personnel_count'] ?? $placeholder),
        'locations' => $data['company_profile']['locations'] ?? ($payload['presence_regions'] ?? $placeholder),
        'operations' => $data['company_profile']['operations'] ?? ($payload['own_production'] ?? $placeholder),
        'unique_assets' => $data['company_profile']['unique_assets'] ?? ($payload['company_brands'] ?? $placeholder),
    ];

    $data['products'] = [
        'portfolio' => $data['products']['portfolio'] ?? ($payload['products_services'] ?? $placeholder),
        'differentiators' => $data['products']['differentiators'] ?? ($payload['additional_info'] ?? $placeholder),
        'key_clients' => $data['products']['key_clients'] ?? ($payload['main_clients'] ?? $placeholder),
        'sales_channels' => $data['products']['sales_channels'] ?? buildSalesChannelsText($payload),
    ];

    $marketInsight = enrichMarketInsight($payload, $data['market'] ?? []);
    $data['market'] = [
        'trend' => $marketInsight['trend'],
        'size' => $marketInsight['size'],
        'growth' => $marketInsight['growth'],
        'sources' => normalizeArray($marketInsight['sources']),
    ];

    $data['financials'] = [
        'revenue' => $data['financials']['revenue'] ?? ($payload['financial']['revenue']['2024_fact'] ?? $placeholder),
        'ebitda' => $data['financials']['ebitda'] ?? ($payload['financial']['sales_profit']['2024_fact'] ?? $placeholder),
        'margins' => $data['financials']['margins'] ?? 'Маржинальность уточняется.',
        'capex' => $data['financials']['capex'] ?? ($payload['financial']['fixed_assets_acquisition']['2024_fact'] ?? 'Низкая CAPEX-нагрузка.'),
        'notes' => $data['financials']['notes'] ?? 'Финансовые показатели подтверждены данными анкеты.',
    ];

    $data['highlights']['bullets'] = normalizeArray($data['highlights']['bullets'] ?? buildHighlightBullets($payload, $placeholder));

    $data['deal_terms'] = [
        'structure' => $data['deal_terms']['structure'] ?? (($payload['deal_goal'] ?? '') ?: 'Гибкая структура сделки.'),
        'share_for_sale' => $data['deal_terms']['share_for_sale'] ?? ($payload['deal_share_range'] ?? 'Доля обсуждается.'),
        'valuation_expectation' => $data['deal_terms']['valuation_expectation'] ?? 'Ожидаемая оценка обсуждается с инвестором.',
        'use_of_proceeds' => $data['deal_terms']['use_of_proceeds'] ?? 'Средства будут направлены на масштабирование бизнеса.',
    ];

    $data['next_steps'] = [
        'cta' => $data['next_steps']['cta'] ?? 'Готовы перейти к сделке после NDA и доступа к VDR.',
        'contact' => $data['next_steps']['contact'] ?? 'Команда SmartBizSell.',
        'disclaimer' => $data['next_steps']['disclaimer'] ?? 'Данные предоставлены продавцом и требуют подтверждения на due diligence.',
    ];

    return $data;
}

/**
 * Пост-обрабатывает блок overview: если AI вернул сухой/ломанный текст,
 * ещё раз обращаемся к модели, но уже с жёстким промптом и опорой на факты.
 */
function ensureOverviewWithAi(array $data, array $payload, string $apiKey): array
{
    if (empty($data['overview'])) {
        $data['overview'] = [];
    }
    if (!shouldEnhanceOverview($data['overview'])) {
        return $data;
    }

    try {
        $prompt = buildOverviewRefinementPrompt($data['overview'], $payload);
        $aiText = trim(callTogetherCompletions($prompt, $apiKey));
        $aiText = constrainToRussianNarrative(sanitizeAiArtifacts(strip_tags($aiText)));
        if ($aiText !== '') {
            $sentences = splitIntoSentences($aiText);
            $data['overview']['summary'] = buildParagraphsFromSentences(
                $sentences,
                buildOverviewFallbackSentences($payload),
                4,
                2
            );
        }
    } catch (Throwable $e) {
        error_log('Overview AI refinement failed: ' . $e->getMessage());
    }

    if (empty($data['overview']['title'])) {
        $data['overview']['title'] = $payload['asset_name'] ?? 'Инвестиционная возможность';
    }

    return $data;
}

/**
 * Пытается привести блок "Продукты и клиенты" к аккуратному русскому описанию:
 * - разворачивает JSON/массивы из AI в строки;
 * - выявляет строки без кириллицы или с «сырой» структурой и
 *   отправляет их на дополнительную локализацию в Together.ai.
 */
function ensureProductsLocalized(array $data, array $payload, string $apiKey): array
{
    if (empty($data['products']) || !is_array($data['products'])) {
        return $data;
    }

    $fields = ['portfolio', 'differentiators', 'key_clients', 'sales_channels'];
    $toTranslate = [];

    foreach ($fields as $field) {
        $current = $data['products'][$field] ?? '';
        $normalized = normalizeProductText($current);
        if ($normalized !== $current) {
            $data['products'][$field] = $normalized;
        }
        if ($normalized === '') {
            continue;
        }
        if (textNeedsLocalization($normalized)) {
            $toTranslate[$field] = $normalized;
        }
    }

    if (empty($toTranslate)) {
        return $data;
    }

    try {
        $prompt = buildProductsLocalizationPrompt($toTranslate, $payload);
        $raw = callTogetherCompletions($prompt, $apiKey);
        $translations = parseProductsLocalizationResponse($raw);
        foreach ($translations as $field => $value) {
            $clean = trim(constrainToRussianNarrative(sanitizeAiArtifacts((string)$value)));
            if ($clean === '') {
                continue;
            }
            $data['products'][$field] = rtrim($clean, '.; ');
        }
    } catch (Throwable $e) {
        error_log('Products localization failed: ' . $e->getMessage());
    }

    return $data;
}

/**
 * Готовит минималистичный промпт для переформулировки описаний
 * продуктов и клиентов: модель должна вернуть JSON той же структуры.
 */
function buildProductsLocalizationPrompt(array $entries, array $payload): string
{
    $asset = $payload['asset_name'] ?? 'актив';
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return <<<PROMPT
Ты маркетолог инвестиционного банка. Переведи и переформулируй на красивом русском языке описания блока "Продукты и клиенты" для компании "{$asset}".
Важно:
- Ответ верни строго в JSON с теми же ключами (portfolio, differentiators, key_clients, sales_channels).
- Используй деловой стиль, максимум два предложения в каждом значении.
- Не добавляй новых фактов и не оставляй английские слова, кроме обязательных названий брендов.

Данные, которые нужно локализовать:
{$json}
PROMPT;
}

/**
 * Безопасно парсит ответ модели и извлекает только ожидаемые ключи.
 */
function parseProductsLocalizationResponse(string $response): array
{
    $clean = trim($response);
    if (str_starts_with($clean, '```')) {
        $clean = preg_replace('/^```[a-z]*\s*/i', '', $clean);
        $clean = preg_replace('/```$/', '', $clean);
    }
    $decoded = json_decode(trim($clean), true);
    if (is_array($decoded)) {
        return array_intersect_key($decoded, array_flip(['portfolio', 'differentiators', 'key_clients', 'sales_channels']));
    }
    return [];
}

function shouldEnhanceOverview(array $overview): bool
{
    $summary = trim((string)($overview['summary'] ?? ''));
    if ($summary === '') {
        return true;
    }
    if (stripos($summary, 'Информация уточняется') !== false) {
        return true;
    }
    if (stripos($summary, 'Ключевые преимущества') !== false) {
        return true;
    }
    if (substr_count($summary, '.') < 3) {
        return true;
    }
    if (mb_strlen($summary) < 220) {
        return true;
    }
    return false;
}

function buildOverviewRefinementPrompt(array $overview, array $payload): string
{
    $facts = [
        'Название' => $payload['asset_name'] ?? '',
        'Отрасль' => $payload['products_services'] ?? '',
        'Регионы присутствия' => $payload['presence_regions'] ?? '',
        'Бренды' => $payload['company_brands'] ?? '',
        'Клиенты' => $payload['main_clients'] ?? '',
        'Персонал' => $payload['personnel_count'] ?? '',
        'Цель сделки' => $payload['deal_goal'] ?? '',
        'Доля к продаже' => $payload['deal_share_range'] ?? '',
        'Сильные стороны' => implode(', ', buildAdvantageSentences($payload)),
        'Финансовые цели' => buildRevenueGrowthMessage($payload) ?? '',
        'Загрузка мощностей' => $payload['production_load'] ?? '',
        'Источник сайта' => buildWebsiteInsightSentence($payload) ?? '',
    ];

    $facts = array_filter($facts, fn($value) => trim((string)$value) !== '');
    $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $existingSummary = trim((string)($overview['summary'] ?? ''));

    return <<<PROMPT
Ты инвестиционный банкир. На основе фактов ниже напиши компактный блок "Обзор возможности" строго на русском языке.
- Стиль: не более четырёх предложений, деловой и живой тон без канцелярита.
- Сформируй ровно четыре абзаца, в каждом по одному предложению. Делай переходы логичными: 1) кто компания и что делает, 2) география и клиенты, 3) конкурентные преимущества, 4) планы использования инвестиций и ожидаемый рост.
- Используй только приведённые факты, не придумывай цифры или названия.
- Внутри предложений соединяй части запятыми, избегай сухих списков.

Исходная версия: "{$existingSummary}"

Факты:
{$factsJson}
PROMPT;
}

function normalizeArray($value): array
{
    if (is_array($value)) {
        $filtered = array_values(array_filter(array_map('trim', $value), fn($item) => $item !== ''));
        if (!empty($filtered)) {
            return $filtered;
        }
    } elseif (is_string($value) && trim($value) !== '') {
        return [trim($value)];
    }
    return ['Дополнительные сведения доступны по запросу.'];
}

function buildSalesChannelsText(array $payload): string
{
    $channels = [];

    // Offline presence may come as «нет», поэтому нормализуем значение заранее.
    $offline = normalizeChannelValue($payload['offline_sales_presence'] ?? '');
    if ($offline !== '') {
        $channels[] = 'Оффлайн: ' . $offline;
    }

    // Online channels бывают перечислены списком — не скрываем детали.
    $online = normalizeChannelValue($payload['online_sales_channels'] ?? '');
    if ($online !== '') {
        $channels[] = 'Онлайн: ' . $online;
    }

    // Contract manufacturing часто содержит английские ответы (yes/no).
    $contract = normalizeChannelValue($payload['contract_production_usage'] ?? '');
    if ($contract !== '') {
        $channels[] = 'Контрактное производство: ' . $contract;
    }

    if (empty($channels)) {
        return 'Каналы продаж уточняются.';
    }

    return implode('; ', $channels);
}

/**
 * Приводит значения каналов к читабельной форме и отбрасывает ответы
 * вроде «no», «нет», «n/a».
 */
function normalizeChannelValue($value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $plain = mb_strtolower($text, 'UTF-8');
    $negativeMarkers = ['нет', 'no', 'none', 'n/a', 'не указано', 'не используется', '0', '-', '—'];
    if (in_array($plain, $negativeMarkers, true)) {
        return '';
    }

    if (preg_match('/^(no|нет)(\b|[^a-zA-ZА-Яа-я0-9])/iu', $text)) {
        return '';
    }

    return $text;
}

/**
 * Разворачивает вложенные массивы/JSON со списками продуктов
 * в единую строку-маркёр.
 */
function normalizeProductText($value): string
{
    if (is_array($value)) {
        return flattenProductArray($value);
    }

    $string = trim((string)$value);
    if ($string === '') {
        return '';
    }

    $first = substr($string, 0, 1);
    $last = substr($string, -1);
    if (($first === '[' && $last === ']') || ($first === '{' && $last === '}')) {
        $decoded = json_decode($string, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return flattenProductArray($decoded);
        }
    }

    return preg_replace('/[\[\]{}]/', '', $string);
}

/**
 * Ходим по массиву произвольной глубины и собираем уникальные строки.
 */
function flattenProductArray($data): string
{
    if (!is_array($data)) {
        return trim((string)$data);
    }
    $result = [];
    $iterator = function ($item) use (&$result, &$iterator) {
        if (is_array($item)) {
            array_walk($item, $iterator);
            return;
        }
        $text = trim((string)$item);
        if ($text !== '') {
            $result[] = $text;
        }
    };
    array_walk($data, $iterator);
    $result = array_unique($result);
    return implode(', ', $result);
}

/**
 * Если в строке нет кириллицы или остались служебные скобки,
 * считаем, что её нужно «одомашнить».
 */
function textNeedsLocalization(string $text): bool
{
    if ($text === '') {
        return false;
    }
    if (preg_match('/[{}[\]]/', $text)) {
        return true;
    }
    if (!containsCyrillic($text)) {
        return true;
    }
    return false;
}

/**
 * Проверяем наличие кириллических символов в строке.
 */
function containsCyrillic(string $text): bool
{
    return (bool)preg_match('/\p{Cyrillic}/u', $text);
}

function buildHighlightBullets(array $payload, string $placeholder): array
{
    $bullets = array_filter([
        !empty($payload['company_brands']) ? 'Сильные бренды: ' . $payload['company_brands'] : null,
        !empty($payload['own_production']) ? 'Собственная производственная база.' : null,
        !empty($payload['presence_regions']) ? 'Широкая география: ' . $payload['presence_regions'] : null,
        !empty($payload['main_clients']) ? 'Ключевые клиенты: ' . $payload['main_clients'] : null,
    ]);
    if (empty($bullets)) {
        $bullets[] = $placeholder;
    }
    return $bullets;
}

/**
 * Расширяет блок «Рынок и тенденции» данными из открытых источников.
 * Приоритет: сначала AI-ответ, затем фактические данные (если удалось собрать).
 */
function enrichMarketInsight(array $payload, array $current): array
{
    $defaults = [
        'trend' => $current['trend'] ?? 'Рынок демонстрирует устойчивый интерес инвесторов.',
        'size' => $current['size'] ?? 'Объём рынка оценивается как значительный по отраслевым данным.',
        'growth' => $current['growth'] ?? 'Ожидается стабильный рост 5–10% в год.',
        'sources' => $current['sources'] ?? ['Отраслевые обзоры SmartBizSell'],
    ];

    $query = deriveMarketQuery($payload);
    if (!$query) {
        return $defaults;
    }

    $facts = fetchExternalMarketFacts($query);
    if (!$facts) {
        return $defaults;
    }

    return [
        'trend' => $facts['trend'] ?? $defaults['trend'],
        'size' => $facts['size'] ?? $defaults['size'],
        'growth' => $facts['growth'] ?? $defaults['growth'],
        'sources' => $facts['sources'] ?? $defaults['sources'],
    ];
}

function deriveMarketQuery(array $payload): ?string
{
    $candidates = [
        $payload['products_services'] ?? '',
        $payload['company_description'] ?? '',
        $payload['industry'] ?? '',
    ];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            return mb_substr($candidate, 0, 80);
        }
    }
    return null;
}

function fetchExternalMarketFacts(string $query): ?array
{
    $sourceUrls = [
        'https://r.jina.ai/https://ru.wikipedia.org/wiki/' . rawurlencode($query),
        'https://r.jina.ai/https://en.wikipedia.org/wiki/' . rawurlencode($query),
        'https://r.jina.ai/https://www.investopedia.com/' . rawurlencode($query),
    ];

    $aggregated = [
        'trend' => null,
        'size' => null,
        'growth' => null,
        'sources' => [],
    ];

    foreach ($sourceUrls as $url) {
        $text = fetchExternalText($url);
        if (!$text) {
            continue;
        }
        $label = describeSourceLabel($url);
        $facts = extractMarketFacts($text, $label);
        if (!$facts) {
            continue;
        }
        foreach (['trend', 'size', 'growth'] as $key) {
            if (!$aggregated[$key] && !empty($facts[$key])) {
                $aggregated[$key] = $facts[$key];
            }
        }
        foreach ($facts['sources'] as $sourceLabel) {
            if (!in_array($sourceLabel, $aggregated['sources'], true)) {
                $aggregated['sources'][] = $sourceLabel;
            }
        }
        if ($aggregated['trend'] && $aggregated['size'] && $aggregated['growth']) {
            break;
        }
    }

    if (!$aggregated['trend'] && !$aggregated['size'] && !$aggregated['growth']) {
        return null;
    }

    $topic = normalizeTopicLabel($query);
    $aggregated['trend'] = ensureRussianMarketSentence($aggregated['trend'], $topic, 'trend');
    $aggregated['size'] = ensureRussianMarketSentence($aggregated['size'], $topic, 'size');
    $aggregated['growth'] = ensureRussianMarketSentence($aggregated['growth'], $topic, 'growth');

    if (empty($aggregated['sources'])) {
        $aggregated['sources'][] = 'Публичные данные (аналитика)';
    }

    $aggregated['topic'] = $topic;

    return $aggregated;
}

function fetchExternalText(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_USERAGENT => 'SmartBizSellBot/1.0 (+https://smartbizsell.ru)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status >= 400) {
        return null;
    }

    $text = trim(strip_tags($response));
    if ($text === '') {
        return null;
    }
    $text = preg_replace('/\[([^\]]+)\]\((?:https?:\/\/|\/)[^)]+\)/u', '$1', $text);
    $text = preg_replace('/^\s*[\*\-•]\s+/m', '', $text);
    $text = preg_replace('/Page Not Found.*$/mi', '', $text);
    $text = preg_replace('/Follow Us.+$/mi', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function extractMarketFacts(string $text, string $sourceLabel): ?array
{
    $sentences = preg_split('/(?<=[.!?])\s+/u', $text);
    if (!$sentences) {
        return null;
    }

    $trend = null;
    $size = null;
    $growth = null;

    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }
        if (!$trend && preg_match('/рынок|market|sector/i', $sentence)) {
            $trend = normalizeMarketSentence($sentence);
        }
        if (!$size && preg_match('/\d+[\s ]?(?:млрд|миллиард|billion|млн|million)/iu', $sentence)) {
            $size = normalizeMarketNumericSentence($sentence, 'size');
        }
        if (!$growth && preg_match('/(\d+[\s ]?(?:%|проц))/iu', $sentence)) {
            $growth = normalizeMarketNumericSentence($sentence, 'growth');
        } elseif (
            !$growth &&
            preg_match('/рост|growth|CAGR/i', $sentence)
        ) {
            $growth = normalizeMarketSentence($sentence);
        }
        if ($trend && $size && $growth) {
            break;
        }
    }

    if (!$trend && !$size && !$growth) {
        return null;
    }

    return [
        'trend' => $trend,
        'size' => $size,
        'growth' => $growth,
        'sources' => [$sourceLabel],
    ];
}

function describeSourceLabel(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return 'Публичные данные';
    }
    if (str_contains($host, 'ru.wikipedia')) {
        return 'Публичные данные: Википедия (ru)';
    }
    if (str_contains($host, 'en.wikipedia')) {
        return 'Публичные данные: Википедия (en)';
    }
    if (str_contains($host, 'investopedia')) {
        return 'Публичные данные: Investopedia';
    }
    return 'Публичные данные (' . $host . ')';
}

function normalizeMarketSentence(string $sentence): string
{
    $sentence = trim(preg_replace('/\s+/', ' ', $sentence));
    $sentence = rtrim($sentence, ';');
    if (preg_match('/0\s*%/u', $sentence)) {
        return '';
    }
    return truncateSentence($sentence);
}

function normalizeMarketNumericSentence(string $sentence, string $type): string
{
    $sentence = normalizeMarketSentence($sentence);
    $number = extractNumericSnippet($sentence);
    if (!$number) {
        return $sentence;
    }
    $clean = convertToRussianNumeric($number);
    if ($type === 'size') {
        return "Объём рынка оценивается примерно в {$clean}.";
    }
    return "Темпы роста составляют около {$clean}.";
}

function extractNumericSnippet(string $sentence): ?string
{
    if (preg_match('/\d[\d\s .,]*(?:%|процентов|проц\.|percent|млрд|миллиард|billion|млн|million)/iu', $sentence, $match)) {
        return $match[0];
    }
    return null;
}

function convertToRussianNumeric(string $snippet): string
{
    $snippet = trim($snippet);
    $snippet = str_ireplace(['billion', 'миллиардов', 'миллиарда', 'миллиард'], 'млрд', $snippet);
    $snippet = str_ireplace(['million', 'миллионов', 'миллиона', 'million'], 'млн', $snippet);
    $snippet = str_ireplace(['процентов', 'проц.', 'percent'], '%', $snippet);
    $snippet = preg_replace('/\s+/', ' ', $snippet);
    return $snippet;
}

function ensureRussianMarketSentence(?string $sentence, string $topic, string $type): ?string
{
    if ($sentence === null || trim($sentence) === '') {
        return null;
    }
    if (containsCyrillic($sentence)) {
        return $sentence;
    }
    $topic = $topic !== '' ? mb_strtolower($topic) : 'рынка';
    $number = extractNumericSnippet($sentence);
    $cleanNumber = $number ? convertToRussianNumeric($number) : null;
    if ($type === 'growth' && $cleanNumber !== null) {
        $numericRaw = preg_replace('/[^\d,.\-]/', '', $cleanNumber);
        $numeric = $numericRaw !== '' ? (float)str_replace(',', '.', $numericRaw) : 0.0;
        if (abs($numeric) < 0.01) {
            $cleanNumber = null;
        }
    }

    switch ($type) {
        case 'size':
            if ($cleanNumber) {
                return "Объём рынка {$topic} оценивается примерно в {$cleanNumber}.";
            }
            return "Объём рынка {$topic} оценивается отраслевыми аналитиками как значительный.";
        case 'growth':
            if ($cleanNumber) {
                return "Темпы роста рынка {$topic} составляют около {$cleanNumber}.";
            }
            return "Темпы роста рынка {$topic} остаются стабильными на горизонте 3–5 лет.";
        default:
            return "Рынок {$topic} демонстрирует устойчивый интерес инвесторов и регулярные сделки.";
    }
}

function normalizeTopicLabel(?string $topic): string
{
    $topic = trim((string)$topic);
    if ($topic === '') {
        return 'рынка';
    }
    $topic = preg_replace('/[^а-яА-Яa-zA-Z0-9\s\-]/u', '', $topic);
    $topic = preg_replace('/\s+/', ' ', $topic);
    if (!containsCyrillic($topic)) {
        $topic = mb_strtolower($topic);
    }
    return mb_substr($topic, 0, 40);
}

function truncateSentence(string $sentence, int $maxLength = 220): string
{
    if (mb_strlen($sentence) <= $maxLength) {
        return $sentence;
    }
    $truncated = mb_substr($sentence, 0, $maxLength);
    $lastDot = mb_strrpos($truncated, '.');
    if ($lastDot !== false && $lastDot > $maxLength * 0.4) {
        return mb_substr($truncated, 0, $lastDot + 1);
    }
    return rtrim($truncated, ',;: ') . '…';
}

function formatMarketBlockText(array $market): array
{
    $sentences = [];
    if (!empty($market['trend'])) {
        $sentences[] = $market['trend'];
    }
    if (!empty($market['size'])) {
        $sentences[] = $market['size'];
    }
    if (!empty($market['growth'])) {
        $sentences[] = $market['growth'];
    }
    $sources = normalizeArray($market['sources'] ?? []);
    if (!empty($sources)) {
        $sentences[] = "Источник(и): " . implode(', ', $sources) . '.';
    }
    $sentences = array_map('ensureSentence', $sentences);
    if (count($sentences) > 4) {
        $sentences = array_slice($sentences, 0, 4);
    }
    $topic = $market['topic'] ?? '';
    while (count($sentences) < 4) {
        $sentences[] = $topic
            ? "Рыночные показатели сегмента {$topic} уточняются у команды SmartBizSell."
            : 'Рыночные показатели уточняются у команды SmartBizSell.';
    }
    $formatted = [
        'text' => implode(' ', array_slice($sentences, 0, 3)),
        'footer' => $sentences[3] ?? '',
    ];
    return $formatted;
}

function buildHeroSummary(?string $aiSummary, array $payload, string $fallback): string
{
    $summary = trim(constrainToRussianNarrative(sanitizeAiArtifacts((string)$aiSummary)));
    if ($summary !== '' && !looksLikeStructuredDump($summary)) {
        return enrichSummaryWithAdvantages($summary, $payload);
    }

    $assetName = trim((string)($payload['asset_name'] ?? 'Компания'));
    $industry = trim((string)($payload['products_services'] ?? ''));
    $regions = trim((string)($payload['presence_regions'] ?? ''));
    $brands = trim((string)($payload['company_brands'] ?? ''));
    $clients = trim((string)($payload['main_clients'] ?? ''));
    $personnel = trim((string)($payload['personnel_count'] ?? ''));

    $sentences = [];
    $descriptor = $industry !== '' ? $industry : 'устойчивый бизнес';
    $sentences[] = "{$assetName} — {$descriptor}, готовый к привлечению инвестора для следующего этапа роста.";

    if ($regions !== '') {
        $sentences[] = "Присутствие в регионах {$regions} обеспечивает диверсификацию выручки и доступ к новым каналам.";
    }

    if ($brands !== '') {
        $sentences[] = "Портфель включает бренды {$brands}, что усиливает узнаваемость и лояльность покупателей.";
    }

    if ($clients !== '') {
        $sentences[] = "Ключевые сегменты клиентов: {$clients}.";
    }

    if ($personnel !== '') {
        $sentences[] = "Команда из {$personnel} специалистов готова поддержать масштабирование при входе инвестора.";
    }

    $advantages = buildAdvantageSentences($payload);
    if (!empty($advantages)) {
        $sentences[] = 'Ключевые преимущества: ' . implode(', ', array_slice($advantages, 0, 3)) . '.';
    }
    $sentences = array_merge($sentences, buildAdvantageSummarySentences($payload));
    $prospect = buildInvestorProspectSentence($payload);
    if ($prospect) {
        $sentences[] = $prospect;
    }
    $websiteSentence = buildWebsiteInsightSentence($payload);
    if ($websiteSentence) {
        $sentences[] = $websiteSentence;
    }

    if (count($sentences) < 2) {
        $sentences[] = $fallback;
    }

    return buildParagraphsFromSentences(
        $sentences,
        buildOverviewFallbackSentences($payload),
        4,
        2
    );
}

function enrichSummaryWithAdvantages(string $summary, array $payload): string
{
    $sentences = [$summary];
    $advantages = buildAdvantageSentences($payload);
    if (!empty($advantages)) {
        $sentences[] = 'Ключевые преимущества: ' . implode(', ', array_slice($advantages, 0, 3));
    }
    $sentences = array_merge($sentences, buildAdvantageSummarySentences($payload));
    $prospect = buildInvestorProspectSentence($payload);
    if ($prospect) {
        $sentences[] = $prospect;
    }
    $websiteSentence = buildWebsiteInsightSentence($payload);
    if ($websiteSentence) {
        $sentences[] = $websiteSentence;
    }
    return buildParagraphsFromSentences(
        $sentences,
        buildOverviewFallbackSentences($payload),
        4,
        2
    );
}

function buildAdvantageSentences(array $payload): array
{
    $advantages = [];
    if (isMeaningfulAdvantageValue($payload['company_brands'] ?? '')) {
        $advantages[] = 'узнаваемые бренды ' . trim($payload['company_brands']);
    }
    if (isMeaningfulAdvantageValue($payload['own_production'] ?? '')) {
        $advantages[] = 'собственная производственная база';
    }
    if (isMeaningfulAdvantageValue($payload['presence_regions'] ?? '')) {
        $advantages[] = 'география присутствия ' . trim($payload['presence_regions']);
    }
    if (isMeaningfulAdvantageValue($payload['main_clients'] ?? '')) {
        $advantages[] = 'портфель ключевых клиентов: ' . trim($payload['main_clients']);
    }
    if (isMeaningfulAdvantageValue($payload['online_sales_share'] ?? '')) {
        $advantages[] = 'цифровые каналы продаж с долей ' . trim($payload['online_sales_share']);
    }
    if (hasMeaningfulCapacity($payload['production_capacity'] ?? '')) {
        $advantages[] = 'производственные мощности ' . trim($payload['production_capacity']);
    }

    $trimmed = array_map(fn($text) => rtrim($text, '.; '), $advantages);
    return array_slice($trimmed, 0, 5);
}

/**
 * Развёрнутая версия преимуществ: формируем отдельные предложения с деталями,
 * чтобы можно было равномерно распределить их по абзацам overview.
 */
function buildAdvantageSummarySentences(array $payload): array
{
    $sentences = [];

    $brands = trim((string)($payload['company_brands'] ?? ''));
    if (isMeaningfulAdvantageValue($brands)) {
        $sentences[] = "Портфель брендов {$brands} поддерживает узнаваемость и премиальный образ компании.";
    }

    $production = trim((string)($payload['own_production'] ?? ''));
    if (isMeaningfulAdvantageValue($production)) {
        $sentences[] = 'Собственная производственная база обеспечивает контроль качества и гибкость выпуска.';
    }

    $capacity = trim((string)($payload['production_capacity'] ?? ''));
    if (hasMeaningfulCapacity($capacity)) {
        $sentences[] = "Производственные мощности составляют {$capacity}, что создаёт запас для наращивания объёмов.";
    }

    $sites = trim((string)($payload['production_sites_count'] ?? ''));
    $siteCount = parseIntFromString($sites);
    if ($siteCount !== null && $siteCount > 0) {
        $word = pluralizeRu($siteCount, 'площадку', 'площадки', 'площадок');
        $sentences[] = "Инфраструктура включает {$siteCount} {$word}, распределённых по ключевым регионам.";
    }

    $clients = trim((string)($payload['main_clients'] ?? ''));
    if (isMeaningfulAdvantageValue($clients)) {
        $sentences[] = "Клиентская база включает {$clients}, что снижает зависимость от единичных контрактов.";
    }

    $channels = trim((string)($payload['online_sales_channels'] ?? ''));
    if (isMeaningfulAdvantageValue($channels)) {
        $sentences[] = "Онлайн-каналы продаж развиты через {$channels}, что ускоряет привлечение новых покупателей.";
    }

    $regions = trim((string)($payload['presence_regions'] ?? ''));
    if (isMeaningfulAdvantageValue($regions)) {
        $sentences[] = "Диверсифицированное присутствие в регионах {$regions} позволяет балансировать спрос.";
    }

    return array_values(array_filter(array_map('trim', $sentences), fn($sentence) => $sentence !== ''));
}

function buildInvestorProspectSentence(array $payload): ?string
{
    $segments = [];
    if (!empty($payload['deal_goal'])) {
        $segments[] = 'Инвестиции планируется направить на ' . trim($payload['deal_goal']) . '.';
    }

    $growthMessage = buildRevenueGrowthMessage($payload);
    if ($growthMessage) {
        $segments[] = $growthMessage;
    }

    if (!empty($payload['production_load'])) {
        $segments[] = 'Текущая загрузка мощностей ' . trim($payload['production_load']) . ' оставляет потенциал для масштабирования.';
    }

    if (empty($segments)) {
        return null;
    }

    return implode(' ', array_map('prettifySummary', $segments));
}

function buildRevenueGrowthMessage(array $payload): ?string
{
    $financial = $payload['financial']['revenue'] ?? [];
    $fact = parseNumericValue($financial['2024_fact'] ?? null);
    $budget = parseNumericValue($financial['2025_budget'] ?? null);
    if ($fact === null || $budget === null || $budget <= 0 || $fact <= 0 || $budget <= $fact) {
        return null;
    }
    $growthPercent = (($budget - $fact) / $fact) * 100;
    $factText = number_format($fact, 0, ',', ' ');
    $budgetText = number_format($budget, 0, ',', ' ');
    $growthText = number_format($growthPercent, 1, ',', ' ');
    return "Бюджет 2025 предусматривает рост выручки с {$factText} до {$budgetText} млн ₽ (+{$growthText}%).";
}

function parseNumericValue($value): ?float
{
    if (!is_scalar($value)) {
        return null;
    }
    $string = str_replace(['руб', '₽', 'млн', 'тыс', '%'], '', (string)$value);
    $string = str_replace([' ', ' '], '', $string);
    $string = str_replace(',', '.', $string);
    if ($string === '' || !is_numeric($string)) {
        return null;
    }
    return (float)$string;
}

function buildWebsiteInsightSentence(array $payload): ?string
{
    $snapshot = trim((string)($payload['company_website_snapshot'] ?? ''));
    if ($snapshot === '') {
        return null;
    }
    $clean = preg_replace('/\s+/', ' ', $snapshot);
    $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $clean);
    $excerpt = '';
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }
        $excerpt .= ($excerpt === '' ? '' : ' ') . $sentence;
        if (mb_strlen($excerpt) >= 220 || mb_substr($excerpt, -1) === '.' || mb_substr($excerpt, -1) === '!' || mb_substr($excerpt, -1) === '?') {
            break;
        }
    }
    $excerpt = trim($excerpt);
    if ($excerpt === '') {
        return null;
    }
    if (mb_strlen($excerpt) > 260) {
        $excerpt = rtrim(mb_substr($excerpt, 0, 257), ',; ') . '…';
    }
    $website = trim((string)($payload['company_website'] ?? ''));
    $prefix = $website !== '' ? "Сайт {$website}" : 'Официальный сайт компании';
    return $prefix . ' отмечает: «' . $excerpt . '»';
}

/**
 * Генерирует fallback-предложения для overview на случай,
 * если основной ответ модели будет слишком коротким.
 */
function buildOverviewFallbackSentences(array $payload): array
{
    $assetName = trim((string)($payload['asset_name'] ?? 'Компания'));
    $regions = trim((string)($payload['presence_regions'] ?? ''));
    $clients = trim((string)($payload['main_clients'] ?? ''));
    $dealGoal = trim((string)($payload['deal_goal'] ?? ''));
    $growth = buildRevenueGrowthMessage($payload);
    $advantages = buildAdvantageSentences($payload);
    $website = buildWebsiteInsightSentence($payload);

    $sentences = [];
    $sentences[] = $assetName !== '' ? "{$assetName} готова к диалогу с инвестором на платформе SmartBizSell." : 'Команда актива готова к диалогу с инвестором на платформе SmartBizSell.';
    if ($regions !== '') {
        $sentences[] = "География деятельности охватывает {$regions}, что поддерживает диверсификацию спроса.";
    }
    if (!empty($advantages)) {
        $sentences[] = 'Ключевые преимущества: ' . implode(', ', array_slice($advantages, 0, 3)) . '.';
    } elseif ($clients !== '') {
        $sentences[] = "Компания работает с клиентами сегмента {$clients} и удерживает их за счёт сервиса.";
    }
    $sentences = array_merge($sentences, buildAdvantageSummarySentences($payload));
    if ($dealGoal !== '') {
        $sentences[] = "Инвестиционный запрос связан с задачей {$dealGoal}.";
    } elseif ($growth) {
        $sentences[] = $growth;
    }
    if ($website) {
        $sentences[] = $website;
    }
    $sentences[] = 'Команда SmartBizSell сопровождает подготовку VDR и процесс due diligence.';

    return array_values(array_filter(array_map('trim', $sentences), fn($sentence) => $sentence !== ''));
}

function isMeaningfulAdvantageValue($value): bool
{
    if (!is_scalar($value)) {
        return false;
    }
    $normalized = mb_strtolower(trim((string)$value));
    if ($normalized === '') {
        return false;
    }
    $banList = ['нет', 'no', 'none', 'n/a', 'офис', 'office', '0', '-', '—'];
    foreach ($banList as $ban) {
        if ($normalized === $ban) {
            return false;
        }
    }
    return true;
}

function hasMeaningfulCapacity($value): bool
{
    if (!isMeaningfulAdvantageValue($value)) {
        return false;
    }
    $string = trim((string)$value);
    if ($string === '') {
        return false;
    }
    if (str_contains(mb_strtolower($string), 'офис')) {
        return false;
    }
    return (bool)preg_match('/\d/', $string);
}

function parseIntFromString($value): ?int
{
    if (!is_scalar($value)) {
        return null;
    }
    $digits = preg_replace('/[^\d]/', '', (string)$value);
    if ($digits === '') {
        return null;
    }
    return (int)$digits;
}

function pluralizeRu(int $number, string $one, string $two, string $many): string
{
    $mod10 = $number % 10;
    $mod100 = $number % 100;
    if ($mod10 === 1 && $mod100 !== 11) {
        return $one;
    }
    if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
        return $two;
    }
    return $many;
}

function ensureSentence(string $text): string
{
    $clean = prettifySummary($text);
    if ($clean === '') {
        return $clean;
    }
    $clean = mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
    $lastChar = mb_substr($clean, -1);
    if (!in_array($lastChar, ['.', '!', '?'], true)) {
        $clean .= '.';
    }
    $clean = preg_replace_callback('/([.!?]\s*)(\p{Ll})/u', static fn($m) => $m[1] . mb_strtoupper($m[2]), $clean);
    return $clean;
}

/**
 * Собирает итоговый текст overview, гарантируя нужное число абзацев
 * и аккуратный вид предложений. Каждый абзац может включать несколько
 * предложений (например, по 3, как требует текущий дизайн).
 */
function buildParagraphsFromSentences(
    array $sentences,
    array $fallbackSentences = [],
    int $requiredParagraphs = 4,
    int $sentencesPerParagraph = 2
): string {
    $totalNeeded = $requiredParagraphs * $sentencesPerParagraph;
    $normalized = normalizeSentenceList($sentences, $totalNeeded);
    $fallbackNormalized = normalizeSentenceList($fallbackSentences, $totalNeeded);

    foreach ($fallbackNormalized as $sentence) {
        if (count($normalized) >= $totalNeeded) {
            break;
        }
        if (!in_array($sentence, $normalized, true)) {
            $normalized[] = $sentence;
        }
    }

    if (empty($normalized)) {
        return '';
    }

    if (count($normalized) < $totalNeeded) {
        foreach ($fallbackNormalized as $sentence) {
            if (count($normalized) >= $totalNeeded) {
                break;
            }
            if (!in_array($sentence, $normalized, true)) {
                $normalized[] = $sentence;
            }
        }
    }

    while (count($normalized) < $totalNeeded) {
        $normalized[] = end($normalized);
    }

    $paragraphs = [];
    for ($i = 0; $i < $requiredParagraphs; $i++) {
        $start = $i * $sentencesPerParagraph;
        $chunk = array_slice($normalized, $start, $sentencesPerParagraph);
        while (count($chunk) < $sentencesPerParagraph) {
            $chunk[] = end($normalized);
        }
        $paragraphs[] = implode(' ', $chunk);
    }

    return implode("\n\n", $paragraphs);
}

/**
 * Нормализует массив предложений: убирает пустые строки и дубли,
 * приводит предложения к каноничному виду.
 */
function normalizeSentenceList(array $items, int $limit): array
{
    $normalized = [];
    foreach ($items as $item) {
        $sentence = trim((string)$item);
        if ($sentence === '') {
            continue;
        }
        $sentence = ensureSentence($sentence);
        if ($sentence === '') {
            continue;
        }
        if (!in_array($sentence, $normalized, true)) {
            $normalized[] = $sentence;
        }
        if (count($normalized) >= $limit) {
            break;
        }
    }
    return $normalized;
}

/**
 * Делит произвольный текст на предложения по .!? — подходит для
 * дальнейшего форматирования AI-ответов.
 */
function splitIntoSentences(string $text): array
{
    $clean = trim($text);
    if ($clean === '') {
        return [];
    }
    $parts = preg_split('/(?<=[.!?])\s+/u', $clean);
    if ($parts === false) {
        return [$clean];
    }
    return array_values(array_filter(array_map('trim', $parts), fn($part) => $part !== ''));
}

/**
 * Удаляет сервисные фразы (“Human: …”, “Assistant: …”, “PMID …”) и прочие
 * артефакты, которые Together.ai иногда добавляет в ответ.
 */
function sanitizeAiArtifacts(string $text): string
{
    if ($text === '') {
        return '';
    }

    $clean = str_replace(['**', '```'], '', $text);
    $clean = preg_replace('/PMID:[^\n]+/iu', '', $clean);
    $clean = preg_replace('/Note:[^\n]+/iu', '', $clean);

    $conversationMarkers = ['Human:', 'Assistant:', 'AI:', 'User:'];
    foreach ($conversationMarkers as $marker) {
        $pos = stripos($clean, $marker);
        if ($pos !== false) {
            $clean = substr($clean, 0, $pos);
            break;
        }
    }

    $lines = preg_split('/\r\n|\r|\n/', $clean);
    $buffer = [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            continue;
        }

        if (preg_match('/^(Human|Assistant)\b/i', $trim)) {
            $parts = explode(':', $trim, 2);
            $trim = isset($parts[1]) ? trim($parts[1]) : '';
        }

        if ($trim === '' || preg_match('/^final[^:]*:?$/i', $trim)) {
            continue;
        }

        $buffer[] = $trim;
    }

    $result = trim(preg_replace('/\s+/', ' ', implode(' ', $buffer)));
    return $result;
}

/**
 * Удаляет предложения, где нет кириллицы или присутствуют инструкции на английском/китайском.
 */
function constrainToRussianNarrative(string $text): string
{
    if ($text === '') {
        return '';
    }
    $parts = preg_split('/(?<=[.!?])\s+/u', $text);
    if ($parts === false) {
        $parts = [$text];
    }
    $kept = [];
    foreach ($parts as $sentence) {
        $trim = trim($sentence);
        if ($trim === '') {
            continue;
        }
        if (preg_match('/\p{Han}/u', $trim)) {
            continue;
        }
        if (preg_match('/\b(If you|Here is|Please|Let me|Final version|Final,|Corrected version)\b/i', $trim)) {
            continue;
        }
        $cyrCount = preg_match_all('/\p{Cyrillic}/u', $trim);
        $latCount = preg_match_all('/[A-Za-z]/u', $trim);
        if ($cyrCount === 0 && stripos($trim, 'SmartBizSell') === false) {
            continue;
        }
        if ($latCount > 0 && $cyrCount > 0 && ($latCount / max($cyrCount, 1)) > 1.5) {
            continue;
        }
        $kept[] = $trim;
    }
    return trim(implode(' ', $kept));
}

function prettifySummary(string $summary): string
{
    $plain = trim($summary);
    $plain = preg_replace('/\s+/', ' ', $plain);
    $plain = preg_replace('/[•]/u', ', ', $plain);
    $plain = preg_replace('/;+/', ', ', $plain);
    $plain = preg_replace('/\s+,/', ', ', $plain);
    $plain = preg_replace('/,\s+/', ', ', $plain);
    $plain = preg_replace('/\s+,/u', ', ', $plain);
    $plain = preg_replace('/[{}[\]()]/u', '', $plain);
    $plain = preg_replace('/["“”]/u', '"', $plain);
    $plain = preg_replace('/\.+/u', '.', $plain);

    $plain = preg_replace('/\b(\d{1,2})\s?(?:%|проц\.|процентов)\b/iu', '$1%', $plain);
    $plain = preg_replace('/\b(?:руб\.|рублей)\b/iu', '₽', $plain);

    if (!preg_match('/[.!?]$/u', $plain)) {
        $plain .= '.';
    }

    return $plain;
}

function looksLikeStructuredDump(string $text): bool
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return false;
    }
    if (preg_match('/^\{.*\}$/s', $trimmed) || preg_match('/^\[.*\]$/s', $trimmed)) {
        return true;
    }
    if (preg_match('/"[a-zA-Z0-9_]+"\s*:/', $trimmed)) {
        return true;
    }
    if (stripos($trimmed, '"overview"') !== false || stripos($trimmed, '"company_profile"') !== false) {
        return true;
    }
    return false;
}

function persistTeaserSnapshot(array $form, array $payload, array $snapshot): array
{
    $payload['teaser_snapshot'] = $snapshot;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return $snapshot;
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE seller_forms SET data_json = ? WHERE id = ?");
        $stmt->execute([$json, $form['id']]);
    } catch (PDOException $e) {
        error_log('Failed to persist teaser snapshot: ' . $e->getMessage());
    }

    return $snapshot;
}

function persistInvestorSnapshot(array $form, array $payload, array $snapshot): array
{
    $payload['investor_snapshot'] = $snapshot;

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return $snapshot;
    }

    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("UPDATE seller_forms SET data_json = ? WHERE id = ?");
        $stmt->execute([$json, $form['id']]);
    } catch (PDOException $e) {
        error_log('Failed to persist investor snapshot: ' . $e->getMessage());
    }

    return $snapshot;
}
