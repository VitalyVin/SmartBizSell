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

function calculateUserDCF(array $form): array {
    $defaults = [
        'wacc' => 0.10,
        'tax_rate' => 0.20,
        'perpetual_growth' => 0.03,
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
        return ['error' => 'Недостаточно данных анкеты для построения DCF.'];
    }

    $finRows = dcf_rows_by_metric($financial);
    $balRows = dcf_rows_by_metric($balance);

    if (!isset($finRows['Выручка'], $finRows['Себестоимость продаж'])) {
        return ['error' => 'Отсутствуют ключевые строки финансовой таблицы.'];
    }

    $vatMode = $form['financial_results_vat'] ?? 'without_vat';
    $vatFactor = ($vatMode === 'with_vat' || $vatMode === 'с НДС') ? 1 / 1.20 : 1.0;

    $revenue = dcf_build_series($finRows['Выручка'], $periodMap);
    $cogs    = dcf_build_series($finRows['Себестоимость продаж'] ?? [], $periodMap);
    $commercial = dcf_build_series($finRows['Коммерческие расходы'] ?? [], $periodMap);
    $admin      = dcf_build_series($finRows['Управленческие расходы'] ?? [], $periodMap);
    $deprRow    = dcf_build_series($finRows['Амортизация'] ?? [], $periodMap);
    $capexRow   = dcf_build_series($finRows['Приобретение основных средств'] ?? [], $periodMap);

    foreach ($revenue as $key => $value) {
        $revenue[$key]   = $value * $vatFactor;
        $cogs[$key]      = ($cogs[$key] ?? 0) * $vatFactor;
        $commercial[$key]= ($commercial[$key] ?? 0) * $vatFactor;
        $admin[$key]     = ($admin[$key] ?? 0) * $vatFactor;
        $deprRow[$key]   = ($deprRow[$key] ?? 0) * $vatFactor;
        $capexRow[$key]  = ($capexRow[$key] ?? 0) * $vatFactor;
    }

    $ebitda = [];
    $ebit   = [];
    foreach ($revenue as $key => $value) {
        $ebitda[$key] = $value - ($cogs[$key] ?? 0) - ($commercial[$key] ?? 0) - ($admin[$key] ?? 0);
        $ebit[$key]   = $ebitda[$key] - ($deprRow[$key] ?? 0);
    }

    $balanceSeries = [];
    foreach ($balRows as $metric => $row) {
        $balanceSeries[$metric] = dcf_build_series($row, $periodMap);
    }

    $nwc = [];
    foreach ($periodMap as $label) {
        $inv = $balanceSeries['Запасы'][$label] ?? 0;
        $ar  = $balanceSeries['Дебиторская задолженность'][$label] ?? 0;
        $ap  = $balanceSeries['Кредиторская задолженность'][$label] ?? 0;
        $nwc[$label] = $inv + $ar - $ap;
    }

    $lastFactLabel = '2024';
    $rev2024 = $revenue[$lastFactLabel] ?? 0;
    $nwcRatio = ($rev2024 > 0) ? ($nwc[$lastFactLabel] ?? 0) / $rev2024 : 0.1;

    $growth23 = ($revenue['2022'] ?? 0) > 0 ? ($revenue['2023'] - $revenue['2022']) / max($revenue['2022'], 1e-6) : 0;
    $growth24 = ($revenue['2023'] ?? 0) > 0 ? ($revenue['2024'] - $revenue['2023']) / max($revenue['2023'], 1e-6) : 0;
    $gAvg = ($growth23 + $growth24) / 2;

    $revenue2025Annual = 0;
    if (($revenue['9M2025'] ?? 0) > 0) {
        $revenue2025Annual = ($revenue['9M2025'] / 9) * 12;
    }
    if (($revenue['2025'] ?? 0) > 0) {
        $revenue2025Annual = ($revenue2025Annual + $revenue['2025']) / 2;
    }
    $gLastFact = ($revenue['2024'] ?? 0) > 0 ? ($revenue2025Annual - $revenue['2024']) / max($revenue['2024'], 1e-6) : 0;

    $warnings = [];
    if ($gAvg <= $gLastFact) {
        $warnings[] = 'Рост первого прогнозного периода не превышает последний фактический рост.';
    }
    if (abs($gAvg - $gLastFact) > 0.20) {
        $warnings[] = 'Отклонение P1 от g_last_fact превышает 20 п.п.';
    }

    $adminPositive = (($admin['2022'] ?? 0) > 0) || (($admin['2023'] ?? 0) > 0) || (($admin['2024'] ?? 0) > 0);
    if ($adminPositive) {
        $warnings[] = 'Рост маржи EBITDA ограничен из-за наличия управленческих расходов.';
    }

    $ebitdaMargin2024 = ($revenue['2024'] ?? 0) > 0 ? ($ebitda['2024'] ?? 0) / max($revenue['2024'], 1e-6) : 0.2;
    $depRatio2024     = ($revenue['2024'] ?? 0) > 0 ? ($deprRow['2024'] ?? 0) / max($revenue['2024'], 1e-6) : 0.05;
    $capexRatio2025   = ($revenue['2025'] ?? 0) > 0 ? ($capexRow['2025'] ?? 0) / max($revenue['2025'], 1e-6) : 0.05;

    $projYears = [2027, 2028, 2029, 2030, 2031];
    $stubFraction = 10.5 / 12;
    $projData = [];
    $prevRevenue = $revenue2025Annual > 0 ? $revenue2025Annual : ($revenue['2026'] ?? 0);
    $prevNwc = $nwc['2026'] ?? ($nwc[$lastFactLabel] ?? 0);

    foreach ($projYears as $index => $year) {
        $growth = $index === 0 ? $gAvg : max(min($projData[$projYears[$index - 1]]['growth'] ?? $gAvg, 0.30), 0.01);
        $yearRevenue = $prevRevenue * (1 + $growth);
        $yearEBITDA  = $yearRevenue * $ebitdaMargin2024;
        if ($adminPositive) {
            $yearEBITDA = min($yearEBITDA, $ebitda['2024'] ?? $yearEBITDA);
        }
        $yearDEP  = $yearRevenue * $depRatio2024;
        $yearEBIT = $yearEBITDA - $yearDEP;
        $yearTax  = max(0, $yearEBIT * $defaults['tax_rate']);
        $yearCapex= $yearRevenue * $capexRatio2025;
        $yearNwc  = $yearRevenue * $nwcRatio;
        $deltaNwc = $yearNwc - $prevNwc;
        $yearFCF  = $yearEBITDA - $yearTax + $yearDEP - $yearCapex - $deltaNwc;

        $projData[$year] = [
            'revenue' => $yearRevenue,
            'ebitda'  => $yearEBITDA,
            'ebit'    => $yearEBIT,
            'tax'     => $yearTax,
            'dep'     => $yearDEP,
            'capex'   => $yearCapex,
            'nwc'     => $yearNwc,
            'delta_nwc' => $deltaNwc,
            'fcf'     => $yearFCF,
            'growth'  => $growth,
        ];

        $prevRevenue = $yearRevenue;
        $prevNwc = $yearNwc;
    }

    $discounted = [];
    $pvSum = 0;
    foreach ($projYears as $idx => $year) {
        $t = ($year - 2025) + 0.5;
        if ($idx === 0) {
            $t -= $stubFraction;
        }
        $df = pow(1 + $defaults['wacc'], $t);
        $pv = $projData[$year]['fcf'] / $df;
        $discounted[$year] = [
            'fcf' => $projData[$year]['fcf'],
            'pv'  => $pv,
        ];
        $pvSum += $pv;
    }

    $terminalFCF = end($projData)['fcf'] * (1 + $defaults['perpetual_growth']);
    $terminalValue = $terminalFCF / ($defaults['wacc'] - $defaults['perpetual_growth']);
    $terminalDF = pow(1 + $defaults['wacc'], count($projYears) + 0.5);
    $terminalPV = $terminalValue / $terminalDF;

    $enterpriseValue = $pvSum + $terminalPV;
    $debt = $balanceSeries['Кредиты и займы']['2026'] ?? 0;
    $cash = $balanceSeries['Денежные средства']['2026'] ?? 0;
    $equityValue = $enterpriseValue - $debt + $cash;

    $osDynamics = [];
    $prevOS = $balanceSeries['Основные средства']['2026'] ?? 0;
    foreach ($projYears as $year) {
        $currOS = $prevOS + $projData[$year]['capex'] - $projData[$year]['dep'];
        $osDynamics[] = [
            'year' => $year,
            'os'   => $currOS,
            'capex'=> $projData[$year]['capex'],
            'dep'  => $projData[$year]['dep'],
        ];
        $prevOS = $currOS;
    }

    return [
        'discounted' => $discounted,
        'terminal_value' => $terminalValue,
        'terminal_pv'    => $terminalPV,
        'enterprise_value' => $enterpriseValue,
        'debt' => $debt,
        'cash' => $cash,
        'equity' => $equityValue,
        'warnings' => $warnings,
        'os_dynamics' => $osDynamics,
    ];
}

