<?php
/**
 * Страница просмотра анкеты продавца
 * 
 * Функциональность:
 * - Отображение полной информации об анкете продавца
 * - Поддержка как нового формата (data_json), так и старого (отдельные поля БД)
 * - Отображение всех секций анкеты: детали сделки, описание бизнеса, производство, финансы, баланс
 * - Возможность редактирования и экспорта анкеты
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверка авторизации - доступ только для авторизованных пользователей
if (!isLoggedIn()) {
    redirectToLogin();
}

// Получение данных текущего пользователя
$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

// Получение ID анкеты из GET-параметра
$formId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($formId <= 0) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Загружаем анкету пользователя из базы данных
 * Проверяем права доступа: модераторы могут просматривать любые анкеты,
 * обычные пользователи - только свои
 */
$pdo = getDBConnection();
if (isModerator()) {
    // Модераторы могут просматривать любые анкеты
    $stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ?");
    $stmt->execute([$formId]);
} else {
    // Обычные пользователи - только свои анкеты
    $stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$formId, $user['id']]);
}
$form = $stmt->fetch();

if (!$form) {
    header('Location: dashboard.php');
    exit;
}

/**
 * Приводит данные формы к единому виду для отображения
 * 
 * Приоритет источников данных:
 * 1. data_json (новый формат) - если есть, используется полностью
 * 2. Отдельные поля БД (старый формат) - маппинг старых названий колонок на новые
 * 3. JSON-поля для таблиц (production_volumes, financial_results, balance_indicators)
 * 
 * Обеспечивает обратную совместимость со старыми формами, сохраненными до внедрения data_json
 * 
 * @param array $form Данные формы из базы данных
 * @return array Унифицированные данные формы для отображения
 */
