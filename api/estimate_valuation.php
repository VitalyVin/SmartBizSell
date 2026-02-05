<?php
/**
 * API расчёта ориентировочной оценки компании без авторизации и без сохранения в БД.
 * Принимает минимальный набор полей, строит виртуальную форму и вызывает DCF + мультипликаторы.
 *
 * POST JSON: activity_description, revenue_last, profit_last?, margin_pct?, unit (million|thousand), vat (with_vat|without_vat), revenue_2023?, revenue_2024?
 * Ответ: { success, range: { min, max }, dcf_mln?, multiplier_mln?, sector?, error? }
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не разрешён. Используйте POST.']);
    exit;
}

define('ESTIMATE_VALUATION_API', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dashboard.php';
require_once __DIR__ . '/../calculate_multiplier_valuation.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'error' => 'Неверный JSON в теле запроса.']);
    exit;
}

$activity = trim((string)($input['activity_description'] ?? ''));
$revenueLast = isset($input['revenue_last']) ? (float)$input['revenue_last'] : 0;
$profitLast = isset($input['profit_last']) ? (float)$input['profit_last'] : null;
$marginPct = isset($input['margin_pct']) ? (float)$input['margin_pct'] : null;
$unit = isset($input['unit']) && $input['unit'] === 'thousand' ? 'thousand' : 'million';
$vat = isset($input['vat']) && $input['vat'] === 'without_vat' ? 'without_vat' : 'with_vat';
$revenue2023 = isset($input['revenue_2023']) ? (float)$input['revenue_2023'] : null;
$revenue2024 = isset($input['revenue_2024']) ? (float)$input['revenue_2024'] : null;

if ($revenueLast <= 0) {
    echo json_encode(['success' => false, 'error' => 'Укажите выручку за последний год.']);
    exit;
}

$toMillions = function ($v) use ($unit) {
    if ($unit === 'thousand') {
        return $v / 1000.0;
    }
    return $v;
};

$r2025 = $toMillions($revenueLast);
$r2024 = $revenue2024 !== null ? $toMillions($revenue2024) : ($r2025 / 1.10);
$r2023 = $revenue2023 !== null ? $toMillions($revenue2023) : ($r2024 / 1.10);
$budget2026 = $r2025 * 1.05;

$profit = $profitLast !== null ? $toMillions($profitLast) : null;
if ($profit === null && $marginPct !== null) {
    $profit = $r2025 * ($marginPct / 100.0);
}
if ($profit === null) {
    $profit = $r2025 * 0.15;
}

$unitStr = $unit === 'thousand' ? 'тыс. руб.' : 'млн. руб.';
$cogsShare = 0.60;
$commShare = 0.10;
$adminShare = 0.15;
$cogs2023 = $r2023 * $cogsShare;
$cogs2024 = $r2024 * $cogsShare;
$cogs2025 = $r2025 * $cogsShare;
$cogs2026 = $budget2026 * $cogsShare;
$comm2023 = $r2023 * $commShare;
$comm2024 = $r2024 * $commShare;
$comm2025 = $r2025 * $commShare;
$comm2026 = $budget2026 * $commShare;
$admin2023 = $r2023 * $adminShare;
$admin2024 = $r2024 * $adminShare;
$admin2025 = $r2025 * $adminShare;
$admin2026 = $budget2026 * $adminShare;
$profit2023 = $r2023 - $cogs2023 - $comm2023 - $admin2023;
$profit2024 = $r2024 - $cogs2024 - $comm2024 - $admin2024;
$profit2025 = $profit;
$profit2026 = $budget2026 - $cogs2026 - $comm2026 - $admin2026;

$financial = [
    'revenue' => [
        'unit' => $unitStr,
        'fact_2022' => '',
        'fact_2023' => round($r2023, 2),
        'fact_2024' => round($r2024, 2),
        'fact_2025' => round($r2025, 2),
        'budget_2026' => round($budget2026, 2),
    ],
    'cost_of_sales' => [
        'unit' => $unitStr,
        'fact_2022' => '',
        'fact_2023' => round($cogs2023, 2),
        'fact_2024' => round($cogs2024, 2),
        'fact_2025' => round($cogs2025, 2),
        'budget_2026' => round($cogs2026, 2),
    ],
    'commercial_expenses' => [
        'unit' => $unitStr,
        'fact_2022' => '',
        'fact_2023' => round($comm2023, 2),
        'fact_2024' => round($comm2024, 2),
        'fact_2025' => round($comm2025, 2),
        'budget_2026' => round($comm2026, 2),
    ],
    'management_expenses' => [
        'unit' => $unitStr,
        'fact_2022' => '',
        'fact_2023' => round($admin2023, 2),
        'fact_2024' => round($admin2024, 2),
        'fact_2025' => round($admin2025, 2),
        'budget_2026' => round($admin2026, 2),
    ],
    'sales_profit' => [
        'unit' => $unitStr,
        'fact_2022' => '',
        'fact_2023' => round($profit2023, 2),
        'fact_2024' => round($profit2024, 2),
        'fact_2025' => round($profit2025, 2),
        'budget_2026' => round($profit2026, 2),
    ],
    'depreciation' => [
        'unit' => $unitStr,
        'fact_2022' => '',
        'fact_2023' => 0,
        'fact_2024' => 0,
        'fact_2025' => 0,
        'budget_2026' => 0,
    ],
];

$fixedAssets = max(0.5, $r2025 * 0.10);
$balance = [
    'fixed_assets' => [
        'unit' => 'млн. руб.',
        'fact_2022' => round($fixedAssets * 0.8, 2),
        'fact_2023' => round($fixedAssets * 0.9, 2),
        'fact_2024' => round($fixedAssets, 2),
        'fact_2025' => round($fixedAssets, 2),
    ],
    'inventory' => ['unit' => 'млн. руб.', 'fact_2022' => 0, 'fact_2023' => 0, 'fact_2024' => 0, 'fact_2025' => 0],
    'receivables' => ['unit' => 'млн. руб.', 'fact_2022' => 0, 'fact_2023' => 0, 'fact_2024' => 0, 'fact_2025' => 0],
    'payables' => ['unit' => 'млн. руб.', 'fact_2022' => 0, 'fact_2023' => 0, 'fact_2024' => 0, 'fact_2025' => 0],
    'loans' => ['unit' => 'млн. руб.', 'fact_2022' => 0, 'fact_2023' => 0, 'fact_2024' => 0, 'fact_2025' => 0],
    'cash' => ['unit' => 'млн. руб.', 'fact_2022' => 0, 'fact_2023' => 0, 'fact_2024' => 0, 'fact_2025' => 0],
    'net_assets' => ['unit' => 'млн. руб.', 'fact_2022' => round($fixedAssets * 0.8, 2), 'fact_2023' => round($fixedAssets * 0.9, 2), 'fact_2024' => round($fixedAssets, 2), 'fact_2025' => round($fixedAssets, 2)],
];

$form = [
    'id' => 0,
    'asset_name' => '',
    'financial_results' => json_encode($financial, JSON_UNESCAPED_UNICODE),
    'balance_indicators' => json_encode($balance, JSON_UNESCAPED_UNICODE),
    'financial_results_vat' => $vat,
    'company_description' => $activity,
    'products_services' => $activity,
    'data_json' => json_encode([
        'company_description' => $activity,
        'products_services' => $activity,
    ], JSON_UNESCAPED_UNICODE),
];

$dcfMln = null;
$multiplierMln = null;
$sector = 'Средний по рынку';

try {
    $dcfResult = calculateUserDCF($form);
    if (!empty($dcfResult['error'])) {
        $dcfMln = null;
    } elseif (!empty($dcfResult['ev_breakdown']['equity'])) {
        $dcfMln = round((float)$dcfResult['ev_breakdown']['equity'], 2);
    }
} catch (Throwable $e) {
    error_log('Estimate valuation API DCF error: ' . $e->getMessage());
    $dcfMln = null;
}

$apiKey = TOGETHER_API_KEY ?? null;
if ($activity !== '' && $apiKey) {
    try {
        $sector = determineSector($activity, $activity, $apiKey);
    } catch (Throwable $e) {
        error_log('Estimate valuation API sector error: ' . $e->getMessage());
    }
}

try {
    $finData = extractFinancialData($form);
    if (empty($finData['error'])) {
        $multResult = calculateMultiplierValuation($sector, $finData);
        if (!empty($multResult['equity_value'])) {
            $multiplierMln = round((float)$multResult['equity_value'], 2);
        }
    }
} catch (Throwable $e) {
    error_log('Estimate valuation API multiplier error: ' . $e->getMessage());
    $multiplierMln = null;
}

$values = array_filter([$dcfMln, $multiplierMln], function ($v) {
    return $v !== null && $v > 0;
});
if (empty($values)) {
    echo json_encode([
        'success' => false,
        'error' => 'Не удалось рассчитать оценку. Проверьте введённые данные (выручка и прибыль/маржа).',
        'sector' => $sector,
    ]);
    exit;
}

$min = round(min($values), 2);
$max = round(max($values), 2);

echo json_encode([
    'success' => true,
    'range' => ['min' => $min, 'max' => $max],
    'dcf_mln' => $dcfMln,
    'multiplier_mln' => $multiplierMln,
    'sector' => $sector,
]);
