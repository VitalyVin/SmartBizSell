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
    $html = renderTeaserHtml($teaserData, $formPayload['asset_name'] ?? 'Актив');

    echo json_encode([
        'success' => true,
        'html' => $html,
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
function renderTeaserHtml(array $data, string $assetName): string
{
    $blocks = [];

    if (!empty($data['overview'])) {
        $overview = $data['overview'];
        $blocks[] = renderCard('Обзор возможности', [
            'subtitle' => htmlspecialchars($overview['title'] ?? $assetName, ENT_QUOTES, 'UTF-8'),
            'text' => nl2br(htmlspecialchars($overview['summary'] ?? '', ENT_QUOTES, 'UTF-8')),
            'list' => $overview['key_metrics'] ?? [],
        ]);
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
            ]);
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
            ]);
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
        ]);
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
        ]);
    }

    if (!empty($data['highlights']['bullets'])) {
        $blocks[] = renderCard('Инвестиционные преимущества', [
            'list' => $data['highlights']['bullets'],
        ]);
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
            ]);
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
        ]);
    }

    if (empty($blocks)) {
        $blocks[] = renderCard('Тизер', [
            'text' => 'AI вернул нестандартный ответ. Содержание: ' . escapeHtml(json_encode($data, JSON_UNESCAPED_UNICODE)),
        ]);
    }

    return '<div class="teaser-grid">' . implode('', $blocks) . '</div>';
}

function renderCard(string $title, array $payload): string
{
    $html = '<div class="teaser-card">';
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

