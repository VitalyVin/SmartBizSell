<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/investor_utils.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('escapeHtml')) {
    function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается.'], JSON_UNESCAPED_UNICODE);
    exit;
}

initSession();
if (!allowPublicInvestorRequest()) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Слишком много запросов. Повторите попытку через минуту.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = getJsonInput();
$form = normalizeInvestorMatchForm($input);
$validationError = validateInvestorMatchForm($form);
if ($validationError !== null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $validationError], JSON_UNESCAPED_UNICODE);
    exit;
}

$apiKey = defined('TOGETHER_API_KEY') ? TOGETHER_API_KEY : '';
$summary = generateShortBusinessSummary($form);

$payload = buildInvestorPayload($form, $summary);
$catalog = loadRagInvestors(__DIR__ . '/rag_investors.xlsx');
if (empty($catalog)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Каталог инвесторов временно недоступен.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ranked = rankInvestorsByRelevance($catalog, $payload);
$investors = selectTopInvestorsWithAi($ranked, $payload, $apiKey, 5);
if (empty($investors)) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось подобрать инвесторов. Попробуйте уточнить описание бизнеса.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$html = renderInvestorSection($investors, 5, [
    'show_send_button' => false,
    'section_class' => 'investor-section--public',
]);

sendInvestorMatchSubmission($form, $summary, $investors);

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'investors' => $investors,
    'html' => $html,
], JSON_UNESCAPED_UNICODE);