function buildViewData(array $form): array
{
    // Приоритет: data_json содержит полную структуру в новом формате
    // Это основной источник данных для новых форм
    if (!empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    // Fallback: маппинг старых названий колонок БД на новые названия полей
    // Обеспечивает совместимость со старыми формами, сохраненными до внедрения data_json
    $mapping = [
        'asset_name' => 'asset_name',
        'deal_share_range' => 'deal_subject',  // Старое название колонки: deal_subject
        'deal_goal' => 'deal_purpose',          // Старое название колонки: deal_purpose
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
        'production_building_ownership' => 'production_building_ownership',
        'production_land_ownership' => 'production_land_ownership',
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

    // Собираем данные из отдельных полей БД
    $data = [];
    foreach ($mapping as $field => $column) {
        $data[$field] = $form[$column] ?? '';
    }

    // Восстанавливаем таблицы из JSON-полей (для старых форм)
    // production_volumes, financial_results, balance_indicators - старые названия JSON-колонок
    $data['production'] = !empty($form['production_volumes']) ? (json_decode($form['production_volumes'], true) ?: []) : [];
    $data['financial']  = !empty($form['financial_results']) ? (json_decode($form['financial_results'], true) ?: []) : [];
    $data['balance']    = !empty($form['balance_indicators']) ? (json_decode($form['balance_indicators'], true) ?: []) : [];

    return $data;
}

// Определяем тип компании
$companyType = $form['company_type'] ?? null;

// Строим унифицированные данные для отображения
$formData = buildViewData($form);

// Извлекаем таблицы для удобного отображения
$productionRows = is_array($formData['production'] ?? null) ? $formData['production'] : [];
$financialRows  = is_array($formData['financial'] ?? null) ? $formData['financial'] : [];
$balanceRows    = is_array($formData['balance'] ?? null) ? $formData['balance'] : [];

/**
 * Маппинг статусов анкет для отображения
 * Каждый статус имеет текстовое название для пользователя
 */
$statusLabels = [
    'draft' => 'Черновик',
    'submitted' => 'Отправлена',
    'review' => 'На проверке',
    'approved' => 'Одобрена',
    'rejected' => 'Отклонена'
];

/**
 * Цвета статусов для визуального отображения
 * Используются для создания цветных индикаторов статуса
 */
$statusColors = [
    'draft' => '#86868B',      // Серый
    'submitted' => '#007AFF',  // Синий
    'review' => '#FF9500',     // Оранжевый
    'approved' => '#34C759',   // Зеленый
    'rejected' => '#FF3B30'    // Красный
];

/**
 * Форматирует значение "да/нет" для отображения
 * 
 * Поддерживает как английские ('yes', 'no'), так и русские ('да', 'нет') значения
 * Используется для отображения булевых полей в читаемом виде
 * 
 * @param string|null $value Значение из базы данных ('yes', 'no', 'да', 'нет')
 * @return string Отформатированное значение для отображения ('Да', 'Нет', '—')
 */
function formatYesNo(?string $value): string
{
    if ($value === 'yes' || $value === 'да') {
        return 'Да';
    }
    if ($value === 'no' || $value === 'нет') {
        return 'Нет';
    }
    return '—';  // Прочерк для пустых или неизвестных значений
}

/**
 * Безопасно извлекает значение из массива с экранированием HTML
 * 
 * Защищает от XSS-атак путем экранирования HTML-сущностей
 * Возвращает значение по умолчанию, если ключ не найден или значение пустое
 * 
 * @param array $data Массив данных
 * @param string $key Ключ для поиска
 * @param string $fallback Значение по умолчанию, если ключ не найден (по умолчанию '—')
 * @return string Экранированное значение или значение по умолчанию
 */
function safeValue(array $data, string $key, string $fallback = '—'): string
{
    $value = $data[$key] ?? '';
    
    // Если значение - массив, возвращаем значение по умолчанию
    // (массивы не могут быть отображены как строка)
    if (is_array($value)) {
        return $fallback;
    }
    
    // Удаляем пробелы и проверяем, не пустое ли значение
    $value = trim((string)$value);
    
    // Если значение пустое, возвращаем значение по умолчанию
    // Иначе экранируем HTML-сущности для защиты от XSS
    return $value === '' ? $fallback : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр анкеты - SmartBizSell</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background: #f5f7fa;
        }
        .view-container {
            max-width: 1200px;
            margin: 100px auto 40px;
            padding: 0 20px;
        }
        .view-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
        }
        .view-card h2 {
            margin-top: 0;
            font-size: 24px;
            margin-bottom: 16px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .info-item {
            background: #f8f9fc;
            border-radius: 12px;
            padding: 16px;
        }
        .info-item label {
            display: block;
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .info-item span {
            font-size: 16px;
            color: var(--text-primary);
            font-weight: 600;
        }
        .table-wrapper {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th,
        table td {
            border: 1px solid var(--border-color);
            padding: 12px;
            text-align: left;
            font-size: 14px;
        }
        table th {
            background: rgba(102, 126, 234, 0.08);
            font-weight: 600;
        }
        .status-pill {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 600;
            color: white;
        }
        .view-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .btn {
            padding: 12px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
        }
        .btn-secondary {
            background: white;
            border: 2px solid var(--border-color);
            color: var(--text-primary);
        }
        .section-title {
            margin-top: 0;
            font-size: 20px;
            margin-bottom: 12px;
        }
        .empty-note {
            font-style: italic;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
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

    <div class="view-container">
        <div class="view-card">
            <div style="display:flex; flex-wrap:wrap; justify-content:space-between; gap:16px; align-items:flex-start;">
                <div>
                    <h1 style="margin:0 0 8px;"><?php echo htmlspecialchars($form['asset_name'] ?: 'Анкета без названия', ENT_QUOTES, 'UTF-8'); ?></h1>
                    <div style="font-size:14px; color:var(--text-secondary);">
                        Создана: <?php echo date('d.m.Y H:i', strtotime($form['created_at'])); ?> ·
                        Обновлена: <?php echo date('d.m.Y H:i', strtotime($form['updated_at'])); ?>
                    </div>
                </div>
                <div>
                    <span class="status-pill" style="background: <?php echo $statusColors[$form['status']] ?? '#86868B'; ?>">
                        <?php echo $statusLabels[$form['status']] ?? ucfirst($form['status']); ?>
                    </span>
                </div>
            </div>
            <div class="view-actions">
                <a class="btn btn-secondary" href="dashboard.php">← Назад в кабинет</a>
                <a class="btn btn-secondary" href="export_form_json.php?id=<?php echo $form['id']; ?>">📥 Скачать JSON</a>
                <a class="btn btn-primary" href="seller_form.php?form_id=<?php echo $form['id']; ?>">Редактировать анкету</a>
            </div>
        </div>

        <div class="view-card">
            <h2>Детали предполагаемой сделки</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Название актива</label>
                    <span><?php echo safeValue($formData, 'asset_name'); ?></span>
                </div>
                <div class="info-item">
                    <label>Предмет сделки</label>
                    <span><?php echo safeValue($formData, 'deal_share_range'); ?></span>
                </div>
                <div class="info-item">
                    <label>Цель сделки</label>
                    <span>
                        <?php
                        $goalMap = [
                            'cash_out' => 'Продажа бизнеса (cash-out)',
                            'cash_in'  => 'Привлечение инвестиций (cash-in)',
                            '' => '—'
                        ];
                        echo htmlspecialchars($goalMap[$formData['deal_goal'] ?? ''] ?? '—', ENT_QUOTES, 'UTF-8');
                        ?>
                    </span>
                </div>
                <div class="info-item">
                    <label>Раскрытие названия </label>
                    <span><?php echo formatYesNo($formData['asset_disclosure'] ?? ''); ?></span>
                </div>
            </div>
        </div>

        <?php if ($companyType === 'startup'): ?>
        <?php $startupData = $formData; ?>

        <div class="view-card">
            <h2>Описание продукта и технологии</h2>
            <div class="info-grid">
                <?php if (!empty($startupData['company_founded_date'] ?? '')): ?>
                <div class="info-item">
                    <label>Дата основания</label>
                    <span><?php echo htmlspecialchars($startupData['company_founded_date'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>

                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Описание продукта / решения</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_product_description'] ?? '—', ENT_QUOTES, 'UTF-8')); ?></span>
                </div>

                <?php if (!empty($startupData['startup_technology_description'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Описание технологии</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_technology_description'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_ip_patents'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Патенты, интеллектуальная собственность</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_ip_patents'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_product_stage'] ?? '')): ?>
                <div class="info-item">
                    <label>Текущая стадия продукта</label>
                    <span>
                        <?php
                        $stages = [
                            'idea' => 'Идея',
                            'prototype' => 'Прототип',
                            'mvp' => 'MVP',
                            'working_product' => 'Рабочий продукт',
                            'scaling' => 'Масштабирование'
                        ];
                        echo htmlspecialchars($stages[$startupData['startup_product_stage']] ?? $startupData['startup_product_stage'], ENT_QUOTES, 'UTF-8');
                        ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-card">
            <h2>Ключевые показатели (traction)</h2>
            <div class="info-grid">
                <?php if (!empty($startupData['startup_users_count'] ?? '')): ?>
                <div class="info-item">
                    <label>Пользователи / клиенты</label>
                    <span><?php echo number_format((float)$startupData['startup_users_count'], 0, ',', ' '); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_mrr'] ?? '')): ?>
                <div class="info-item">
                    <label>MRR, руб.</label>
                    <span><?php echo number_format((float)$startupData['startup_mrr'], 2, ',', ' '); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_dau'] ?? '')): ?>
                <div class="info-item">
                    <label>DAU</label>
                    <span><?php echo number_format((float)$startupData['startup_dau'], 0, ',', ' '); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_mau'] ?? '')): ?>
                <div class="info-item">
                    <label>MAU</label>
                    <span><?php echo number_format((float)$startupData['startup_mau'], 0, ',', ' '); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_registrations'] ?? '')): ?>
                <div class="info-item">
                    <label>Количество регистраций</label>
                    <span><?php echo number_format((float)$startupData['startup_registrations'], 0, ',', ' '); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_conversion_rate'] ?? '')): ?>
                <div class="info-item">
                    <label>Конверсия, %</label>
                    <span><?php echo htmlspecialchars($startupData['startup_conversion_rate'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_retention_rate'] ?? '')): ?>
                <div class="info-item">
                    <label>Удержание, %</label>
                    <span><?php echo htmlspecialchars($startupData['startup_retention_rate'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_pilots_partnerships'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Пилотные проекты / партнерства</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_pilots_partnerships'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-card">
            <h2>Команда</h2>
            <div class="info-grid">
                <?php if (!empty($startupData['startup_shareholders'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Состав акционеров</label>
                    <span><?php echo nl2br(htmlspecialchars(is_array($startupData['startup_shareholders']) ? json_encode($startupData['startup_shareholders'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $startupData['startup_shareholders'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_key_employees'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Ключевые сотрудники</label>
                    <span><?php echo nl2br(htmlspecialchars(is_array($startupData['startup_key_employees']) ? json_encode($startupData['startup_key_employees'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $startupData['startup_key_employees'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['personnel_count'] ?? '')): ?>
                <div class="info-item">
                    <label>Численность команды</label>
                    <span><?php echo htmlspecialchars($startupData['personnel_count'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_social_links'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Ссылки на соцсети</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_social_links'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-card">
            <h2>Рынок и конкурентные преимущества</h2>
            <div class="info-grid">
                <?php if (!empty($startupData['startup_target_market'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Целевой рынок</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_target_market'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_market_size'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Размер рынка (TAM/SAM/SOM)</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_market_size'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_competitors'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Конкуренты и аналоги</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_competitors'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_competitive_advantages'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Преимущества и недостатки существующих продуктов</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_competitive_advantages'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['company_website'] ?? '')): ?>
                <div class="info-item">
                    <label>Сайт / продукт</label>
                    <span><?php echo htmlspecialchars($startupData['company_website'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-card">
            <h2>Дорожная карта и использование инвестиций</h2>
            <div class="info-grid">
                <?php if (!empty($startupData['startup_roadmap'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>План развития (12–24 мес.)</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_roadmap'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_scaling_plans'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Планы по масштабированию</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_scaling_plans'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_funding_usage'] ?? '')): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>На что пойдут средства (cash-in)</label>
                    <span><?php echo nl2br(htmlspecialchars($startupData['startup_funding_usage'], ENT_QUOTES, 'UTF-8')); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="view-card">
            <h2>Финансовые показатели и прогнозы</h2>
            <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
                <div class="info-item">
                    <label>2023: выручка / расходы / прибыль</label>
                    <span><?php
                        $r = $startupData['startup_revenue_2023'] ?? null;
                        $e = $startupData['startup_expenses_2023'] ?? null;
                        $p = $startupData['startup_profit_2023'] ?? null;
                        echo htmlspecialchars(trim(($r ?? '—') . ' / ' . ($e ?? '—') . ' / ' . ($p ?? '—')), ENT_QUOTES, 'UTF-8');
                    ?></span>
                </div>
                <div class="info-item">
                    <label>2024: выручка / расходы / прибыль</label>
                    <span><?php
                        $r = $startupData['startup_revenue_2024'] ?? null;
                        $e = $startupData['startup_expenses_2024'] ?? null;
                        $p = $startupData['startup_profit_2024'] ?? null;
                        echo htmlspecialchars(trim(($r ?? '—') . ' / ' . ($e ?? '—') . ' / ' . ($p ?? '—')), ENT_QUOTES, 'UTF-8');
                    ?></span>
                </div>
                <div class="info-item">
                    <label>2025: выручка / расходы / прибыль</label>
                    <span><?php
                        $r = $startupData['startup_revenue_2025'] ?? null;
                        $e = $startupData['startup_expenses_2025'] ?? null;
                        $p = $startupData['startup_profit_2025'] ?? null;
                        echo htmlspecialchars(trim(($r ?? '—') . ' / ' . ($e ?? '—') . ' / ' . ($p ?? '—')), ENT_QUOTES, 'UTF-8');
                    ?></span>
                </div>
            </div>

            <?php if (!empty($startupData['startup_forecast'] ?? '')): ?>
            <div style="margin-top:20px;">
                <label style="font-size:12px; color:var(--text-secondary); text-transform:uppercase;">Прогнозные показатели (3–5 лет)</label>
                <p style="margin-top:8px;"><?php echo nl2br(htmlspecialchars(is_array($startupData['startup_forecast']) ? json_encode($startupData['startup_forecast'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $startupData['startup_forecast'], ENT_QUOTES, 'UTF-8')); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($startupData['startup_unit_economics'] ?? '')): ?>
            <div style="margin-top:20px;">
                <label style="font-size:12px; color:var(--text-secondary); text-transform:uppercase;">Юнит-экономика</label>
                <p style="margin-top:8px;"><?php echo nl2br(htmlspecialchars(is_array($startupData['startup_unit_economics']) ? json_encode($startupData['startup_unit_economics'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $startupData['startup_unit_economics'], ENT_QUOTES, 'UTF-8')); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <div class="view-card">
            <h2>Инвестиции</h2>
            <div class="info-grid">
                <?php if (!empty($startupData['startup_valuation'] ?? '')): ?>
                <div class="info-item">
                    <label>Текущая оценка компании, руб.</label>
                    <span><?php echo htmlspecialchars($startupData['startup_valuation'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($startupData['startup_investment_amount'] ?? '')): ?>
                <div class="info-item">
                    <label>Требуемая сумма инвестиций, руб.</label>
                    <span><?php echo htmlspecialchars($startupData['startup_investment_amount'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($startupData['startup_previous_investments'] ?? '')): ?>
            <div style="margin-top:20px;">
                <label style="font-size:12px; color:var(--text-secondary); text-transform:uppercase;">Предыдущие инвестиции</label>
                <p style="margin-top:8px;"><?php echo nl2br(htmlspecialchars(is_array($startupData['startup_previous_investments']) ? json_encode($startupData['startup_previous_investments'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $startupData['startup_previous_investments'], ENT_QUOTES, 'UTF-8')); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($companyType === 'mature' || !$companyType): ?>
        <div class="view-card">
            <h2>Описание бизнеса</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Описание деятельности</label>
                    <span><?php echo safeValue($formData, 'company_description'); ?></span>
                </div>
                <div class="info-item">
                    <label>Регионы присутствия</label>
                    <span><?php echo safeValue($formData, 'presence_regions'); ?></span>
                </div>
                <div class="info-item">
                    <label>Продукция / услуги</label>
                    <span><?php echo safeValue($formData, 'products_services'); ?></span>
                </div>
                <div class="info-item">
                    <label>Бренды компании</label>
                    <span><?php echo safeValue($formData, 'company_brands'); ?></span>
                </div>
                <div class="info-item">
                    <label>Основные клиенты</label>
                    <span><?php echo safeValue($formData, 'main_clients'); ?></span>
                </div>
                <div class="info-item">
                    <label>Доля продаж РФ / экспорт</label>
                    <span><?php echo safeValue($formData, 'sales_share'); ?></span>
                </div>
                <div class="info-item">
                    <label>Численность персонала</label>
                    <span><?php echo safeValue($formData, 'personnel_count'); ?></span>
                </div>
                <div class="info-item">
                    <label>Сайт компании</label>
                    <span><?php echo safeValue($formData, 'company_website'); ?></span>
                </div>
            </div>
            <?php if (!empty(trim($formData['additional_info'] ?? ''))): ?>
                <div style="margin-top:20px;">
                    <label style="font-size:12px; color:var(--text-secondary); text-transform:uppercase;">Дополнительная информация</label>
                    <p style="margin-top:8px;"><?php echo nl2br(safeValue($formData, 'additional_info')); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="view-card">
            <h2>Производственные мощности</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Наличие собственного производства</label>
                    <span><?php echo formatYesNo($formData['own_production'] ?? ''); ?></span>
                </div>
                <div class="info-item">
                    <label>Количество площадок</label>
                    <span><?php echo safeValue($formData, 'production_sites_count'); ?></span>
                </div>
                <div class="info-item">
                    <label>Регионы площадок</label>
                    <span><?php echo safeValue($formData, 'production_sites_region'); ?></span>
                </div>
                <div class="info-item">
                    <label>Площадь</label>
                    <span><?php echo safeValue($formData, 'production_area'); ?></span>
                </div>
                <div class="info-item">
                    <label>Мощность</label>
                    <span><?php echo safeValue($formData, 'production_capacity'); ?></span>
                </div>
                <div class="info-item">
                    <label>Текущая загрузка</label>
                    <span><?php echo safeValue($formData, 'production_load'); ?></span>
                </div>
                <div class="info-item">
                    <label>Право собственности (здание)</label>
                    <span><?php echo formatYesNo($formData['production_building_ownership'] ?? ''); ?></span>
                </div>
                <div class="info-item">
                    <label>Право собственности (земля)</label>
                    <span><?php echo formatYesNo($formData['production_land_ownership'] ?? ''); ?></span>
                </div>
            </div>

            <h3 class="section-title" style="margin-top:24px;">Таблица "Объемы производства"</h3>
            <?php if (!empty($productionRows)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Вид продукции</th>
                                <th>Ед. изм.</th>
                                <th>2022 факт</th>
                                <th>2023 факт</th>
                                <th>2024 факт</th>
                                <th>2025 факт</th>
                                <th>2026 бюджет</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productionRows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['product'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['unit'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2022_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2023_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2024_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2025_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2026_budget'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-note">Таблица не заполнена.</p>
            <?php endif; ?>
        </div>

        <div class="view-card">
            <h2>Финансовые показатели</h2>
            <div class="info-grid">
                <div class="info-item">
                    <label>Отчетность</label>
                    <span>
                        <?php
                        $sourceMap = [
                            'rsbu' => 'РСБУ',
                            'ifrs' => 'МСФО',
                            'management' => 'Управленческая',
                        ];
                        echo htmlspecialchars($sourceMap[$formData['financial_source'] ?? ''] ?? '—', ENT_QUOTES, 'UTF-8');
                        ?>
                    </span>
                </div>
                <div class="info-item">
                    <label>Формат НДС</label>
                    <span><?php echo ($formData['financial_results_vat'] ?? '') === 'with_vat' ? 'С НДС' : 'Без НДС'; ?></span>
                </div>
            </div>

            <?php if (!empty($financialRows)): ?>
                <div class="table-wrapper" style="margin-top:20px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Показатель</th>
                                <th>Ед. изм.</th>
                                <th>2022 факт</th>
                                <th>2023 факт</th>
                                <th>2024 факт</th>
                                <th>2025 факт</th>
                                <th>2026 бюджет</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($financialRows as $metric => $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($metric, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['unit'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2022_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2023_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2024_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2025_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2026_budget'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-note">Финансовый блок не заполнен.</p>
            <?php endif; ?>
        </div>

        <div class="view-card">
            <h2>Балансовые показатели</h2>
            <?php if (!empty($balanceRows)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Показатель</th>
                                <th>Ед. изм.</th>
                                <th>31.12.2022</th>
                                <th>31.12.2023</th>
                                <th>31.12.2024</th>
                                <th>31.12.2025</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($balanceRows as $metric => $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($metric, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['unit'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2022_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2023_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2024_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['2025_fact'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-note">Балансовые данные отсутствуют.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>
