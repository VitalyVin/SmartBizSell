<?php
/**
 * Личный кабинет продавца
 * 
 * Функциональность:
 * - Просмотр статистики по анкетам
 * - Список всех анкет пользователя с фильтрацией по статусу
 * - Переход к созданию новой анкеты
 * - Переход к настройкам профиля
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

/**
 * Проверка авторизации пользователя
 * Если пользователь не авторизован, происходит редирект на страницу входа
 */
if (!isLoggedIn()) {
    redirectToLogin();
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

/**
 * Получение всех анкет текущего пользователя из базы данных
 * Анкеты сортируются по дате создания (новые первыми)
 */
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, asset_name, status, created_at, updated_at, submitted_at 
        FROM seller_forms 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $forms = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching forms: " . $e->getMessage());
    $forms = [];
}

$activeForms = array_values(array_filter($forms, fn($f) => $f['status'] !== 'draft'));
$draftForms = array_values(array_filter($forms, fn($f) => $f['status'] === 'draft'));

/**
 * Маппинг статусов анкет для отображения
 * Каждый статус имеет текстовое название и цвет для визуального отображения
 */
$statusLabels = [
    'draft' => 'Черновик',
    'submitted' => 'Отправлена',
    'review' => 'На проверке',
    'approved' => 'Одобрена',
    'rejected' => 'Отклонена'
];

$statusColors = [
    'draft' => '#86868B',
    'submitted' => '#007AFF',
    'review' => '#FF9500',
    'approved' => '#34C759',
    'rejected' => '#FF3B30'
];

/**
 * Вспомогательные функции для DCF
 */
function dcf_to_float($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    $normalized = str_replace([' ', ' '], '', (string)$value);
    $normalized = str_replace(',', '.', $normalized);
    return is_numeric($normalized) ? (float)$normalized : 0.0;
}

function dcf_rows_by_metric(?array $rows): array {
    $result = [];
    foreach ($rows ?? [] as $row) {
        if (!empty($row['metric'])) {
            $result[$row['metric']] = $row;
        }
    }
    return $result;
}

function dcf_build_series(array $row, array $order): array {
    $series = [];
    foreach ($order as $key => $label) {
        $series[$label] = dcf_to_float($row[$key] ?? 0);
    }
    return $series;
}

/**
 * Унификация значений (поддержка новых ключей 2022_fact и legacy fact_2022)
 */
function pickValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return '';
}

