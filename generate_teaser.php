<?php
require_once 'config.php';

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
    $prompt = buildTeaserPrompt($formPayload);
    $rawResponse = callTogetherCompletions($prompt, $apiKey);

    $teaserData = parseTeaserResponse($rawResponse);
    $teaserData = normalizeTeaserData($teaserData, $formPayload);
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
            'summary' => $clean,
            'key_metrics' => [],
        ],
    ];
}

/**
 * Рендерит HTML для тизера.
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
        $bullets = array_filter([
            $market['trend'] ?? '',
            $market['size'] ?? '',
            $market['growth'] ?? '',
        ]);
        $sources = $market['sources'] ?? [];
        $blocks[] = renderCard('Рынок и тенденции', [
            'text' => implode('<br>', array_map('escapeHtml', $bullets)),
            'footer' => !empty($sources) ? 'Источники: ' . implode(', ', array_map('escapeHtml', $sources)) : '',
        ], 'market');
    }

    if (!empty($data['financials'])) {
        $financials = $data['financials'];
        $bullets = array_filter([
            formatMetric('Выручка', $financials['revenue'] ?? ''),
            formatMetric('EBITDA', $financials['ebitda'] ?? ''),
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
        'sales_profit' => ['title' => 'EBITDA', 'unit' => 'млн ₽'],
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

    $maxValue = 0;
    foreach ($series as $metric) {
        foreach ($metric['points'] as $point) {
            $maxValue = max($maxValue, $point['value']);
        }
    }
    if ($maxValue <= 0) {
        return '';
    }

    $width = 360;
    $height = 220;
    $chartLeft = 52;
    $chartRight = 330;
    $chartTop = 26;
    $chartBottom = 180;
    $chartWidth = $chartRight - $chartLeft;
    $chartHeight = $chartBottom - $chartTop;

    $labelCount = count($labels);
    $xPositions = [];
    foreach ($labels as $index => $label) {
        if ($labelCount === 1) {
            $xPositions[$label] = $chartLeft;
        } else {
            $xPositions[$label] = $chartLeft + ($chartWidth * ($index / ($labelCount - 1)));
        }
    }

    $palette = ['#6366F1', '#0EA5E9', '#F97316', '#10B981'];
    $paths = [];
    $dots = [];
    foreach ($series as $idx => $metric) {
        $color = $palette[$idx % count($palette)];
        $currentPath = '';
        foreach ($labels as $label) {
            $value = valueForLabel($metric['points'], $label);
            if ($value === null) {
                if ($currentPath !== '') {
                    $paths[] = ['d' => $currentPath, 'color' => $color];
                    $currentPath = '';
                }
                continue;
            }
            $x = $xPositions[$label];
            $y = $chartBottom - ($value / $maxValue) * $chartHeight;
            if ($currentPath === '') {
                $currentPath = "M{$x},{$y}";
            } else {
                $currentPath .= " L{$x},{$y}";
            }
            $dots[] = [
                'x' => $x,
                'y' => $y,
                'color' => $color,
                'value' => $value,
            ];
        }
        if ($currentPath !== '') {
            $paths[] = ['d' => $currentPath, 'color' => $color];
        }
    }

    $ticks = [];
    $tickCount = 4;
    for ($i = 0; $i <= $tickCount; $i++) {
        $value = ($maxValue / $tickCount) * $i;
        $y = $chartBottom - ($value / $maxValue) * $chartHeight;
        $ticks[] = ['value' => $value, 'y' => $y];
    }

    $html = '<div class="teaser-card teaser-chart-card" data-variant="chart">';
    $html .= '<div class="teaser-card__icon">📈</div>';
    $html .= '<h3>Динамика финансов</h3>';
    $html .= '<div class="teaser-chart">';
    $html .= '<svg viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="График финансов">';
    $html .= '<line x1="' . $chartLeft . '" y1="' . $chartBottom . '" x2="' . $chartRight . '" y2="' . $chartBottom . '" stroke="rgba(15,23,42,0.45)" stroke-width="1"/>';
    $html .= '<line x1="' . $chartLeft . '" y1="' . $chartTop . '" x2="' . $chartLeft . '" y2="' . $chartBottom . '" stroke="rgba(15,23,42,0.45)" stroke-width="1"/>';

    foreach ($ticks as $tick) {
        $html .= '<line x1="' . ($chartLeft - 5) . '" y1="' . $tick['y'] . '" x2="' . $chartLeft . '" y2="' . $tick['y'] . '" stroke="rgba(15,23,42,0.35)" stroke-width="0.8"/>';
        $html .= '<text x="' . ($chartLeft - 8) . '" y="' . ($tick['y'] + 4) . '" font-size="10" text-anchor="end" fill="rgba(15,23,42,0.75)">' . number_format($tick['value'], 0, '.', ' ') . '</text>';
    }

    foreach ($labels as $label) {
        $x = $xPositions[$label];
        $html .= '<line x1="' . $x . '" y1="' . $chartBottom . '" x2="' . $x . '" y2="' . ($chartBottom + 4) . '" stroke="rgba(15,23,42,0.35)" stroke-width="0.8"/>';
        $html .= '<text x="' . $x . '" y="' . ($chartBottom + 14) . '" font-size="10" text-anchor="middle" fill="rgba(15,23,42,0.75)">' . escapeHtml($label) . '</text>';
    }

    foreach ($paths as $path) {
        $html .= '<path d="' . $path['d'] . '" fill="none" stroke="' . $path['color'] . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.9"/>';
    }

    foreach ($dots as $dot) {
        $html .= '<circle cx="' . $dot['x'] . '" cy="' . $dot['y'] . '" r="3.4" fill="' . $dot['color'] . '" opacity="0.95"/>';
    }

    $html .= '</svg>';

    $html .= '<div class="teaser-chart-legend">';
    foreach ($series as $idx => $metric) {
        $color = $palette[$idx % count($palette)];
        $html .= '<span><i style="background:' . $color . '"></i>' . escapeHtml($metric['title']) . '</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $html .= '<p class="teaser-chart__note">Показатели указаны в млн ₽. Источник: анкета продавца (факт + бюджет).</p>';
    $html .= '</div>';
    return $html;
}

function normalizeTeaserData(array $data, array $payload): array
{
    $placeholder = 'Информация уточняется.';
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

    $data['market'] = [
        'trend' => $data['market']['trend'] ?? 'Рынок демонстрирует устойчивый интерес инвесторов.',
        'size' => $data['market']['size'] ?? 'Объём рынка оценивается как значительный по отраслевым данным.',
        'growth' => $data['market']['growth'] ?? 'Ожидается стабильный рост 5–10% в год.',
        'sources' => normalizeArray($data['market']['sources'] ?? ['Отраслевые обзоры SmartBizSell']),
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
    return ['Информация уточняется.'];
}

function buildSalesChannelsText(array $payload): string
{
    $channels = [];
    if (!empty($payload['offline_sales_presence'])) {
        $channels[] = 'Оффлайн: ' . $payload['offline_sales_presence'];
    }
    if (!empty($payload['online_sales_channels'])) {
        $channels[] = 'Онлайн: ' . $payload['online_sales_channels'];
    }
    if (!empty($payload['contract_production_usage'])) {
        $channels[] = 'Contract manufacturing: ' . $payload['contract_production_usage'];
    }
    if (empty($channels)) {
        return 'Каналы продаж уточняются.';
    }
    return implode('; ', $channels);
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

function buildHeroSummary(?string $aiSummary, array $payload, string $fallback): string
{
    $summary = trim((string)$aiSummary);
    if ($summary !== '' && !looksLikeStructuredDump($summary)) {
        return prettifySummary($summary);
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

    if (count($sentences) < 2) {
        $sentences[] = $fallback;
    }

    return implode(' ', array_map('prettifySummary', $sentences));
}

function prettifySummary(string $summary): string
{
    $plain = trim($summary);
    $plain = preg_replace('/\s+/', ' ', $plain);
    $plain = preg_replace('/[;••]/u', '.', $plain);
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