function allowPublicInvestorRequest(): bool
{
    $windowSeconds = 60;
    $maxRequests = 10;
    $now = time();
    $bucket = $_SESSION['public_investor_match_rate'] ?? ['time' => $now, 'count' => 0];

    if (($now - (int)$bucket['time']) > $windowSeconds) {
        $bucket = ['time' => $now, 'count' => 0];
    }

    $bucket['count']++;
    $_SESSION['public_investor_match_rate'] = $bucket;

    return $bucket['count'] <= $maxRequests;
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeInvestorMatchForm(array $input): array
{
    $dealOptions = normalizeStringArray($input['deal_subject'] ?? []);
    $assetOptions = normalizeStringArray($input['assets'] ?? []);

    return [
        'inn' => trim((string)($input['inn'] ?? '')),
        'deal_subject' => $dealOptions,
        'assets' => $assetOptions,
        'offer' => trim((string)($input['offer'] ?? '')),
        'website' => trim((string)($input['website'] ?? '')),
        'region' => trim((string)($input['region'] ?? '')),
        'revenue' => trim((string)($input['revenue'] ?? '')),
    ];
}

function normalizeStringArray($value): array
{
    if (!is_array($value)) {
        return [];
    }

    $out = [];
    foreach ($value as $item) {
        $clean = trim((string)$item);
        if ($clean !== '') {
            $out[] = $clean;
        }
    }

    return array_values(array_unique($out));
}

function validateInvestorMatchForm(array $form): ?string
{
    if ($form['inn'] === '') {
        return 'Укажите ИНН компании.';
    }

    if (!preg_match('/^\d{10}(\d{2})?$/', $form['inn'])) {
        return 'ИНН должен содержать 10 или 12 цифр.';
    }
    if (empty($form['deal_subject'])) {
        return 'Выберите предмет сделки.';
    }
    if (empty($form['assets'])) {
        return 'Выберите материальные активы.';
    }
    if ($form['offer'] === '' || mb_strlen($form['offer']) < 40) {
        return 'Опишите продукт/услугу минимум в 1-2 предложениях.';
    }

    foreach (['inn', 'offer', 'website', 'region', 'revenue'] as $field) {
        if (mb_strlen((string)$form[$field]) > 1000) {
            return 'Слишком длинные данные в анкете.';
        }
    }

    return null;
}

function generateShortBusinessSummary(array $form): string
{
    $deal = implode(', ', $form['deal_subject']);
    $assets = implode(', ', $form['assets']);
    $website = $form['website'] !== '' ? $form['website'] : 'не указан';
    $region = $form['region'] !== '' ? $form['region'] : 'не указан';
    $revenue = $form['revenue'] !== '' ? $form['revenue'] : 'не указана';

    return "Предмет сделки: {$deal}. "
        . "Материальные активы: {$assets}. "
        . "Продукт/услуга: {$form['offer']} "
        . "Регион: {$region}. "
        . "Выручка за последний отчетный период (2025): {$revenue}. "
        . "Сайт: {$website}.";
}

function buildInvestorPayload(array $form, string $summary): array
{
    return [
        'asset_name' => 'Компания ' . $form['inn'],
        'products_services' => $form['offer'],
        'company_description' => $summary,
        'presence_regions' => $form['region'],
        'deal_goal' => implode('; ', $form['deal_subject']),
        'main_clients' => '',
        'additional_info' => implode('; ', $form['assets']) . ($form['website'] !== '' ? '; Сайт: ' . $form['website'] : ''),
        'financial' => [
            'revenue' => [
                '2025_fact' => $form['revenue'],
                '2024_fact' => $form['revenue'],
            ],
        ],
    ];
}

function selectTopInvestorsWithAi(array $ranked, array $payload, string $apiKey, int $limit): array
{
    $limit = max(1, $limit);
    $basePool = array_slice($ranked, 0, 20);
    if (empty($basePool)) {
        return [];
    }

    $mappedByName = [];
    foreach ($basePool as $row) {
        $nameKey = mb_strtolower(trim((string)($row['name'] ?? '')));
        if ($nameKey !== '') {
            $mappedByName[$nameKey] = $row;
        }
    }

    $selected = [];
    $aiPicked = selectInvestorNamesByAi($payload, $basePool, $apiKey, $limit);
    foreach ($aiPicked as $pick) {
        $key = mb_strtolower(trim((string)($pick['name'] ?? '')));
        if ($key === '' || !isset($mappedByName[$key])) {
            continue;
        }

        $row = $mappedByName[$key];
        if (!empty($pick['reason'])) {
            $row['reason'] = $pick['reason'];
        }
        $selected[$key] = $row;
        if (count($selected) >= $limit) {
            break;
        }
    }

    if (count($selected) < $limit) {
        foreach ($basePool as $row) {
            $key = mb_strtolower(trim((string)($row['name'] ?? '')));
            if ($key === '' || isset($selected[$key])) {
                continue;
            }
            $selected[$key] = $row;
            if (count($selected) >= $limit) {
                break;
            }
        }
    }

    $result = array_slice(array_values($selected), 0, $limit);
    foreach ($result as &$row) {
        unset($row['score']);
        $row['source'] = 'catalog';
    }

    return $result;
}

function selectInvestorNamesByAi(array $payload, array $candidatePool, string $apiKey, int $limit): array
{
    if ($apiKey === '') {
        return [];
    }

    $assetSummary = buildAssetSummaryForInvestors($payload);
    $excerpt = buildInvestorCatalogExcerpt($candidatePool, 40);
    $limit = max(1, min($limit, 5));

    $prompt = <<<PROMPT
Ты подбираешь инвесторов из ГОТОВОГО списка кандидатов.
На основе короткого описания компании выбери до {$limit} наиболее подходящих инвесторов ИСКЛЮЧИТЕЛЬНО из списка ниже.
Не придумывай новые названия.

Короткое описание компании:
{$assetSummary}

Список кандидатов:
{$excerpt}

Верни JSON-массив формата:
[
  {"name":"Точное название из списка","reason":"Короткое обоснование релевантности"},
  {"name":"...","reason":"..."}
]
PROMPT;

    $raw = callTogetherCompletionsPublic($prompt, $apiKey);
    if ($raw === '') {
        return [];
    }

    $json = extractJsonArray($raw);
    if ($json === null) {
        return [];
    }

    $result = [];
    foreach ($json as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $reason = trim((string)($row['reason'] ?? ''));
        if ($name === '') {
            continue;
        }
        $result[] = ['name' => $name, 'reason' => $reason];
        if (count($result) >= $limit) {
            break;
        }
    }

    return $result;
}

function extractJsonArray(string $raw): ?array
{
    $clean = trim($raw);
    $candidate = json_decode($clean, true);
    if (is_array($candidate)) {
        return $candidate;
    }

    if (preg_match('/\[[\s\S]*\]/u', $clean, $matches) !== 1) {
        return null;
    }

    $candidate = json_decode($matches[0], true);
    return is_array($candidate) ? $candidate : null;
}

function callTogetherCompletionsPublic(string $prompt, string $apiKey): string
{
    $payload = [
        'model' => defined('TOGETHER_MODEL') ? TOGETHER_MODEL : 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
        'prompt' => $prompt,
        'max_tokens' => 900,
        'temperature' => 0.2,
        'top_p' => 0.8,
    ];

    $ch = curl_init('https://api.together.xyz/v1/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => defined('TOGETHER_TIMEOUT_COMPLETIONS') ? TOGETHER_TIMEOUT_COMPLETIONS : 45,
        CURLOPT_CONNECTTIMEOUT => defined('TOGETHER_CONNECT_TIMEOUT') ? TOGETHER_CONNECT_TIMEOUT : 5,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '' || $status >= 400) {
        return '';
    }

    $decoded = json_decode((string)$response, true);
    $text = trim((string)($decoded['choices'][0]['text'] ?? ''));
    return $text;
}

function sendInvestorMatchSubmission(array $form, string $summary, array $investors): void
{
    if (!function_exists('sendEmail')) {
        error_log('Investor match submission email skipped: sendEmail() not available.');
        return;
    }

    if (empty($form) || $summary === '' || empty($investors)) {
        return;
    }

    try {
        $compactInvestors = array_map(static function (array $row): array {
            return [
                'name' => (string)($row['name'] ?? ''),
                'focus' => (string)($row['focus'] ?? ''),
                'check' => (string)($row['check'] ?? ''),
                'reason' => (string)($row['reason'] ?? ''),
                'source' => (string)($row['source'] ?? 'catalog'),
            ];
        }, $investors);

        $submission = [
            'submitted_at' => date('c'),
            'form' => $form,
            'summary' => $summary,
            'investors' => $compactInvestors,
            'meta' => [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'referer' => $_SERVER['HTTP_REFERER'] ?? '',
            ],
        ];

        $jsonPretty = json_encode($submission, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($jsonPretty === false) {
            error_log('Investor match submission email: json_encode failed.');
            return;
        }

        $inn = htmlspecialchars((string)$form['inn'], ENT_QUOTES, 'UTF-8');
        $offerPreview = htmlspecialchars(mb_substr((string)$form['offer'], 0, 240), ENT_QUOTES, 'UTF-8');
        $jsonHtml = htmlspecialchars($jsonPretty, ENT_QUOTES, 'UTF-8');

        $subject = 'Новая анкета подбора инвестора (ИНН ' . (string)$form['inn'] . ')';
        $htmlBody = '<p>Получена новая анкета подбора инвестора.</p>'
            . '<p><strong>ИНН:</strong> ' . $inn . '</p>'
            . '<p><strong>Описание:</strong> ' . $offerPreview . '</p>'
            . '<p>Полные данные в формате JSON:</p>'
            . '<pre style="font-family:Menlo,Consolas,monospace;font-size:13px;background:#f5f7fb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;white-space:pre-wrap;word-break:break-word;">'
            . $jsonHtml
            . '</pre>';

        $textBody = "Новая анкета подбора инвестора.\n\n" . $jsonPretty;

        $sent = sendEmail(
            'info@smartbizsell.ru',
            $subject,
            $htmlBody,
            $textBody,
            'no-reply@smartbizsell.ru',
            'SmartBizSell'
        );

        if (!$sent) {
            error_log('Investor match submission email: sendEmail returned false.');
        }
    } catch (Throwable $e) {
        error_log('Investor match submission email error: ' . $e->getMessage());
    }
}