function convertFinancialRows(array $financial): array
{
    if (empty($financial)) {
        return [];
    }

    $first = reset($financial);
    if (is_array($first) && isset($first['metric'])) {
        return $financial;
    }

    $map = [
        'revenue' => 'Выручка',
        'cost_of_sales' => 'Себестоимость продаж',
        'commercial_expenses' => 'Коммерческие расходы',
        'management_expenses' => 'Управленческие расходы',
        'sales_profit' => 'Прибыль от продаж',
        'depreciation' => 'Амортизация',
        'fixed_assets_acquisition' => 'Приобретение основных средств',
    ];

    $fields = [
        'unit'         => ['unit'],
        'fact_2022'    => ['fact_2022', '2022_fact'],
        'fact_2023'    => ['fact_2023', '2023_fact'],
        'fact_2024'    => ['fact_2024', '2024_fact'],
        'fact_2025_9m' => ['fact_2025_9m', '2025_q3_fact'],
        'budget_2025'  => ['budget_2025', '2025_budget'],
        'budget_2026'  => ['budget_2026', '2026_budget'],
    ];

    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($financial[$key]) || !is_array($financial[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        foreach ($fields as $legacyKey => $aliases) {
            $row[$legacyKey] = pickValue($financial[$key], $aliases);
        }
        $result[] = $row;
    }

    return $result;
}

function convertBalanceRows(array $balance): array
{
    if (empty($balance)) {
        return [];
    }

    $first = reset($balance);
    if (is_array($first) && isset($first['metric'])) {
        return $balance;
    }

    $map = [
        'fixed_assets' => 'Основные средства',
        'inventory'    => 'Запасы',
        'receivables'  => 'Дебиторская задолженность',
        'payables'     => 'Кредиторская задолженность',
        'loans'        => 'Кредиты и займы',
        'cash'         => 'Денежные средства',
        'net_assets'   => 'Чистые активы',
    ];

    $fields = [
        'unit'         => ['unit'],
        'fact_2022'    => ['fact_2022', '2022_fact'],
        'fact_2023'    => ['fact_2023', '2023_fact'],
        'fact_2024'    => ['fact_2024', '2024_fact'],
        'fact_2025_9m' => ['fact_2025_9m', '2025_q3_fact'],
    ];

    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($balance[$key]) || !is_array($balance[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        foreach ($fields as $legacyKey => $aliases) {
            $row[$legacyKey] = pickValue($balance[$key], $aliases);
        }
        $result[] = $row;
    }

    return $result;
}

function extractFinancialAndBalance(array $form): array
{
    $financial = json_decode($form['financial_results'] ?? '[]', true);
    $balance   = json_decode($form['balance_indicators'] ?? '[]', true);

    if (empty($form['data_json']) === false) {
        $decoded = json_decode($form['data_json'], true);
        if (empty($financial) && isset($decoded['financial']) && is_array($decoded['financial'])) {
            $financial = $decoded['financial'];
        }
        if (empty($balance) && isset($decoded['balance']) && is_array($decoded['balance'])) {
            $balance = $decoded['balance'];
        }
    }

    $financial = convertFinancialRows($financial);
    $balance   = convertBalanceRows($balance);

    return [$financial, $balance];
}

function stableRandFloat(string $seed, int $offset, float $min, float $max): float
{
    $hash = crc32($seed . '|' . $offset);
    $normalized = fmod(abs(sin($hash + $offset * 12.9898)), 1);
    return $min + ($max - $min) * $normalized;
}

function clampFloat(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

/**
 * Строит полную DCF-модель на основе последней отправленной анкеты пользователя.
 * Возвращает не только итоговые показатели, но и полный набор параметров/предупреждений
 * для отображения в личном кабинете.
 */
function calculateUserDCF(array $form): array {
    $defaults = [
        'wacc' => 0.24,
        'tax_rate' => 0.25,
        'perpetual_growth' => 0.04,
    ];

    $periodMap = [
        'fact_2022'    => '2022',
        'fact_2023'    => '2023',
        'fact_2024'    => '2024',
        'fact_2025_9m' => '9M2025',
        'budget_2025'  => '2025',
        'budget_2026'  => '2026',
    ];

    list($financial, $balance) = extractFinancialAndBalance($form);
    if (!$financial || !$balance) {
        return ['error' => 'Недостаточно финансовых данных для построения модели.'];
    }

    $finRows = dcf_rows_by_metric($financial);
    $balRows = dcf_rows_by_metric($balance);

    $requiredMetrics = ['Выручка', 'Себестоимость продаж', 'Коммерческие расходы'];
    foreach ($requiredMetrics as $metric) {
        if (!isset($finRows[$metric])) {
            return ['error' => 'Не заполнены обязательные строки финансовой таблицы (выручка/расходы).'];
        }
    }

    $revenueSeries   = dcf_build_series($finRows['Выручка'], $periodMap);
    $cogsSeries      = dcf_build_series($finRows['Себестоимость продаж'], $periodMap);
    $commercialSeries= dcf_build_series($finRows['Коммерческие расходы'], $periodMap);
    $adminSeries     = isset($finRows['Управленческие расходы']) ? dcf_build_series($finRows['Управленческие расходы'], $periodMap) : [];
    $deprSeries      = isset($finRows['Амортизация']) ? dcf_build_series($finRows['Амортизация'], $periodMap) : [];

    $balanceSeries = [];
    foreach ($balRows as $metric => $row) {
        $balanceSeries[$metric] = dcf_build_series($row, $periodMap);
    }

    $factYears = ['2022', '2023', '2024'];
    $forecastLabels = ['P1', 'P2', 'P3', 'P4', 'P5'];
    $columns = [];
    foreach ($factYears as $label) {
        $columns[] = ['key' => $label, 'label' => $label, 'type' => 'fact'];
    }
    foreach ($forecastLabels as $label) {
        $columns[] = ['key' => $label, 'label' => $label, 'type' => 'forecast'];
    }
    $columns[] = ['key' => 'TV', 'label' => 'TV', 'type' => 'tv'];

    $lastFactLabel = '2024';
    if (($revenueSeries[$lastFactLabel] ?? 0) <= 0) {
        return ['error' => 'Укажите выручку минимум за три последних года (включая 2024).'];
    }

    $factData = [
        'revenue' => [],
        'cogs' => [],
        'commercial' => [],
        'admin' => [],
        'depr' => [],
        'ebitda' => [],
        'ebit' => [],
        'margin' => [],
    ];

    foreach ($factYears as $year) {
        $factData['revenue'][$year]    = $revenueSeries[$year] ?? 0;
        $factData['cogs'][$year]       = $cogsSeries[$year] ?? 0;
        $factData['commercial'][$year] = $commercialSeries[$year] ?? 0;
        $factData['admin'][$year]      = $adminSeries[$year] ?? 0;
        $factData['depr'][$year]       = $deprSeries[$year] ?? 0;
        $factData['ebitda'][$year]     = $factData['revenue'][$year] - $factData['cogs'][$year] - $factData['commercial'][$year] - $factData['admin'][$year];
        $factData['ebit'][$year]       = $factData['ebitda'][$year] - $factData['depr'][$year];
        $factData['margin'][$year]     = ($factData['revenue'][$year] > 0) ? $factData['ebitda'][$year] / $factData['revenue'][$year] : null;
    }

    $factGrowth = [];
    $prevRevenue = null;
    foreach ($factYears as $year) {
        $current = $factData['revenue'][$year];
        if ($prevRevenue !== null && abs($prevRevenue) > 1e-6) {
            $factGrowth[$year] = ($current - $prevRevenue) / $prevRevenue;
        } else {
            $factGrowth[$year] = null;
        }
        $prevRevenue = $current;
    }
    $growthValues = array_values(array_filter($factGrowth, fn($value) => $value !== null));
    $gAvg = !empty($growthValues) ? array_sum($growthValues) / count($growthValues) : 0.05;
    $gLastFact = $factGrowth[$lastFactLabel] ?? 0.05;

    $seedKey = ($form['asset_name'] ?? '') . '|' . ($form['id'] ?? '0');
    $growthAnchors = [];
    if ($gAvg <= -0.20) {
        $growthAnchors[2] = 0.022;
        $growthAnchors[3] = 0.0315;
        $growthAnchors[4] = 0.04;
    } elseif ($gAvg <= 0) {
        $growthAnchors[3] = 0.0525;
        $growthAnchors[4] = 0.04;
    } elseif ($gAvg <= 0.1275) {
        $growthAnchors[0] = 0.1275;
        $growthAnchors[3] = 0.066;
        $growthAnchors[4] = 0.04;
    } else {
        $growthAnchors[3] = 0.066;
        $growthAnchors[4] = 0.04;
    }
    if (!isset($growthAnchors[4])) {
        $growthAnchors[4] = 0.04;
    }

    $p1Candidate = $growthAnchors[0] ?? clampFloat($gAvg, -0.20, 0.35);
    $p1Candidate = max($p1Candidate, $gLastFact + 0.0001);
    $p1Candidate = min($p1Candidate, $gLastFact + 0.10);
    if (abs($p1Candidate - $gLastFact) < 0.0001) {
        $p1Candidate = $gLastFact + 0.005;
    }
    $growthAnchors[0] = $p1Candidate;

    $forecastGrowth = array_fill(0, 5, null);
    for ($i = 0; $i < 5; $i++) {
        if (isset($growthAnchors[$i])) {
            $forecastGrowth[$i] = $growthAnchors[$i];
            continue;
        }
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (isset($growthAnchors[$j])) {
                $prev = [$j, $growthAnchors[$j]];
                break;
            }
        }
        $next = null;
        for ($j = $i + 1; $j < 5; $j++) {
            if (isset($growthAnchors[$j])) {
                $next = [$j, $growthAnchors[$j]];
                break;
            }
        }
        if ($prev && $next && $next[0] !== $prev[0]) {
            $ratio = ($i - $prev[0]) / ($next[0] - $prev[0]);
            $forecastGrowth[$i] = $prev[1] + ($next[1] - $prev[1]) * $ratio;
        } elseif ($prev) {
            $forecastGrowth[$i] = $prev[1];
        } elseif ($next) {
            $forecastGrowth[$i] = $next[1];
        } else {
            $forecastGrowth[$i] = $p1Candidate;
        }
    }

    foreach ($forecastGrowth as $idx => $value) {
        $forecastGrowth[$idx] = clampFloat(
            $value + stableRandFloat($seedKey, $idx, -0.002, 0.002),
            -0.30,
            0.40
        );
    }
    for ($i = 1; $i < count($forecastGrowth); $i++) {
        if ($forecastGrowth[$i] > $forecastGrowth[$i - 1]) {
            $forecastGrowth[$i] = $forecastGrowth[$i - 1] - 0.003;
        }
    }
    $forecastGrowth[0] = max($forecastGrowth[0], $gLastFact + 0.0001);
    $forecastGrowth[0] = min($forecastGrowth[0], $gLastFact + 0.10);

    $forecastRevenue = [];
    $prevRevenue = $factData['revenue'][$lastFactLabel];
    foreach ($forecastLabels as $index => $label) {
        $prevRevenue = $prevRevenue * (1 + $forecastGrowth[$index]);
        $forecastRevenue[$label] = max(0, $prevRevenue);
    }

    $computeShare = function (array $values, array $bases, array $years, float $fallback) {
        $ratios = [];
        $lastRatio = null;
        foreach ($years as $year) {
            $base = $bases[$year] ?? 0;
            if ($base > 0) {
                $ratio = ($values[$year] ?? 0) / $base;
                $ratios[] = $ratio;
                $lastRatio = $ratio;
            }
        }
        if (empty($ratios)) {
            return $fallback;
        }
        $avg = array_sum($ratios) / count($ratios);
        foreach ($ratios as $ratio) {
            if (abs($ratio - $avg) >= 0.10) {
                return $lastRatio ?? $avg;
            }
        }
        return $avg;
    };

    $cogsShare = $computeShare($factData['cogs'], $factData['revenue'], $factYears, 0.6);
    $commercialShare = $computeShare($factData['commercial'], $factData['revenue'], $factYears, 0.12);

    $forecastCogs = [];
    $forecastCommercial = [];
    foreach ($forecastLabels as $label) {
        $forecastCogs[$label] = $forecastRevenue[$label] * $cogsShare;
        $forecastCommercial[$label] = $forecastRevenue[$label] * $commercialShare;
    }

    $adminExists = ($factData['admin']['2022'] ?? 0) > 0 || ($factData['admin']['2023'] ?? 0) > 0 || ($factData['admin']['2024'] ?? 0) > 0;
    $adminForecast = [];
    $ebitdaForecast = [];
    $ebitdaMarginForecast = [];
    $inflationPath = [0.091, 0.055, 0.045, 0.04, 0.04];

    if ($adminExists) {
        $prevAdmin = $factData['admin'][$lastFactLabel] ?? 0;
        foreach ($forecastLabels as $idx => $label) {
            $prevAdmin *= (1 + $inflationPath[$idx]);
            $adminForecast[$label] = $prevAdmin;
            $ebitdaForecast[$label] = $forecastRevenue[$label] - $forecastCogs[$label] - $forecastCommercial[$label] - $adminForecast[$label];
            $ebitdaMarginForecast[$label] = ($forecastRevenue[$label] > 0)
                ? $ebitdaForecast[$label] / $forecastRevenue[$label]
                : null;
        }
    } else {
        $baseMargin = $factData['margin'][$lastFactLabel] ?? 0.2;
        $increment = stableRandFloat($seedKey, 99, 0.005, 0.008);
        foreach ($forecastLabels as $idx => $label) {
            $targetMargin = clampFloat($baseMargin + $increment * ($idx + 1), 0, 0.6);
            $baseCosts = $forecastRevenue[$label] - ($forecastCogs[$label] + $forecastCommercial[$label]);
            $desiredEbitda = $forecastRevenue[$label] * $targetMargin;
            $delta = $desiredEbitda - $baseCosts;
            if ($delta > 0) {
                $costSum = max($forecastCogs[$label] + $forecastCommercial[$label], 1e-6);
                $adjustFactor = clampFloat($delta / $costSum, 0, 0.2);
                $forecastCogs[$label] *= (1 - $adjustFactor * 0.7);
                $forecastCommercial[$label] *= (1 - $adjustFactor * 0.3);
            }
            $adminForecast[$label] = 0;
            $ebitdaForecast[$label] = $forecastRevenue[$label] - $forecastCogs[$label] - $forecastCommercial[$label];
            $ebitdaMarginForecast[$label] = ($forecastRevenue[$label] > 0)
                ? $ebitdaForecast[$label] / $forecastRevenue[$label]
                : null;
        }
    }

    $osLastFact = $balanceSeries['Основные средства'][$lastFactLabel] ?? null;
    if ($osLastFact === null || $osLastFact <= 0) {
        return ['error' => 'Не заполнены данные по основным средствам (баланс).'];
    }

    $deprForecast = [];
    $supportCapex = [];
    $osTrend = [];
    $prevOS = $osLastFact;
    foreach ($forecastLabels as $label) {
        $dep = 0.10 * $prevOS;
        $capex = 0.5 * $dep;
        $currentOS = $prevOS + $capex;
        $deprForecast[$label] = $dep;
        $supportCapex[$label] = $capex;
        $osTrend[$label] = $currentOS;
        $prevOS = $currentOS;
    }

    $ebitForecast = [];
    $taxForecast = [];
    foreach ($forecastLabels as $label) {
        $ebitForecast[$label] = $ebitdaForecast[$label] - $deprForecast[$label];
        $taxForecast[$label] = max(0, $ebitForecast[$label]) * $defaults['tax_rate'];
    }

    $tailYears = array_slice($factYears, -2);
    $factCostBase = [];
    foreach ($factYears as $year) {
        $factCostBase[$year] = ($factData['cogs'][$year] ?? 0) + ($factData['commercial'][$year] ?? 0) + ($factData['admin'][$year] ?? 0);
    }
    $avgArRatio = $computeShare($balanceSeries['Дебиторская задолженность'] ?? [], $factData['revenue'], $tailYears, 0.15);
    $avgInvRatio = $computeShare($balanceSeries['Запасы'] ?? [], $factData['cogs'], $tailYears, 0.12);
    $avgApRatio = $computeShare($balanceSeries['Кредиторская задолженность'] ?? [], $factCostBase, $tailYears, 0.09);

    $factNwc = [];
    foreach ($factYears as $year) {
        $ar = $balanceSeries['Дебиторская задолженность'][$year] ?? 0;
        $inv = $balanceSeries['Запасы'][$year] ?? 0;
        $ap = $balanceSeries['Кредиторская задолженность'][$year] ?? 0;
        $factNwc[$year] = $ar + $inv - $ap;
    }
    $nwcLastFact = $factNwc[$lastFactLabel] ?? 0;

    $nwcForecast = [];
    $deltaNwcForecast = [];
    foreach ($forecastLabels as $index => $label) {
        $ar = $forecastRevenue[$label] * $avgArRatio;
        $inv = $forecastCogs[$label] * $avgInvRatio;
        $apBase = $forecastCogs[$label] + $forecastCommercial[$label] + $adminForecast[$label];
        $ap = $apBase * $avgApRatio;
        $nwcForecast[$label] = $ar + $inv - $ap;
        if ($index === 0) {
            $deltaNwcForecast[$label] = $nwcForecast[$label] - $nwcLastFact;
        } else {
            $prevLabel = $forecastLabels[$index - 1];
            $deltaNwcForecast[$label] = $nwcForecast[$label] - $nwcForecast[$prevLabel];
        }
    }

    $fcffForecast = [];
    foreach ($forecastLabels as $label) {
        $fcffForecast[$label] = $ebitdaForecast[$label]
            - $taxForecast[$label]
            - $supportCapex[$label]
            - $deltaNwcForecast[$label];
    }

    $currentDate = new DateTime();
    $currentMonth = (int)$currentDate->format('n');
    $currentDay = (int)$currentDate->format('j');
    $elapsedFraction = clampFloat((($currentMonth - 1) + ($currentDay / 30)) / 12, 0, 0.99);
    $remainingFraction = 1 - $elapsedFraction;
    $stubFraction = clampFloat((12 - $currentMonth) / 12, 0, 1);

    $fcffDisplay = $fcffForecast;
    $fcffDisplay[$forecastLabels[0]] = $fcffForecast[$forecastLabels[0]] * $remainingFraction;

    $discountFactors = [];
    $discountedCf = [];
    $pvSum = 0;
    foreach ($forecastLabels as $index => $label) {
        // Period t for discounting: P1 is at stubFraction years, P2 at 1+stubFraction, etc.
        // For P1 (index=0): t = stubFraction (remaining part of current year)
        // For P2 (index=1): t = 1 + stubFraction (end of next year)
        // For P3 (index=2): t = 2 + stubFraction (end of year after next), etc.
        $t = $index + $stubFraction;
        $df = 1 / pow(1 + $defaults['wacc'], $t);
        $discountFactors[$label] = $df;
        $discountedCf[$label] = $fcffDisplay[$label] * $df;
        $pvSum += $discountedCf[$label];
    }

    // Terminal Value calculation using Gordon Growth Model
    // TV = FCF(n) * (1 + g) / (WACC - g)
    // where FCF(n) is the FCF of the last forecast year, g is perpetual growth rate
    $terminalFcff = end($fcffForecast);
    
    // Ensure WACC > perpetual_growth for the formula to work
    $wacc = $defaults['wacc'];
    $perpetualGrowth = $defaults['perpetual_growth'];
    if ($wacc <= $perpetualGrowth) {
        // If WACC <= growth, set growth to WACC - 0.01 to avoid division by zero
        $perpetualGrowth = max(0, $wacc - 0.01);
    }
    
    // Calculate Terminal Value at the end of the last forecast year
    $terminalValue = $terminalFcff * (1 + $perpetualGrowth) / ($wacc - $perpetualGrowth);
    
    // Discount TV to present value
    // TV is at the end of the last forecast period (same moment as last P period)
    // If we have 5 periods (P1-P5), P5 is discounted at (4 + stubFraction)
    // TV should be discounted at the same moment: (count - 1 + stubFraction)
    $terminalPeriod = (count($forecastLabels) - 1) + $stubFraction;
    $terminalDf = 1 / pow(1 + $wacc, $terminalPeriod);
    $terminalPv = $terminalValue * $terminalDf;
    $discountFactors['TV'] = $terminalDf;
    $discountedCf['TV'] = $terminalPv;

    $debt = $balanceSeries['Кредиты и займы'][$lastFactLabel] ?? 0;
    $cash = $balanceSeries['Денежные средства'][$lastFactLabel] ?? 0;
    $enterpriseValue = $pvSum + $terminalPv;
    $equityValue = $enterpriseValue - $debt + $cash;

    $buildValues = function (array $factValues, array $forecastValues, $tvValue = null) use ($factYears, $forecastLabels) {
        $values = [];
        foreach ($factYears as $year) {
            $values[$year] = $factValues[$year] ?? null;
        }
        foreach ($forecastLabels as $label) {
            $values[$label] = $forecastValues[$label] ?? null;
        }
        $values['TV'] = $tvValue;
        return $values;
    };

    $nullFact = array_fill_keys($factYears, null);
    $nullForecast = array_fill_keys($forecastLabels, null);

    $forecastGrowthAssoc = array_combine($forecastLabels, $forecastGrowth);
    $factTax = [];
    foreach ($factYears as $year) {
        $factTax[$year] = max(0, $factData['ebit'][$year] ?? 0) * $defaults['tax_rate'];
    }

    $rows = [
        [
            'label' => 'Выручка',
            'format' => 'money',
            'is_expense' => false,
            'values' => $buildValues($factData['revenue'], $forecastRevenue),
        ],
        [
            'label' => 'Темп роста, %',
            'format' => 'percent',
            'italic' => true,
            'values' => $buildValues(
                $factGrowth + [$factYears[0] => null],
                $forecastGrowthAssoc
            ),
        ],
        [
            'label' => 'Себестоимость',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['cogs'], $forecastCogs),
        ],
        [
            'label' => 'Коммерческие',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['commercial'], $forecastCommercial),
        ],
        [
            'label' => 'Административные',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['admin'], $adminForecast),
        ],
        [
            'label' => 'EBITDA',
            'format' => 'money',
            'is_expense' => false,
            'values' => $buildValues($factData['ebitda'], $ebitdaForecast),
        ],
        [
            'label' => 'EBITDA-маржа, %',
            'format' => 'percent',
            'italic' => true,
            'values' => $buildValues($factData['margin'], $ebitdaMarginForecast),
        ],
        [
            'label' => 'Налог (от EBIT)',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factTax, $taxForecast),
        ],
        [
            'label' => 'Поддерживающий CAPEX',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($nullFact, $supportCapex),
        ],
        [
            'label' => 'ΔNWC',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($nullFact, $deltaNwcForecast),
        ],
        [
            'label' => 'FCFF',
            'format' => 'money',
            'is_expense' => false,
            'star_columns' => [$forecastLabels[0]],
            'values' => $buildValues($nullFact, $fcffDisplay, null), // TV не относится к FCFF, это отдельный показатель
        ],
        [
            'label' => 'Фактор дисконтирования',
            'format' => 'decimal',
            'values' => $buildValues($nullFact, $discountFactors, $discountFactors['TV']),
        ],
        [
            'label' => 'Discounted FCFF',
            'format' => 'money',
            'is_expense' => false,
            'values' => $buildValues($nullFact, $discountedCf, $terminalPv),
        ],
    ];

    $warnings = [];
    if ($forecastGrowth[0] <= $gLastFact) {
        $warnings[] = 'P1 скорректирован, чтобы быть выше фактического темпа 2024 года.';
    }
    if (abs($forecastGrowth[0] - $gLastFact) > 0.10) {
        $warnings[] = 'Отклонение P1 от g_last_fact ограничено 10 п.п. согласно регламенту.';
    }

    return [
        'columns' => $columns,
        'rows' => $rows,
        'wacc' => $defaults['wacc'],
        'perpetual_growth' => $defaults['perpetual_growth'],
        'footnotes' => ['* FCFF₁ скорректирован на оставшуюся часть года'],
        'warnings' => $warnings,
        'ev_breakdown' => [
            'ev' => $enterpriseValue,
            'debt' => $debt,
            'cash' => $cash,
            'equity' => $equityValue,
            'terminal_value' => $terminalValue,
            'terminal_pv' => $terminalPv,
            'discounted_sum' => $pvSum,
        ],
    ];
}