$latestFormStmt = $pdo->prepare("
    SELECT *
    FROM seller_forms
    WHERE user_id = ?
    ORDER BY submitted_at DESC, updated_at DESC
    LIMIT 1
");
$latestFormStmt->execute([$user['id']]);
$latestForm = $latestFormStmt->fetch();
$dcfData = null;
if ($latestForm) {
    $dcfData = calculateUserDCF($latestForm);
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
                    <div>
                        <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">
                            Продолжить заполнение
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($dcfData): ?>
            <div class="dcf-card">
                <h2>DCF Model</h2>
                <?php if (isset($dcfData['error'])): ?>
                    <div class="warnings"><?php echo htmlspecialchars($dcfData['error'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <table class="dcf-table">
                        <thead>
                            <tr>
                                <th>Год</th>
                                <th>FCF (млн ₽)</th>
                                <th>PV FCF (млн ₽)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dcfData['discounted'] as $year => $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo number_format($row['fcf'], 2, '.', ' '); ?></td>
                                    <td><?php echo number_format($row['pv'], 2, '.', ' '); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td>Terminal Value</td>
                                <td><?php echo number_format($dcfData['terminal_value'], 2, '.', ' '); ?></td>
                                <td><?php echo number_format($dcfData['terminal_pv'], 2, '.', ' '); ?></td>
                            </tr>
                            <tr>
                                <td colspan="2"><strong>Enterprise Value (EV)</strong></td>
                                <td><strong><?php echo number_format($dcfData['enterprise_value'], 2, '.', ' '); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="dcf-table dcf-table--params">
                        <tbody>
                            <tr>
                <th>Ставка дисконтирования (WACC)</th>
                <td>10.00%</td>
            </tr>
            <tr>
                <th>Темп долгосрочного роста (g)</th>
                <td>2.50%</td>
            </tr>
            <tr>
                <th>Период прогноза</th>
                <td>5 лет (2027–2031) + Terminal Value</td>
            </tr>
            <tr>
                <th>Enterprise Value (EV)</th>
                <td><?php echo number_format($dcfData['enterprise_value'], 2, '.', ' '); ?> млн ₽</td>
            </tr>
            <tr>
                <th>Чистый долг</th>
                <td><?php echo number_format(max(($dcfData['debt'] ?? 0) - ($dcfData['cash'] ?? 0), 0), 2, '.', ' '); ?> млн ₽</td>
            </tr>
            <tr>
                <th>Equity Value</th>
                <td><?php echo number_format($dcfData['enterprise_value'] - (($dcfData['debt'] ?? 0) - ($dcfData['cash'] ?? 0)), 2, '.', ' '); ?> млн ₽</td>
            </tr>
                        </tbody>
                    </table>

                    <h3>Динамика основных средств</h3>
                    <table class="dcf-table">
                        <thead>
                            <tr>
                                <th>Год</th>
                                <th>Основные средства</th>
                                <th>CAPEX</th>
                                <th>Амортизация</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dcfData['os_dynamics'] as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['year'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo number_format($row['os'], 2, '.', ' '); ?></td>
                                    <td><?php echo number_format($row['capex'], 2, '.', ' '); ?></td>
                                    <td><?php echo number_format($row['dep'], 2, '.', ' '); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

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

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