$latestForm = null;
$dcfData = null;
$dcfSourceStatus = null;

$latestSubmittedStmt = $pdo->prepare("
    SELECT *
    FROM seller_forms
    WHERE user_id = ?
      AND status IN ('submitted','review','approved')
    ORDER BY submitted_at DESC, updated_at DESC
    LIMIT 1
");
$latestSubmittedStmt->execute([$user['id']]);
$latestForm = $latestSubmittedStmt->fetch();

if ($latestForm) {
    $dcfSourceStatus = $latestForm['status'];
    $dcfData = calculateUserDCF($latestForm);
} else {
    $latestAnyStmt = $pdo->prepare("
        SELECT *
        FROM seller_forms
        WHERE user_id = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $latestAnyStmt->execute([$user['id']]);
    $latestForm = $latestAnyStmt->fetch();
    if ($latestForm) {
        $dcfSourceStatus = $latestForm['status'] ?? null;
        if (in_array($dcfSourceStatus, ['submitted','review','approved'], true)) {
            $dcfData = calculateUserDCF($latestForm);
        } else {
            $dcfData = ['error' => 'DCF рассчитывается только по отправленным анкетам. Отправьте анкету, чтобы увидеть модель.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - SmartBizSell.ru</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            padding: 40px 0;
            color: white;
            margin-bottom: 40px;
            margin-top: 80px;
        }
        .dashboard-header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .dashboard-header h1 {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .dashboard-header p {
            opacity: 0.9;
            font-size: 16px;
        }
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .dashboard-actions {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: white;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }
        .btn-secondary:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .forms-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 18px;
        }
        .table-row {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 16px;
            align-items: center;
            transition: background 0.2s ease;
        }
        .table-row:hover {
            background: var(--bg-secondary);
        }
        .table-row:last-child {
            border-bottom: none;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        @media (max-width: 768px) {
            .table-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
            .dcf-card {
                padding: 16px;
                margin-top: 24px;
            }
            .dcf-card h2 {
                font-size: 20px;
            }
            .dcf-card__actions {
                flex-direction: column;
                gap: 8px;
            }
            .dcf-card__actions .btn {
                width: 100%;
            }
            .dcf-params-strip {
                flex-direction: column;
                gap: 8px;
            }
            /* Обертка для горизонтальной прокрутки таблиц DCF */
            .dcf-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -16px;
                padding: 0 16px;
            }
            .dcf-table {
                min-width: 600px;
                font-size: 12px;
            }
            .dcf-table th,
            .dcf-table td {
                padding: 8px 6px;
                white-space: nowrap;
            }
            .dcf-table--full th:first-child {
                width: 140px;
                min-width: 140px;
                position: sticky;
                left: 0;
                z-index: 10;
                background: rgba(245,247,250,0.98);
                box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            }
            .dcf-table--full td:first-child {
                position: sticky;
                left: 0;
                z-index: 9;
                background: white;
                box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            }
            .dcf-table--full tr:nth-child(even) td:first-child {
                background: rgba(248,250,252,0.98);
            }
            .dcf-table--ev {
                font-size: 12px;
            }
            .dcf-table--ev td {
                padding: 4px 6px;
            }
        }
        .dcf-card {
            margin-top: 48px;
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }
        .dcf-card h2 {
            margin-top: 0;
            font-size: 24px;
            margin-bottom: 16px;
        }
        .dcf-card__actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .btn-export-pdf {
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            background: white;
        }
        .btn-export-pdf:hover {
            background: rgba(102, 126, 234, 0.05);
        }
        .dcf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .dcf-table th,
        .dcf-table td {
            border: 1px solid var(--border-color);
            padding: 12px;
            text-align: left;
        }
        .dcf-table th {
            background: rgba(245,247,250,0.8);
            font-weight: 600;
        }
        .dcf-table--full th:first-child {
            width: 220px;
        }
        .dcf-table--full td {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .dcf-table--full td:first-child {
            text-align: left;
            font-weight: 500;
            color: var(--text-primary);
        }
        .dcf-col-fact {
            background: rgba(248,250,252,0.6);
        }
        .dcf-col-forecast {
            background: rgba(255,255,255,0.8);
        }
        .dcf-col-tv {
            background: rgba(20,184,166,0.08);
        }
        .dcf-cell-tv {
            font-weight: 600;
        }
        .dcf-params-strip {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .dcf-footnote {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: -12px;
            margin-bottom: 16px;
        }
        .dcf-table--ev {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 0;
        }
        .dcf-table--ev td {
            border: none;
            padding: 6px 12px;
            text-align: right;
        }
        .dcf-table--ev td:first-child {
            text-align: left;
            font-weight: 500;
            color: var(--text-primary);
        }
        @media print {
            body.print-dcf * {
                visibility: hidden !important;
            }
            body.print-dcf #dcf-card,
            body.print-dcf #dcf-card * {
                visibility: visible !important;
            }
            body.print-dcf #dcf-card {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
            }
        }
        .dcf-source-note {
            font-size: 13px;
            color: var(--text-secondary);
            margin: -8px 0 16px;
        }
        .dcf-source-note strong {
            color: var(--text-primary);
        }
        .dcf-source-note--warning {
            color: #ad6800;
        }
        .dcf-print-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .warnings {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            background: #fff7e6;
            border: 1px solid #ffe2a8;
            color: #ad6800;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon">🚀</span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="index.php">Главная</a></li>
                    <li><a href="index.php#buy-business">Купить бизнес</a></li>
                    <li><a href="seller_form.php">Продать бизнес</a></li>
                    <li><a href="dashboard.php">Личный кабинет</a></li>
                    <li><a href="logout.php">Выйти</a></li>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <div class="dashboard-header">
        <div class="dashboard-header-content">
            <h1>Личный кабинет</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>!</p>
        </div>
    </div>

    <div class="dashboard-container">
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                <strong>✓ Анкета успешно отправлена!</strong> Команда SmartBizSell изучит информацию и свяжется с вами.
            </div>
        <?php endif; ?>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($forms); ?></div>
                <div class="stat-label">Всего анкет</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'submitted' || $f['status'] === 'review')); ?></div>
                <div class="stat-label">На проверке</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'approved')); ?></div>
                <div class="stat-label">Одобрено</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'draft')); ?></div>
                <div class="stat-label">Черновиков</div>
            </div>
        </div>

        <div class="dashboard-actions">
            <a href="seller_form.php" class="btn btn-primary">+ Создать новую анкету</a>
            <a href="profile.php" class="btn btn-secondary">Настройки профиля</a>
        </div>

        <div class="forms-table">
            <div class="table-header">Мои анкеты</div>
            
            <?php if (empty($activeForms)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>У вас пока нет отправленных анкет</h3>
                    <p>Заполните и отправьте анкету, чтобы получить материалы по сделке</p>
                    <a href="seller_form.php" class="btn btn-primary" style="margin-top: 20px;">Создать анкету</a>
                </div>
            <?php else: ?>
                <div style="padding: 0;">
                    <?php foreach ($activeForms as $form): ?>
                        <div class="table-row">
                            <div>
                                <strong><?php echo htmlspecialchars($form['asset_name'] ?: 'Без названия', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                                    Создана: <?php echo date('d.m.Y H:i', strtotime($form['created_at'])); ?>
                                </div>
                            </div>
                            <div>
                                <span class="status-badge" style="background: <?php echo $statusColors[$form['status']]; ?>; color: white;">
                                    <?php echo $statusLabels[$form['status']]; ?>
                                </span>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: var(--text-secondary);">Обновлена:</div>
                                <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['updated_at'])); ?></div>
                            </div>
                            <div>
                                <?php if ($form['submitted_at']): ?>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Отправлена:</div>
                                    <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['submitted_at'])); ?></div>
                                <?php else: ?>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Не отправлена</div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a href="view_form.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">Просмотр</a>
                                <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">Редактировать</a>
                                <a href="export_form_json.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">📥 JSON</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($draftForms)): ?>
        <div class="forms-table" style="margin-top: 32px;">
            <div class="table-header">Черновики</div>
            <div style="padding: 0;">
                <?php foreach ($draftForms as $form): ?>
                    <div class="table-row">
                    <div>
                        <strong><?php echo htmlspecialchars($form['asset_name'] ?: 'Черновик без названия', ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                            Обновлён: <?php echo date('d.m.Y H:i', strtotime($form['updated_at'])); ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge" style="background: <?php echo $statusColors['draft']; ?>; color: white;">
                            <?php echo $statusLabels['draft']; ?>
                        </span>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary);">Создан:</div>
                        <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['created_at'])); ?></div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">
                            Продолжить заполнение
                        </a>
                        <a href="export_form_json.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">📥 JSON</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($dcfData): ?>
            <div class="dcf-card" id="dcf-card">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin-bottom:4px;">DCF Model</h2>
                        <small class="dcf-print-hint">Сохраните PDF через системный диалог печати (⌘+P / Ctrl+P).</small>
                    </div>
                    <button
                        type="button"
                        class="btn btn-export-pdf"
                        id="export-dcf-pdf"
                        data-asset-name="<?php echo htmlspecialchars($latestForm['asset_name'] ?? 'DCF', ENT_QUOTES, 'UTF-8'); ?>"
                        data-date-label="<?php echo isset($latestForm['submitted_at']) ? date('d.m.Y', strtotime($latestForm['submitted_at'])) : date('d.m.Y'); ?>"
                    >
                        Сохранить DCF в PDF
                    </button>
                </div>
                <?php if ($latestForm): ?>
                    <?php
                        $dcfAssetName = $latestForm['asset_name'] ?: 'Без названия';
                        $dcfDate = null;
                        if (!empty($latestForm['submitted_at'])) {
                            $dcfDate = date('d.m.Y', strtotime($latestForm['submitted_at']));
                        } elseif (!empty($latestForm['updated_at'])) {
                            $dcfDate = date('d.m.Y', strtotime($latestForm['updated_at']));
                        }
                        $dcfStatusLabel = $statusLabels[$dcfSourceStatus] ?? $dcfSourceStatus ?? 'Черновик';
                        $noteClasses = 'dcf-source-note';
                        if (!in_array($dcfSourceStatus, ['submitted','review','approved'], true)) {
                            $noteClasses .= ' dcf-source-note--warning';
                        }
                    ?>
                    <p class="<?php echo $noteClasses; ?>">
                        <?php if (in_array($dcfSourceStatus, ['submitted','review','approved'], true)): ?>
                            Расчёт построен по анкете «<?php echo htmlspecialchars($dcfAssetName, ENT_QUOTES, 'UTF-8'); ?>»
                            <?php if ($dcfDate): ?>от <?php echo $dcfDate; ?><?php endif; ?>
                            (статус: <?php echo htmlspecialchars($dcfStatusLabel, ENT_QUOTES, 'UTF-8'); ?>).
                        <?php else: ?>
                            Последняя анкета «<?php echo htmlspecialchars($dcfAssetName, ENT_QUOTES, 'UTF-8'); ?>»
                            имеет статус «<?php echo htmlspecialchars($dcfStatusLabel, ENT_QUOTES, 'UTF-8'); ?>». Отправьте анкету, чтобы рассчитать модель.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (isset($dcfData['error'])): ?>
                    <div class="warnings"><?php echo htmlspecialchars($dcfData['error'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <?php
                        $columnsMeta = $dcfData['columns'] ?? [];
                        $rows = $dcfData['rows'] ?? [];
                        $evData = $dcfData['ev_breakdown'] ?? null;
                        $formatMoney = static function ($value, bool $isExpense = false): string {
                            if ($value === null) {
                                return '—';
                            }
                            $rounded = round($value);
                            $formatted = number_format(abs($rounded), 0, '.', ' ');
                            if ($isExpense && $rounded > 0) {
                                return '(' . $formatted . ')';
                            }
                            if ($isExpense && $rounded < 0) {
                                return '−(' . $formatted . ')';
                            }
                            return ($rounded < 0 ? '−' : '') . $formatted;
                        };
                        $formatPercent = static function ($value, bool $italic = false): string {
                            if ($value === null) {
                                return '—';
                            }
                            $formatted = number_format($value * 100, 2, '.', ' ') . '%';
                            return $italic ? '<em>' . $formatted . '</em>' : $formatted;
                        };
                        $formatDecimal = static function ($value): string {
                            if ($value === null) {
                                return '—';
                            }
                            return number_format($value, 4, '.', ' ');
                        };
                        $formatEvRow = static function ($value) use ($formatMoney): string {
                            if ($value === null) {
                                return '—';
                            }
                            return $formatMoney($value) . ' млн ₽';
                        };
                    ?>
                    <div class="dcf-params-strip">
                        <span>WACC: <?php echo number_format(($dcfData['wacc'] ?? 0) * 100, 2, '.', ' '); ?>%</span>
                        <span>g: <?php echo number_format(($dcfData['perpetual_growth'] ?? 0) * 100, 2, '.', ' '); ?>%</span>
                    </div>
                    <div class="dcf-table-wrapper">
                    <table class="dcf-table dcf-table--full">
                        <thead>
                            <tr>
                                <th>Показатель</th>
                                <?php foreach ($columnsMeta as $column): ?>
                                    <th class="dcf-col-<?php echo htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php foreach ($columnsMeta as $column): ?>
                                        <?php
                                            $value = $row['values'][$column['key']] ?? null;
                                            $formattedValue = '—';
                                            if ($row['format'] === 'money') {
                                                $formattedValue = $formatMoney($value, $row['is_expense'] ?? false);
                                            } elseif ($row['format'] === 'percent') {
                                                $formattedValue = $formatPercent($value, $row['italic'] ?? false);
                                            } elseif ($row['format'] === 'decimal') {
                                                $formattedValue = $formatDecimal($value);
                                            } else {
                                                $formattedValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                                            }
                                            if (!empty($row['star_columns']) && in_array($column['key'], $row['star_columns'], true) && $formattedValue !== '—') {
                                                $formattedValue .= '*';
                                            }
                                        ?>
                                        <td class="dcf-cell-<?php echo htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $formattedValue; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if (!empty($dcfData['footnotes'])): ?>
                        <p class="dcf-footnote">
                            <?php foreach ($dcfData['footnotes'] as $note): ?>
                                <?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?><br>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($evData): ?>
                        <div class="dcf-table-wrapper">
                        <table class="dcf-table dcf-table--ev">
                            <tbody>
                                <tr>
                                    <td>Enterprise Value (EV)</td>
                                    <td><?php echo $formatEvRow($evData['ev'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td>− Debt</td>
                                    <td>(<?php echo $formatMoney($evData['debt'] ?? 0); ?> млн ₽)</td>
                                </tr>
                                <tr>
                                    <td>Cash</td>
                                    <td><?php echo $formatEvRow($evData['cash'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Equity Value</strong></td>
                                    <td><strong><?php echo $formatEvRow($evData['equity'] ?? null); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($dcfData['warnings'])): ?>
                        <div class="warnings">
                            <strong>Контрольные замечания:</strong>
                            <ul>
                                <?php foreach ($dcfData['warnings'] as $warning): ?>
                                    <li><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const card = document.getElementById('dcf-card');
            const exportBtn = document.getElementById('export-dcf-pdf');
            if (!card || !exportBtn) {
                return;
            }

            const originalText = exportBtn.textContent;

            const restoreState = () => {
                document.body.classList.remove('print-dcf');
                exportBtn.disabled = false;
                exportBtn.textContent = originalText;
            };

            const handleAfterPrint = () => {
                restoreState();
                window.removeEventListener('afterprint', handleAfterPrint);
            };

            exportBtn.addEventListener('click', () => {
                document.body.classList.add('print-dcf');
                exportBtn.disabled = true;
                exportBtn.textContent = 'Открывается диалог...';

                window.addEventListener('afterprint', handleAfterPrint);

                setTimeout(() => {
                    window.print();
                    // На некоторых iOS/Safari события afterprint нет — возвращаем состояние сами
                    setTimeout(restoreState, 1000);
                }, 50);
            });
        });
    </script>
    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

