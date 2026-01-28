<?php
/**
 * Форма продавца для заполнения анкеты бизнеса
 * 
 * Функциональность:
 * - Создание и редактирование анкет
 * - Сохранение черновиков с полными данными в JSON
 * - Валидация обязательных полей при отправке
 * - Восстановление данных из черновиков
 * - Обработка динамических таблиц (производство, финансы, баланс)
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';
// Подключаем функции для работы с маскированными данными тизера
define('TEASER_FUNCTIONS_ONLY', true);
require_once __DIR__ . '/generate_teaser.php';

if (!isLoggedIn()) {
    redirectToLogin();
}

/**
 * Нормализует поля 2025 года в массивах данных (production/financial):
 * - Переносит 2025_budget -> 2025_fact при отсутствии 2025_fact
 * - Переносит 2025_q3_fact -> 2025_fact при отсутствии 2025_fact (старые анкеты)
 * - Удаляет устаревшее поле 2025_q3_fact (9М 2025 Факт)
 *
 * @param array $data
 * @return array
 */
function normalize2025Fields(array $data): array
{
    foreach ($data as $key => $row) {
        if (is_array($row)) {
            // Глубже для ассоциативных подмассивов
            $row = normalize2025Fields($row);
        }

        if (is_array($row)) {
            if (isset($row['2025_budget']) && (!isset($row['2025_fact']) || $row['2025_fact'] === '')) {
                $row['2025_fact'] = $row['2025_budget'];
            }
            if (isset($row['2025_q3_fact']) && (!isset($row['2025_fact']) || $row['2025_fact'] === '')) {
                $row['2025_fact'] = $row['2025_q3_fact'];
            }
            unset($row['2025_budget'], $row['2025_q3_fact']);
        }

        $data[$key] = $row;
    }

    return $data;
}

$pdo = getDBConnection();
ensureSellerFormSchema($pdo);
$formId = null;
$existingForm = null;
$draftMessage = false;

/**
 * Поля, обязательные для отправки анкеты (не применяются к сохранению черновика)
 * При сохранении черновика валидация не выполняется, все поля опциональны
 */
$requiredFields = [
    'company_inn',
    'asset_name',
    'deal_share_range',
    'deal_goal',
    'asset_disclosure',
    'company_description',
    'presence_regions',
    'products_services',
    'main_clients',
    'sales_share',
    'personnel_count',
    'financial_results_vat',
    'financial_source',
    'agree',
];

/**
 * Проверяет, является ли поле обязательным для отправки формы
 * 
 * @param string $field Название поля
 * @param string|null $companyType Тип компании ('startup' или 'mature')
 * @return bool true если поле обязательное, false иначе
 */
function isFieldRequired(string $field, ?string $companyType = null): bool
{
    global $requiredFields;
    
    // Базовые обязательные поля для всех типов
    $baseRequired = ['company_inn', 'asset_name', 'deal_share_range', 'deal_goal', 'asset_disclosure', 'agree'];
    
    if (in_array($field, $baseRequired, true)) {
        return true;
    }
    
    // Для стартапов убираем требования к финансовым данным за 3 года
    if ($companyType === 'startup') {
        $startupExcluded = ['financial_results_vat', 'financial_source'];
        if (in_array($field, $startupExcluded, true)) {
            return false;
        }
        // Для стартапов обязательны специфичные поля (проверяются отдельно в валидации)
        $startupRequired = ['startup_product_description', 'startup_product_stage', 'startup_target_market'];
        if (in_array($field, $startupRequired, true)) {
            return true;
        }
        // Остальные поля для стартапов опциональны
        return false;
    }
    
    // Для зрелых компаний - все текущие обязательные поля
    return in_array($field, $requiredFields, true);
}

/**
 * Возвращает HTML-атрибут required для обязательных полей
 * Используется в HTML-формах для валидации на стороне клиента
 * 
 * @param string $field Название поля
 * @param string|null $companyType Тип компании ('startup' или 'mature')
 * @return string Строка ' required' или пустая строка
 */
function requiredAttr(string $field, ?string $companyType = null): string
{
    return isFieldRequired($field, $companyType) ? ' required' : '';
}

/**
 * Возвращает CSS-класс для обязательных полей
 * Используется для визуального выделения обязательных полей
 * 
 * @param string $field Название поля
 * @param string|null $companyType Тип компании ('startup' или 'mature')
 * @return string CSS-класс 'required-field' или пустая строка
 */
function requiredClass(string $field, ?string $companyType = null): string
{
    return isFieldRequired($field, $companyType) ? ' required-field' : '';
}

/**
 * Рекурсивная нормализация значений для корректного JSON
 * 
 * Обрабатывает:
 * - Массивы (рекурсивно)
 * - Строки (trim, нормализация кодировки UTF-8)
 * - Остальные типы (возвращает как есть)
 * 
 * @param mixed $value Значение для нормализации
 * @return mixed Нормализованное значение
 */
function normalizeDraftValue($value)
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $innerValue) {
            $normalized[$key] = normalizeDraftValue($innerValue);
        }
        return $normalized;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        return mb_convert_encoding($trimmed, 'UTF-8', 'UTF-8');
    }

    return $value;
}

/**
 * Формирует безопасный payload для сохранения черновика
 * 
 * Собирает все данные формы в единую структуру для сохранения в JSON:
 * - Скалярные поля (текстовые, числовые, выборы)
 * - Динамические таблицы (production, financial, balance)
 * - Метаданные (form_id, save_draft)
 * 
 * Все значения проходят через normalizeDraftValue для корректной сериализации
 * 
 * @param array $source Исходные данные формы (обычно $_POST)
 * @return array Нормализованный массив для сохранения в data_json
 */
function buildDraftPayload(array $source): array
{
    $scalarFields = [
        'company_inn', 'asset_name', 'deal_share_range', 'deal_goal', 'asset_disclosure',
        'company_description', 'presence_regions', 'products_services',
        'company_brands', 'own_production', 'production_sites_count',
        'production_sites_region', 'production_area', 'production_capacity',
        'production_load', 'production_building_ownership', 'production_land_ownership',
        'contract_production_usage', 'contract_production_region', 'contract_production_logistics',
        'offline_sales_presence', 'offline_sales_points', 'offline_sales_regions',
        'offline_sales_area', 'offline_sales_third_party', 'offline_sales_distributors',
        'online_sales_presence', 'online_sales_share', 'online_sales_channels',
        'main_clients', 'sales_share', 'personnel_count', 'company_website',
        'additional_info', 'financial_results_vat', 'financial_source',
        'company_type'
    ];

    $payload = [];

    foreach ($scalarFields as $field) {
        if (array_key_exists($field, $source)) {
            // Специальная обработка для deal_goal: сохраняем массив как JSON
            if ($field === 'deal_goal' && is_array($source[$field])) {
                $payload[$field] = json_encode($source[$field], JSON_UNESCAPED_UNICODE);
            } elseif ($field === 'presence_regions' && is_array($source[$field])) {
                // Сохраняем массив регионов как массив в JSON (для черновиков)
                $payload[$field] = array_map('trim', array_filter($source[$field]));
            } else {
            $payload[$field] = normalizeDraftValue($source[$field]);
            }
        }
    }

    $payload['production'] = normalizeDraftValue($source['production'] ?? []);
    $payload['financial'] = normalizeDraftValue(applyTableUnitFallback($source['financial'] ?? []));
    $payload['balance'] = normalizeDraftValue(applyTableUnitFallback($source['balance'] ?? []));

    // Поля для стартапов (хранятся в data_json)
    $startupFields = [
        'company_founded_date', 'startup_product_description', 'startup_technology_description',
        'startup_ip_patents', 'startup_product_stage', 'startup_users_count', 'startup_mrr',
        'startup_dau', 'startup_mau', 'startup_registrations', 'startup_conversion_rate',
        'startup_retention_rate', 'startup_pilots_partnerships', 'startup_shareholders',
        'startup_key_employees', 'startup_social_links', 'startup_target_market',
        'startup_market_size', 'startup_competitors', 'startup_competitive_advantages',
        'startup_roadmap', 'startup_scaling_plans', 'startup_funding_usage',
        'startup_revenue_2023', 'startup_revenue_2024', 'startup_revenue_2025',
        'startup_expenses_2023', 'startup_expenses_2024', 'startup_expenses_2025',
        'startup_profit_2023', 'startup_profit_2024', 'startup_profit_2025',
        'startup_forecast', 'startup_unit_economics', 'startup_valuation',
        'startup_investment_amount', 'startup_previous_investments'
    ];
    
    foreach ($startupFields as $field) {
        if (array_key_exists($field, $source)) {
            $payload[$field] = normalizeDraftValue($source[$field]);
        }
    }

    if (isset($source['save_draft'])) {
        $payload['save_draft'] = $source['save_draft'];
    }

    if (!empty($source['form_id'])) {
        $payload['form_id'] = $source['form_id'];
    }

    return $payload;
}

/**
 * Заполняет пустые единицы измерения единым значением по таблице.
 * Берем первую непустую единицу и подставляем в строки без unit.
 */
function applyTableUnitFallback(array $rows): array
{
    if (empty($rows)) {
        return $rows;
    }

    $tableUnit = '';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $unit = trim((string)($row['unit'] ?? ''));
        if ($unit !== '') {
            $tableUnit = $unit;
            break;
        }
    }

    if ($tableUnit === '') {
        return $rows;
    }

    foreach ($rows as $key => $row) {
        if (!is_array($row)) {
            continue;
        }
        $unit = trim((string)($row['unit'] ?? ''));
        if ($unit === '') {
            $row['unit'] = $tableUnit;
            $rows[$key] = $row;
        }
    }

    return $rows;
}

/**
 * Проверяет существование колонки в таблице seller_forms
 * Используется для безопасного выполнения миграций БД без ошибок
 * 
 * @param PDO $pdo Подключение к базе данных
 * @param string $column Название колонки для проверки
 * @return bool true если колонка существует, false иначе
 */
function sellerFormsColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'seller_forms'
          AND COLUMN_NAME = :column
        LIMIT 1
    ");
    $stmt->execute([
        'schema' => DB_NAME,
        'column' => $column,
    ]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Проверяет существование нескольких колонок в таблице seller_forms
 * 
 * @param PDO $pdo Подключение к базе данных
 * @param array $columns Массив названий колонок для проверки
 * @return bool true если все колонки существуют, false если хотя бы одна отсутствует
 */
function sellerFormsColumnsExist(PDO $pdo, array $columns): bool
{
    foreach ($columns as $column) {
        if (!sellerFormsColumnExists($pdo, $column)) {
            return false;
        }
    }
    return true;
}

/**
 * Восстанавливает данные формы из базы данных в $_POST
 * 
 * Приоритет источников данных:
 * 1. data_json (новый формат, используется для черновиков) - полная замена $_POST
 * 2. Отдельные поля таблицы (старый формат, для отправленных форм)
 * 3. JSON-поля (production_volumes, financial_results, balance_indicators)
 * 4. Инициализация пустых структур, если данных нет
 * 
 * Функция модифицирует глобальный $_POST для последующего отображения формы
 * 
 * @param array $form Данные формы из базы данных
 * @return void
 */
function hydrateFormFromDb(array $form): void
{
    error_log("HYDRATING FORM - form_id: " . ($form['id'] ?? 'unknown'));

    // Если есть data_json (для черновиков), используем его для восстановления всех данных
    // Это приоритетный источник, так как содержит полную структуру формы
    if (!empty($form['data_json'])) {
        $decodedData = json_decode($form['data_json'], true);
        error_log("HYDRATING FORM - data_json length: " . strlen($form['data_json']));
        if (is_array($decodedData)) {
            $_POST = $decodedData; // Полностью заменяем $_POST данными из базы
            error_log("HYDRATING FORM - loaded data keys: " . implode(', ', array_keys($decodedData)));
            error_log("HYDRATING FORM - production data: " . (isset($_POST['production']) ? 'EXISTS (' . count($_POST['production']) . ' items)' : 'NOT SET'));
            return; // Если data_json есть, используем только его
                } else {
            error_log("HYDRATING FORM - failed to decode JSON");
        }
    } else {
        error_log("HYDRATING FORM - no data_json found");
    }

    // Иначе используем отдельные поля из базы данных (для старых форм или отправленных форм)
    $mapping = [
        'company_inn' => 'company_inn',
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

    foreach ($mapping as $postKey => $column) {
        $_POST[$postKey] = $form[$column] ?? '';
    }
    
    // Преобразуем строку регионов в массив для чекбоксов (если это строка)
    // Если уже массив - оставляем как есть
    if (isset($_POST['presence_regions'])) {
        if (is_string($_POST['presence_regions']) && !empty($_POST['presence_regions'])) {
            // Строка - преобразуем в массив
            $_POST['presence_regions'] = array_map('trim', explode(',', $_POST['presence_regions']));
            $_POST['presence_regions'] = array_filter($_POST['presence_regions']); // Убираем пустые значения
        } elseif (is_array($_POST['presence_regions'])) {
            // Уже массив - очищаем и оставляем как есть
            $_POST['presence_regions'] = array_map('trim', $_POST['presence_regions']);
            $_POST['presence_regions'] = array_filter($_POST['presence_regions']); // Убираем пустые значения
        }
    }


    // Преобразование значений для совместимости
    // Обрабатываем deal_goal как массив (checkboxes) или одиночное значение (для обратной совместимости)
    if (isset($_POST['deal_goal'])) {
        if (is_array($_POST['deal_goal'])) {
            // Новый формат: массив значений
            $_POST['deal_goal'] = $_POST['deal_goal'];
        } else {
            // Старый формат: одиночное значение (для обратной совместимости)
    if ($_POST['deal_goal'] === 'cash-out') $_POST['deal_goal'] = 'cash_out';
    if ($_POST['deal_goal'] === 'cash-in') $_POST['deal_goal'] = 'cash_in';
        }
    }
    $_POST['production_land_ownership'] = $form['production_land_ownership'] ?? '';
    $_POST['contract_production_usage'] = $form['contract_production_usage'] ?? '';
    $_POST['offline_sales_presence'] = $form['offline_sales_presence'] ?? '';
    $_POST['offline_sales_third_party'] = $form['offline_sales_third_party'] ?? '';
    $_POST['offline_sales_distributors'] = $form['offline_sales_distributors'] ?? '';

    // Восстановление данных из JSON для таблиц
    if (!empty($form['data_json'])) {
        $data = json_decode($form['data_json'], true);
        if (is_array($data)) {
            // Восстановление данных таблиц
            if (isset($data['production'])) {
                $_POST['production'] = $data['production'];
            }
            if (isset($data['financial'])) {
                $_POST['financial'] = $data['financial'];
            }
            if (isset($data['balance'])) {
                $_POST['balance'] = $data['balance'];
            }
            // Восстановление остальных полей формы
            foreach ($data as $key => $value) {
                if (!isset($_POST[$key]) && $key !== 'production' && $key !== 'financial' && $key !== 'balance') {
                    // Обрабатываем deal_goal: может быть JSON (массив) или строкой
                    if ($key === 'deal_goal' && is_string($value)) {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded)) {
                            $_POST[$key] = $decoded; // Массив для checkboxes
                        } else {
                            $_POST[$key] = $value; // Строка для обратной совместимости
                        }
                    } else {
                    $_POST[$key] = $value;
                    }
                }
            }
            
            // Преобразуем строку регионов в массив для чекбоксов (если это строка из data_json)
            // Если уже массив - оставляем как есть
            if (isset($_POST['presence_regions'])) {
                if (is_string($_POST['presence_regions']) && !empty($_POST['presence_regions'])) {
                    // Строка - преобразуем в массив
                    $_POST['presence_regions'] = array_map('trim', explode(',', $_POST['presence_regions']));
                    $_POST['presence_regions'] = array_filter($_POST['presence_regions']); // Убираем пустые значения
                } elseif (is_array($_POST['presence_regions'])) {
                    // Уже массив - очищаем и оставляем как есть
                    $_POST['presence_regions'] = array_map('trim', $_POST['presence_regions']);
                    $_POST['presence_regions'] = array_filter($_POST['presence_regions']); // Убираем пустые значения
                }
            }
        }
    }

    // Также проверяем данные из отдельных полей (для совместимости с старыми формами)
    if (empty($_POST['production']) && !empty($form['production_volumes'])) {
        $_POST['production'] = json_decode($form['production_volumes'], true) ?: [];
    }
    if (empty($_POST['financial']) && !empty($form['financial_results'])) {
        $_POST['financial'] = json_decode($form['financial_results'], true) ?: [];
    }
    if (empty($_POST['balance']) && !empty($form['balance_indicators'])) {
        $_POST['balance'] = json_decode($form['balance_indicators'], true) ?: [];
    }

    // Нормализация старых полей 2025 года (budget -> fact, удаляем 9М 2025)
    if (!empty($_POST['production'])) {
        $_POST['production'] = normalize2025Fields($_POST['production']);
    }
    if (!empty($_POST['financial'])) {
        $_POST['financial'] = normalize2025Fields($_POST['financial']);
    }

    // Инициализация пустых массивов с правильной структурой, если они не существуют
    if (!isset($_POST['production']) || empty($_POST['production'])) {
        error_log("INIT PRODUCTION - creating default structure");
        $_POST['production'] = [[
            'product' => '',
            'unit' => '',
            '2022_fact' => '',
            '2023_fact' => '',
            '2024_fact' => '',
            '2025_fact' => '',
            '2026_budget' => ''
        ]];
    }

    if (!isset($_POST['financial']) || empty($_POST['financial'])) {
        error_log("INIT FINANCIAL - creating default structure");
        $metrics = ['revenue', 'cost_of_sales', 'commercial_expenses', 'management_expenses', 'sales_profit', 'depreciation', 'fixed_assets_acquisition'];
        $_POST['financial'] = [];
        foreach ($metrics as $metric) {
            $_POST['financial'][$metric] = [
                'unit' => '',
                '2022_fact' => '',
                '2023_fact' => '',
                '2024_fact' => '',
                '2025_fact' => '',
                '2026_budget' => ''
            ];
        }
    }

    if (!isset($_POST['balance']) || empty($_POST['balance'])) {
        error_log("INIT BALANCE - creating default structure");
        $balanceItems = ['fixed_assets', 'inventory', 'receivables', 'payables', 'loans', 'cash', 'net_assets'];
        $_POST['balance'] = [];
        foreach ($balanceItems as $item) {
            $_POST['balance'][$item] = [
                'unit' => '',
                '2022_fact' => '',
                '2023_fact' => '',
                '2024_fact' => '',
                    '2025_fact' => ''
            ];
        }
    }
}

// ==================== ОБРАБОТКА ФОРМЫ ====================

/**
 * Обработка POST-запроса формы
 * 
 * Поддерживает два режима:
 * 1. Сохранение черновика (save_draft) - все данные в data_json, статус 'draft'
 * 2. Отправка формы (submit) - данные в отдельных полях + data_json, статус 'submitted'
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверяем и обновляем схему БД при необходимости
    ensureSellerFormSchema($pdo);

    // Загружаем существующую форму, если указан form_id
    $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : null;
    $existingForm = null;
    if ($formId) {
        $effectiveUserId = getEffectiveUserId();
        $stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ? AND user_id = ?");
        $stmt->execute([$formId, $effectiveUserId]);
        $existingForm = $stmt->fetch();
    }

    // Определяем тип компании из POST или существующей формы
    $companyType = null;
    if (isset($_POST['company_type']) && in_array($_POST['company_type'], ['startup', 'mature'], true)) {
        $companyType = $_POST['company_type'];
    } elseif ($existingForm && isset($existingForm['company_type'])) {
        $companyType = $existingForm['company_type'];
    }

    // Получаем данные формы
    $asset_name = sanitizeInput($_POST['asset_name'] ?? '');
    $companyInnRaw = sanitizeInput($_POST['company_inn'] ?? '');
    $companyInnDigits = preg_replace('/\D+/', '', $companyInnRaw);
    $_POST['company_inn'] = $companyInnDigits;
    
    // Определяем режим сохранения: черновик или отправка
    // Используем два флага для надежности (save_draft кнопка и скрытое поле save_draft_flag)
    $saveDraftFlag = $_POST['save_draft_flag'] ?? '';
    $isDraftSave = isset($_POST['save_draft']) || $saveDraftFlag === '1';

    error_log("Form processing: method=POST, form_id=" . ($formId ?: 'new') . ", is_draft=" . ($isDraftSave ? 'yes' : 'no') . ", asset_name='" . $asset_name . "'");

    // Определяем тип компании для валидации
    $companyTypeForValidation = $_POST['company_type'] ?? ($existingForm['company_type'] ?? null);

    // Валидация обязательных полей (только для финальной отправки)
    // Для черновиков валидация не выполняется - можно сохранить частично заполненную форму
    if (!$isDraftSave) {
        // Обязательные поля для всех типов
        if ($asset_name === '') {
            $errors['asset_name'] = 'Укажите название актива';
        }
        if ($companyInnDigits === '') {
            $errors['company_inn'] = 'Укажите ИНН';
        } elseif (!preg_match('/^\d{10}$|^\d{12}$/', $companyInnDigits)) {
            $errors['company_inn'] = 'ИНН должен содержать 10 или 12 цифр';
        }
        if (empty($_POST['deal_share_range'])) {
            $errors['deal_share_range'] = 'Укажите предмет сделки';
        }
        if (empty($_POST['asset_disclosure'])) {
            $errors['asset_disclosure'] = 'Укажите, раскрывать ли название';
        }
        // Валидация deal_goal: должен быть выбран хотя бы один вариант
        $dealGoalValue = $_POST['deal_goal'] ?? '';
        if (is_array($dealGoalValue)) {
            if (empty($dealGoalValue)) {
                $errors['deal_goal'] = 'Выберите хотя бы одну цель сделки';
            }
        } elseif (empty($dealGoalValue)) {
            $errors['deal_goal'] = 'Выберите хотя бы одну цель сделки';
        }
        if (!isset($_POST['agree'])) {
            $errors['agree'] = 'Необходимо согласие на обработку данных';
        }
        
        // Валидация для стартапов
        if ($companyTypeForValidation === 'startup') {
            // Проверяем обязательные поля стартапа из data_json (будут в draftPayload)
            $draftPayload = buildDraftPayload($_POST);
            if (empty($draftPayload['startup_product_description'])) {
                $errors['startup_product_description'] = 'Укажите описание продукта/решения';
            }
            if (empty($draftPayload['startup_product_stage'])) {
                $errors['startup_product_stage'] = 'Укажите текущую стадию продукта';
            }
            if (empty($draftPayload['startup_target_market'])) {
                $errors['startup_target_market'] = 'Укажите целевой рынок';
            }
            // Команда: хотя бы один из списков должен быть заполнен
            if (empty($draftPayload['startup_shareholders']) && empty($draftPayload['startup_key_employees'])) {
                $errors['startup_team'] = 'Укажите состав акционеров или ключевых сотрудников';
            }
        } else {
            // Валидация для зрелых компаний (текущие требования)
            if (empty($_POST['company_description'])) {
                $errors['company_description'] = 'Укажите описание деятельности';
            }
            if (empty($_POST['presence_regions'])) {
                $errors['presence_regions'] = 'Укажите регионы присутствия';
            }
            if (empty($_POST['products_services'])) {
                $errors['products_services'] = 'Укажите продукцию/услуги';
            }
            if (empty($_POST['main_clients'])) {
                $errors['main_clients'] = 'Укажите основных клиентов';
            }
            if (empty($_POST['sales_share'])) {
                $errors['sales_share'] = 'Укажите долю продаж в РФ';
            }
            if (empty($_POST['personnel_count'])) {
                $errors['personnel_count'] = 'Укажите численность персонала';
            }
            if (empty($_POST['financial_results_vat'])) {
                $errors['financial_results_vat'] = 'Укажите формат НДС для финансовых результатов';
            }
            if (empty($_POST['financial_source'])) {
                $errors['financial_source'] = 'Укажите источник финансовых показателей';
            }
        }
    }

    // Если ошибок валидации нет, сохраняем данные
    if (empty($errors)) {
        try {
            // Заполняем единицы измерения по таблице, если часть строк не заполнена
            $_POST['financial'] = applyTableUnitFallback($_POST['financial'] ?? []);
            $_POST['balance'] = applyTableUnitFallback($_POST['balance'] ?? []);

            // Подготавливаем данные для сохранения в JSON
            // buildDraftPayload нормализует все данные формы в единую структуру
            $draftPayload = buildDraftPayload($_POST);
            $dataJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
            
            // Обработка ошибок кодирования JSON (на случай проблемных данных)
            if ($dataJson === false) {
                $jsonError = json_last_error_msg();
                error_log("JSON ENCODE FAILED: " . $jsonError);
                // Попытка повторной нормализации и кодирования
                $dataJson = json_encode(normalizeDraftValue($draftPayload), JSON_UNESCAPED_UNICODE);
                if ($dataJson === false) {
                    error_log("JSON ENCODE FAILED SECOND TIME, сохраняем пустой объект");
                    $dataJson = json_encode(new stdClass());
                }
            }

            error_log("SAVING DRAFT - payload keys: " . implode(', ', array_keys($draftPayload)));
            error_log("SAVING DRAFT - production data: " . (isset($draftPayload['production']) ? 'EXISTS' : 'NOT SET'));
            if (isset($draftPayload['production'])) {
                error_log("SAVING DRAFT - production count: " . count($draftPayload['production']));
            }

            if ($isDraftSave) {
                // ========== РЕЖИМ СОХРАНЕНИЯ ЧЕРНОВИКА ==========
                // Для черновика сохраняем только asset_name и data_json
                // Статус всегда 'draft', остальные поля не заполняются
                if ($formId && $existingForm) {
                    // Обновление существующего черновика
                    // ВАЖНО: Сохраняем существующие поля data_json (final_price, multiplier_valuation и т.д.)
                    // чтобы не потерять их при сохранении формы
                    $currentDataJson = [];
                    if (!empty($existingForm['data_json'])) {
                        $decoded = json_decode($existingForm['data_json'], true);
                        if (is_array($decoded)) {
                            $currentDataJson = $decoded;
                        }
                    }
                    
                    // Объединяем текущие данные с новыми данными формы
                    // Приоритет у новых данных формы, но сохраняем важные поля из текущих данных
                    $preservedFields = ['final_price', 'final_selling_price', 'final_price_updated_at', 'multiplier_valuation', 'teaser_snapshot'];
                    foreach ($preservedFields as $field) {
                        if (isset($currentDataJson[$field])) {
                            $draftPayload[$field] = $currentDataJson[$field];
                        }
                    }
                    
                    // Обновляем data_json с сохранением важных полей
                    $dataJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
                    
                    $effectiveUserId = getEffectiveUserId();
                    $stmt = $pdo->prepare("UPDATE seller_forms SET asset_name = ?, company_inn = ?, company_type = ?, data_json = ?, status = 'draft', updated_at = NOW() WHERE id = ? AND user_id = ?");
                    $stmt->execute([$asset_name, $companyInnDigits, $companyType ?: null, $dataJson, $formId, $effectiveUserId]);
                    error_log("DRAFT UPDATED - form_id: $formId");
                } else {
                    // Создание нового черновика
                    $effectiveUserId = getEffectiveUserId();
                    $stmt = $pdo->prepare("INSERT INTO seller_forms (user_id, asset_name, company_inn, company_type, data_json, status) VALUES (?, ?, ?, ?, ?, 'draft')");
                    $stmt->execute([$effectiveUserId, $asset_name, $companyInnDigits, $companyType ?: null, $dataJson]);
                    $formId = $pdo->lastInsertId();
                    error_log("DRAFT INSERTED - new form_id: $formId");
                }

                // Редирект с сообщением об успешном сохранении
                header('Location: seller_form.php?saved=1&form_id=' . $formId);
                exit;
            } else {
                // ========== РЕЖИМ ФИНАЛЬНОЙ ОТПРАВКИ ==========
                // Для отправленной формы сохраняем данные в отдельных полях БД
                // Обрабатываем deal_goal: может быть массивом (checkboxes) или строкой (для обратной совместимости)
                $dealGoalValue = $_POST['deal_goal'] ?? '';
                if (is_array($dealGoalValue)) {
                    // Массив значений: сохраняем как JSON
                    $dealPurpose = json_encode($dealGoalValue, JSON_UNESCAPED_UNICODE);
                } else {
                    // Одиночное значение: сохраняем как строку
                    $dealPurpose = sanitizeInput($dealGoalValue);
                }
                $dealSubject = sanitizeInput($_POST['deal_share_range'] ?? '');
                $assetDisclosure = sanitizeInput($_POST['asset_disclosure'] ?? '');
                $companyDescription = sanitizeInput($_POST['company_description'] ?? '');
                // Обработка регионов присутствия (может быть массивом или строкой)
                // Поле необязательное, поэтому если не выбрано - будет пустая строка
                $presenceRegions = '';
                if (isset($_POST['presence_regions']) && is_array($_POST['presence_regions']) && !empty($_POST['presence_regions'])) {
                    // Новый формат - массив из чекбоксов, объединяем через запятую
                    $presenceRegions = implode(', ', array_filter(array_map('trim', $_POST['presence_regions'])));
                } elseif (isset($_POST['presence_regions']) && !empty($_POST['presence_regions'])) {
                    // Старый формат - строка (для обратной совместимости)
                    $presenceRegions = sanitizeInput($_POST['presence_regions']);
                }
                $productsServices = sanitizeInput($_POST['products_services'] ?? '');
                $companyBrands = sanitizeInput($_POST['company_brands'] ?? '');
                $ownProduction = sanitizeInput($_POST['own_production'] ?? '');
                $productionSitesCount = sanitizeInput($_POST['production_sites_count'] ?? '');
                $productionSitesRegion = sanitizeInput($_POST['production_sites_region'] ?? '');
                $productionArea = sanitizeInput($_POST['production_area'] ?? '');
                $productionCapacity = sanitizeInput($_POST['production_capacity'] ?? '');
                $productionLoad = sanitizeInput($_POST['production_load'] ?? '');
                $productionBuildingOwnership = sanitizeInput($_POST['production_building_ownership'] ?? '');
                $productionLandOwnership = sanitizeInput($_POST['production_land_ownership'] ?? '');
                $contractProductionUsage = sanitizeInput($_POST['contract_production_usage'] ?? '');
                $contractProductionRegion = sanitizeInput($_POST['contract_production_region'] ?? '');
                $contractProductionLogistics = sanitizeInput($_POST['contract_production_logistics'] ?? '');
                $offlineSalesPresence = sanitizeInput($_POST['offline_sales_presence'] ?? '');
                $offlineSalesPoints = sanitizeInput($_POST['offline_sales_points'] ?? '');
                $offlineSalesRegions = sanitizeInput($_POST['offline_sales_regions'] ?? '');
                $offlineSalesArea = sanitizeInput($_POST['offline_sales_area'] ?? '');
                $offlineSalesThirdParty = sanitizeInput($_POST['offline_sales_third_party'] ?? '');
                $offlineSalesDistributors = sanitizeInput($_POST['offline_sales_distributors'] ?? '');
                $onlineSalesPresence = sanitizeInput($_POST['online_sales_presence'] ?? '');
                $onlineSalesShare = sanitizeInput($_POST['online_sales_share'] ?? '');
                $onlineSalesChannels = sanitizeInput($_POST['online_sales_channels'] ?? '');
                $mainClients = sanitizeInput($_POST['main_clients'] ?? '');
                $salesShare = sanitizeInput($_POST['sales_share'] ?? '');
                $personnelCount = sanitizeInput($_POST['personnel_count'] ?? '');
                $companyWebsite = sanitizeInput($_POST['company_website'] ?? '');
                $additionalInfo = sanitizeInput($_POST['additional_info'] ?? '');
                $financialResultsVat = sanitizeInput($_POST['financial_results_vat'] ?? '');
                $financialSource = sanitizeInput($_POST['financial_source'] ?? '');
                // $companyType уже определена выше в начале блока POST

                // Сохраняем таблицы как JSON
                $productionVolumes = isset($_POST['production']) ? json_encode($_POST['production'], JSON_UNESCAPED_UNICODE) : null;
                $financialResults = isset($_POST['financial']) ? json_encode($_POST['financial'], JSON_UNESCAPED_UNICODE) : null;
                $balanceIndicators = isset($_POST['balance']) ? json_encode($_POST['balance'], JSON_UNESCAPED_UNICODE) : null;

                if ($formId && $existingForm) {
                    // ВАЖНО: Сохраняем существующие поля data_json (final_price, multiplier_valuation и т.д.)
                    // чтобы не потерять их при финальной отправке формы
                    $currentDataJson = [];
                    if (!empty($existingForm['data_json'])) {
                        $decoded = json_decode($existingForm['data_json'], true);
                        if (is_array($decoded)) {
                            $currentDataJson = $decoded;
                        }
                    }
                    
                    // Объединяем текущие данные с новыми данными формы
                    // Приоритет у новых данных формы, но сохраняем важные поля из текущих данных
                    $preservedFields = ['final_price', 'final_selling_price', 'final_price_updated_at', 'multiplier_valuation', 'teaser_snapshot'];
                    foreach ($preservedFields as $field) {
                        if (isset($currentDataJson[$field])) {
                            $draftPayload[$field] = $currentDataJson[$field];
                        }
                    }
                    
                    // Обновляем data_json с сохранением важных полей
                    $dataJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
                    
                    $stmt = $pdo->prepare("UPDATE seller_forms SET
                        asset_name = ?, company_inn = ?, company_type = ?, deal_subject = ?, deal_purpose = ?, asset_disclosure = ?,
                        company_description = ?, presence_regions = ?, products_services = ?, company_brands = ?,
                        own_production = ?, production_sites_count = ?, production_sites_region = ?, production_area = ?,
                        production_capacity = ?, production_load = ?, production_building_ownership = ?, production_land_ownership = ?,
                        contract_production_usage = ?, contract_production_region = ?, contract_production_logistics = ?,
                        offline_sales_presence = ?, offline_sales_points = ?, offline_sales_regions = ?, offline_sales_area = ?,
                        offline_sales_third_party = ?, offline_sales_distributors = ?,
                        online_sales_presence = ?, online_sales_share = ?, online_sales_channels = ?,
                        main_clients = ?, sales_share = ?, personnel_count = ?, company_website = ?, additional_info = ?,
                        financial_results_vat = ?, financial_source = ?,
                        production_volumes = ?, financial_results = ?, balance_indicators = ?, data_json = ?,
                        status = 'submitted', submitted_at = NOW(), updated_at = NOW()
                        WHERE id = ? AND user_id = ?");
                    $stmt->execute([
                        $asset_name, $companyInnDigits, $companyType ?: null, $dealSubject, $dealPurpose, $assetDisclosure,
                        $companyDescription, $presenceRegions, $productsServices, $companyBrands,
                        $ownProduction, $productionSitesCount, $productionSitesRegion, $productionArea,
                        $productionCapacity, $productionLoad, $productionBuildingOwnership, $productionLandOwnership,
                        $contractProductionUsage, $contractProductionRegion, $contractProductionLogistics,
                        $offlineSalesPresence, $offlineSalesPoints, $offlineSalesRegions, $offlineSalesArea,
                        $offlineSalesThirdParty, $offlineSalesDistributors,
                        $onlineSalesPresence, $onlineSalesShare, $onlineSalesChannels,
                        $mainClients, $salesShare, $personnelCount, $companyWebsite, $additionalInfo,
                        $financialResultsVat, $financialSource,
                        $productionVolumes, $financialResults, $balanceIndicators, $dataJson,
                        $formId, getEffectiveUserId()
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO seller_forms (
                                    user_id, asset_name, company_inn, company_type, deal_subject, deal_purpose, asset_disclosure,
                                    company_description, presence_regions, products_services, company_brands,
                        own_production, production_sites_count, production_sites_region, production_area,
                        production_capacity, production_load, production_building_ownership, production_land_ownership,
                                    contract_production_usage, contract_production_region, contract_production_logistics,
                                    offline_sales_presence, offline_sales_points, offline_sales_regions, offline_sales_area,
                                    offline_sales_third_party, offline_sales_distributors,
                                    online_sales_presence, online_sales_share, online_sales_channels,
                                    main_clients, sales_share, personnel_count, company_website, additional_info,
                        financial_results_vat, financial_source,
                        production_volumes, financial_results, balance_indicators, data_json,
                        status, submitted_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())");
                            $effectiveUserId = getEffectiveUserId();
                            $stmt->execute([
                        $effectiveUserId, $asset_name, $companyInnDigits, $companyType ?: null, $dealSubject, $dealPurpose, $assetDisclosure,
                        $companyDescription, $presenceRegions, $productsServices, $companyBrands,
                        $ownProduction, $productionSitesCount, $productionSitesRegion, $productionArea,
                        $productionCapacity, $productionLoad, $productionBuildingOwnership, $productionLandOwnership,
                        $contractProductionUsage, $contractProductionRegion, $contractProductionLogistics,
                        $offlineSalesPresence, $offlineSalesPoints, $offlineSalesRegions, $offlineSalesArea,
                        $offlineSalesThirdParty, $offlineSalesDistributors,
                        $onlineSalesPresence, $onlineSalesShare, $onlineSalesChannels,
                        $mainClients, $salesShare, $personnelCount, $companyWebsite, $additionalInfo,
                        $financialResultsVat, $financialSource,
                        $productionVolumes, $financialResults, $balanceIndicators, $dataJson
                    ]);
                    $formId = $pdo->lastInsertId();
                }

                // Для финальной отправки - редирект в кабинет
                            header('Location: dashboard.php?success=1');
                            exit;
            }
                        } catch (PDOException $e) {
                            error_log("Error saving form: " . $e->getMessage());
            if ($isDraftSave) {
                $errors['general'] = 'Ошибка сохранения черновика: ' . $e->getMessage();
            } else {
                            $errors['general'] = 'Ошибка сохранения анкеты. Попробуйте позже.';
                        }
        }
    }
}

// Загружаем существующий черновик или форму для редактирования
$formId = null;
$existingForm = null;
$draftMessage = false;

if (isset($_GET['form_id'])) {
    $formId = (int)$_GET['form_id'];
    $effectiveUserId = getEffectiveUserId();
    $stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$formId, $effectiveUserId]);
    $existingForm = $stmt->fetch();
    if ($existingForm) {
        $formId = $existingForm['id'];
    }
}

if (isset($_GET['saved'])) {
    $draftMessage = true;
}

// Определяем тип компании
$companyType = null;
if (isset($_POST['company_type']) && in_array($_POST['company_type'], ['startup', 'mature'], true)) {
    $companyType = $_POST['company_type'];
} elseif ($existingForm && isset($existingForm['company_type'])) {
    $companyType = $existingForm['company_type'];
}

// Если есть существующая форма, загружаем данные
if ($existingForm) {
    error_log("LOADING EXISTING FORM - form_id: " . $existingForm['id'] . ", status: " . $existingForm['status'] . ", company_type: " . ($companyType ?? 'NULL'));
    hydrateFormFromDb($existingForm);
    // Восстанавливаем company_type из БД, если не был передан в POST
    if (!$companyType && isset($existingForm['company_type'])) {
        $companyType = $existingForm['company_type'];
        $_POST['company_type'] = $companyType;
    }
} else {
    error_log("NO EXISTING FORM TO LOAD");
}

/**
 * Обеспечивает актуальность схемы таблицы seller_forms
 * 
 * Выполняет миграции БД для добавления новых колонок и переименования старых.
 * Использует статическую переменную для предотвращения повторных проверок в рамках одного запроса.
 * 
 * Миграции:
 * 1. Добавление новых колонок (asset_disclosure, offline_sales_*, financial_results_vat, data_json и т.д.)
 * 2. Переименование legacy колонок (own_retail_* -> offline_sales_*)
 * 3. Переименование financial_indicators -> financial_results
 * 
 * Все операции проверяют существование колонок перед выполнением, чтобы избежать ошибок.
 * 
 * @param PDO $pdo Подключение к базе данных
 * @return void
 */
function ensureSellerFormSchema(PDO $pdo): void
{
    // Статическая переменная гарантирует, что миграции выполняются только один раз за запрос
    static $schemaChecked = false;
    if ($schemaChecked) {
        return;
    }
    $schemaChecked = true;

    // Список колонок для добавления: название колонки => SQL для ALTER TABLE
    $columnsToAdd = [
        'company_inn' => "ALTER TABLE seller_forms ADD COLUMN company_inn VARCHAR(20) DEFAULT NULL AFTER asset_name",
        'asset_disclosure' => "ALTER TABLE seller_forms ADD COLUMN asset_disclosure ENUM('yes','no') DEFAULT NULL AFTER deal_purpose",
        'offline_sales_third_party' => "ALTER TABLE seller_forms ADD COLUMN offline_sales_third_party ENUM('yes','no') DEFAULT NULL AFTER offline_sales_area",
        'offline_sales_distributors' => "ALTER TABLE seller_forms ADD COLUMN offline_sales_distributors ENUM('yes','no') DEFAULT NULL AFTER offline_sales_third_party",
        'financial_results_vat' => "ALTER TABLE seller_forms ADD COLUMN financial_results_vat ENUM('with_vat','without_vat') DEFAULT NULL AFTER production_volumes",
        'balance_indicators' => "ALTER TABLE seller_forms ADD COLUMN balance_indicators JSON DEFAULT NULL AFTER financial_results",
        'data_json' => "ALTER TABLE seller_forms ADD COLUMN data_json JSON DEFAULT NULL AFTER submitted_at",
        'company_type' => "ALTER TABLE seller_forms ADD COLUMN company_type ENUM('startup', 'mature') DEFAULT NULL COMMENT 'Тип компании: startup - стартап/начинающая, mature - зрелая компания' AFTER user_id",
    ];

    // Добавление новых колонок (если они еще не существуют)
    foreach ($columnsToAdd as $column => $sql) {
        if (sellerFormsColumnExists($pdo, $column)) {
            continue; // Колонка уже существует, пропускаем
        }
        try {
            $pdo->exec($sql);
            error_log("Column {$column} added to seller_forms");
        } catch (PDOException $e) {
            // Логируем ошибку, но не прерываем выполнение (колонка может уже существовать)
            error_log("Failed to add column {$column}: " . $e->getMessage());
        }
    }

    // Переименование legacy колонок для офлайн-продаж
    // Старые названия: own_retail_* -> Новые: offline_sales_*
    $legacyRetailColumns = ['own_retail_presence', 'own_retail_points', 'own_retail_regions', 'own_retail_area'];
    if (sellerFormsColumnsExist($pdo, $legacyRetailColumns)) {
        try {
            $pdo->exec("
                ALTER TABLE seller_forms
                    CHANGE COLUMN `own_retail_presence`  `offline_sales_presence`  ENUM('yes','no') DEFAULT NULL,
                    CHANGE COLUMN `own_retail_points`    `offline_sales_points`    INT DEFAULT NULL,
                    CHANGE COLUMN `own_retail_regions`   `offline_sales_regions`   VARCHAR(255) DEFAULT NULL,
                    CHANGE COLUMN `own_retail_area`      `offline_sales_area`      VARCHAR(255) DEFAULT NULL
            ");
            error_log("Legacy retail columns renamed to offline_sales_*");
        } catch (PDOException $e) {
            error_log("Retail columns rename failed: " . $e->getMessage());
        }
    }

    // Переименование financial_indicators -> financial_results (унификация названий)
    if (sellerFormsColumnExists($pdo, 'financial_indicators')) {
        try {
            $pdo->exec("ALTER TABLE seller_forms CHANGE COLUMN financial_indicators financial_results JSON DEFAULT NULL");
            error_log("Column financial_indicators renamed to financial_results");
        } catch (PDOException $e) {
            error_log("Financial indicators rename failed: " . $e->getMessage());
        }
    }
}

$errors = [];
$yesNo = ['yes' => 'да', 'no' => 'нет'];

// ==================== HTML ====================

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анкета продавца - SmartBizSell</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/seller_form.php">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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

    <section id="seller-form" class="seller-form-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Анкета для продавца</h2>
                <p class="section-subtitle">Расскажите о компании — и команда SmartBizSell подготовит материалы сделки и стратегию выхода на рынок</p>
            </div>
            <div class="form-wrapper">
                <?php if ($draftMessage): ?>
                    <div id="draft-saved-message" class="success-message">
                    <div class="success-icon">✓</div>
                        <h3>Черновик сохранён</h3>
                        <p>Вы можете продолжить заполнение анкеты в любое время.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors['general'])): ?>
                    <div class="error-message" style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                        <strong>Ошибка:</strong> <?php echo htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['debug']) || isset($_GET['saved'])): ?>
                    <div style="background: #e7f3ff; border: 1px solid #b3d9ff; color: #004085; padding: 16px; border-radius: 12px; margin-bottom: 24px; font-family: monospace; font-size: 12px;">
                        <h4 style="margin-top: 0;">🔍 Отладочная информация:</h4>
                        <p><strong>Form ID:</strong> <?php echo $formId ?? 'не установлен'; ?></p>
                        <p><strong>Статус сообщения:</strong> <?php echo $draftMessage ? '✅ Показано' : '❌ Не показано'; ?></p>
                        <?php if ($existingForm): ?>
                            <p><strong>Найден черновик:</strong> ✅ Да (ID: <?php echo $existingForm['id']; ?>)</p>
                            <p><strong>Размер data_json:</strong> <?php echo strlen($existingForm['data_json'] ?? ''); ?> байт</p>
                            <?php if (!empty($existingForm['data_json'])): ?>
                                <?php $decoded = json_decode($existingForm['data_json'], true); ?>
                                <p><strong>JSON валидный:</strong> <?php echo is_array($decoded) ? '✅ Да' : '❌ Нет'; ?></p>
                                <?php if (is_array($decoded)): ?>
                                    <p><strong>Ключи в data_json:</strong> <?php echo implode(', ', array_slice(array_keys($decoded), 0, 10)); ?><?php if (count($decoded) > 10) echo '...'; ?></p>
                                    <p><strong>production в data_json:</strong> <?php echo isset($decoded['production']) ? '✅ Да (' . count($decoded['production']) . ' элементов)' : '❌ Нет'; ?></p>
                                    <p><strong>asset_name в $_POST:</strong> <?php echo isset($_POST['asset_name']) ? '✅ "' . htmlspecialchars($_POST['asset_name']) . '"' : '❌ Нет'; ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="error"><strong>data_json:</strong> ❌ ПУСТОЙ!</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p><strong>Найден черновик:</strong> ❌ Нет</p>
                        <?php endif; ?>
                        <p style="margin-top: 10px;"><a href="debug_draft.php" target="_blank" style="color: #004085; text-decoration: underline;">📊 Открыть полную отладку</a></p>
                    </div>
                <?php endif; ?>

                <div class="form-legend">
                    <span class="legend-marker"></span>
                    <div>
                        <strong>Легенда:</strong> поля с бирюзовой полосой и отметкой * обязательны для заполнения при отправке анкеты
                    </div>
                </div>

                <form class="seller-form" method="POST" action="seller_form.php" novalidate>
                    <input type="hidden" name="form_id" value="<?php echo htmlspecialchars($formId ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="save_draft_flag" value="0" id="save-draft-flag">
                    
                    <?php if (!$companyType): ?>
                    <!-- Выбор типа компании - показывается только если тип не определен -->
                    <div class="form-section" id="company-type-selection">
                        <h3 class="form-section-title">Выберите тип компании</h3>
                        <div class="form-group<?php echo requiredClass('company_type', $companyType); ?>">
                            <label>Тип компании:</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="company_type" value="startup" <?php echo (($_POST['company_type'] ?? '') === 'startup') ? 'checked' : ''; ?> required>
                                    <span>Стартап / начинающая компания</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="company_type" value="mature" <?php echo (($_POST['company_type'] ?? '') === 'mature') ? 'checked' : ''; ?> required>
                                    <span>Зрелая компания</span>
                                </label>
                            </div>
                            <?php if (isset($errors['company_type'])): ?>
                                <span class="error-message"><?php echo $errors['company_type']; ?></span>
                            <?php endif; ?>
                            <small style="color: var(--text-secondary); display: block; margin-top: 8px;">
                                Выберите тип компании для отображения соответствующей анкеты
                            </small>
                        </div>
                        <div style="text-align: center; margin-top: 24px;">
                            <button type="submit" name="save_draft" value="1" class="btn btn-primary" formnovalidate>
                                Продолжить
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Форма отображается только если тип компании выбран -->
                    <div class="form-actions" style="margin-bottom:24px; text-align:right;">
                        <button type="submit" name="save_draft" value="1" class="btn btn-secondary" style="padding: 10px 20px;" formnovalidate>
                            Сохранить черновик
                        </button>
                    </div>
                    <input type="hidden" name="company_type" value="<?php echo htmlspecialchars($companyType, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-section">
                        <h3 class="form-section-title">I. Детали предполагаемой сделки</h3>
                        <div class="form-group<?php echo requiredClass('company_inn', $companyType); ?>">
                            <label for="company_inn">ИНН организации:</label>
                            <input type="text" id="company_inn" name="company_inn"<?php echo requiredAttr('company_inn', $companyType); ?>
                                   value="<?php echo htmlspecialchars($_POST['company_inn'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Например, 7707083893 или 500100732259">
                            <?php if (isset($errors['company_inn'])): ?>
                                <span class="error-message"><?php echo $errors['company_inn']; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="form-group<?php echo requiredClass('asset_name', $companyType); ?>">
                            <label for="asset_name">Название актива (название ЮЛ, группы компаний или бренда):</label>
                            <input type="text" id="asset_name" name="asset_name"<?php echo requiredAttr('asset_name', $companyType); ?>
                                   value="<?php echo htmlspecialchars($_POST['asset_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (isset($errors['asset_name'])): ?>
                                <span class="error-message"><?php echo $errors['asset_name']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group<?php echo requiredClass('deal_share_range', $companyType); ?>">
                            <label for="deal_share_range">Предмет сделки: продажа доли ____%</label>
                            <div class="input-suffix-container">
                                <input type="number"
                                       id="deal_share_range"
                                       name="deal_share_range"
                                       min="1"
                                       max="100"
                                       step="1"
                                       placeholder="например, 25"
                                       class="input-with-suffix"
                                       <?php echo requiredAttr('deal_share_range', $companyType); ?>
                                       value="<?php echo htmlspecialchars(preg_replace('/[^0-9\\.]/', '', $_POST['deal_share_range'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="input-suffix">%</span>
                            </div>
                            <small style="color: var(--text-secondary);">Введите число от 1 до 100, знак «%» подставится автоматически</small>
                        </div>

                        <div class="form-group<?php echo requiredClass('deal_goal', $companyType); ?>">
                            <label>Цель сделки:</label>
                            <div class="radio-group">
                                <?php
                                // Обрабатываем deal_goal: может быть массивом (новый формат) или строкой (старый формат)
                                $dealGoalValue = $_POST['deal_goal'] ?? '';
                                $dealGoalArray = [];
                                if (is_array($dealGoalValue)) {
                                    $dealGoalArray = $dealGoalValue;
                                } elseif (is_string($dealGoalValue)) {
                                    // Пытаемся декодировать JSON
                                    $decoded = json_decode($dealGoalValue, true);
                                    if (is_array($decoded)) {
                                        $dealGoalArray = $decoded;
                                    } elseif (!empty($dealGoalValue)) {
                                        // Одиночное значение для обратной совместимости
                                        $dealGoalArray = [$dealGoalValue];
                                    }
                                }
                                $isCashOutChecked = in_array('cash_out', $dealGoalArray, true);
                                $isCashInChecked = in_array('cash_in', $dealGoalArray, true);
                                ?>
                                <label class="radio-label">
                                    <input type="checkbox" name="deal_goal[]" value="cash_out" <?php echo $isCashOutChecked ? 'checked' : ''; ?>>
                                    <span>a. Продажа бизнеса (cash-out)</span>
                                </label>
                                <label class="radio-label">
                                    <input type="checkbox" name="deal_goal[]" value="cash_in" <?php echo $isCashInChecked ? 'checked' : ''; ?>>
                                    <span>b. Привлечение инвестиций (cash-in)</span>
                                </label>
                            </div>
                            <?php if (isset($errors['deal_goal'])): ?>
                                <span class="error-message"><?php echo $errors['deal_goal']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group<?php echo requiredClass('asset_disclosure', $companyType); ?>">
                            <label>Раскрытие названия актива в анкете: да/нет</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="asset_disclosure" value="yes" <?php echo (($_POST['asset_disclosure'] ?? '') === 'yes') ? 'checked' : ''; ?><?php echo requiredAttr('asset_disclosure', $companyType); ?>>
                                    <span>да</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="asset_disclosure" value="no" <?php echo (($_POST['asset_disclosure'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php if ($companyType === 'startup'): ?>
                    <!-- СЕКЦИИ ДЛЯ СТАРТАПОВ -->
                    <?php
                    // Загружаем данные стартапа из data_json
                    $startupData = [];
                    if ($existingForm && !empty($existingForm['data_json'])) {
                        $decoded = json_decode($existingForm['data_json'], true);
                        if (is_array($decoded)) {
                            $startupData = $decoded;
                        }
                    }
                    // Приоритет у POST данных
                    foreach ($_POST as $key => $value) {
                        if (strpos($key, 'startup_') === 0 || $key === 'company_founded_date') {
                            $startupData[$key] = $value;
                        }
                    }
                    ?>

                    <div class="form-section">
                        <h3 class="form-section-title">II. Описание продукта и технологии</h3>
                        <div class="form-group">
                            <label for="company_founded_date">Дата основания компании (месяц/год):</label>
                            <input type="month" id="company_founded_date" name="company_founded_date"
                                   value="<?php echo htmlspecialchars($startupData['company_founded_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group<?php echo requiredClass('startup_product_description', $companyType); ?>">
                            <label for="startup_product_description">Описание продукта/решения:</label>
                            <textarea id="startup_product_description" name="startup_product_description" rows="5"<?php echo requiredAttr('startup_product_description', $companyType); ?>><?php echo htmlspecialchars($startupData['startup_product_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">Что вы создаете, какую конкретную проблему решаете, в чем конкурентные преимущества</small>
                            <?php if (isset($errors['startup_product_description'])): ?>
                                <span class="error-message"><?php echo $errors['startup_product_description']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="startup_technology_description">Описание технологии:</label>
                            <textarea id="startup_technology_description" name="startup_technology_description" rows="4"><?php echo htmlspecialchars($startupData['startup_technology_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="startup_ip_patents">Патенты, интеллектуальная собственность:</label>
                            <textarea id="startup_ip_patents" name="startup_ip_patents" rows="3"><?php echo htmlspecialchars($startupData['startup_ip_patents'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group<?php echo requiredClass('startup_product_stage', $companyType); ?>">
                            <label for="startup_product_stage">Текущая стадия продукта:</label>
                            <select id="startup_product_stage" name="startup_product_stage"<?php echo requiredAttr('startup_product_stage', $companyType); ?>>
                                <option value="">— выбрать —</option>
                                <option value="idea" <?php echo (($startupData['startup_product_stage'] ?? '') === 'idea') ? 'selected' : ''; ?>>Идея</option>
                                <option value="prototype" <?php echo (($startupData['startup_product_stage'] ?? '') === 'prototype') ? 'selected' : ''; ?>>Прототип</option>
                                <option value="mvp" <?php echo (($startupData['startup_product_stage'] ?? '') === 'mvp') ? 'selected' : ''; ?>>MVP</option>
                                <option value="working_product" <?php echo (($startupData['startup_product_stage'] ?? '') === 'working_product') ? 'selected' : ''; ?>>Рабочий продукт</option>
                                <option value="scaling" <?php echo (($startupData['startup_product_stage'] ?? '') === 'scaling') ? 'selected' : ''; ?>>Масштабирование</option>
                            </select>
                            <?php if (isset($errors['startup_product_stage'])): ?>
                                <span class="error-message"><?php echo $errors['startup_product_stage']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">III. Ключевые показатели (traction)</h3>
                        <div class="form-group">
                            <label for="startup_users_count">Количество пользователей/клиентов:</label>
                            <input type="number" id="startup_users_count" name="startup_users_count" min="0"
                                   value="<?php echo htmlspecialchars($startupData['startup_users_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_mrr">Ежемесячный прирост дохода (MRR), руб.:</label>
                            <input type="number" id="startup_mrr" name="startup_mrr" min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($startupData['startup_mrr'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_dau">Количество активных пользователей (DAU):</label>
                            <input type="number" id="startup_dau" name="startup_dau" min="0"
                                   value="<?php echo htmlspecialchars($startupData['startup_dau'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_mau">Количество активных пользователей (MAU):</label>
                            <input type="number" id="startup_mau" name="startup_mau" min="0"
                                   value="<?php echo htmlspecialchars($startupData['startup_mau'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_registrations">Количество регистраций:</label>
                            <input type="number" id="startup_registrations" name="startup_registrations" min="0"
                                   value="<?php echo htmlspecialchars($startupData['startup_registrations'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_conversion_rate">Конверсии (conversion rate), %:</label>
                            <input type="number" id="startup_conversion_rate" name="startup_conversion_rate" min="0" max="100" step="0.01"
                                   value="<?php echo htmlspecialchars($startupData['startup_conversion_rate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_retention_rate">Удержание клиентов (retention rate), %:</label>
                            <input type="number" id="startup_retention_rate" name="startup_retention_rate" min="0" max="100" step="0.01"
                                   value="<?php echo htmlspecialchars($startupData['startup_retention_rate'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_pilots_partnerships">Пилотные проекты/партнерства:</label>
                            <textarea id="startup_pilots_partnerships" name="startup_pilots_partnerships" rows="3"><?php echo htmlspecialchars($startupData['startup_pilots_partnerships'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">IV. Команда</h3>
                        <div class="form-group">
                            <label for="startup_shareholders">Состав акционеров:</label>
                            <textarea id="startup_shareholders" name="startup_shareholders" rows="5"><?php echo htmlspecialchars(is_array($startupData['startup_shareholders'] ?? null) ? json_encode($startupData['startup_shareholders'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($startupData['startup_shareholders'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">ФИО, роль, подробный бэкграунд (образование, опыт)</small>
                        </div>

                        <div class="form-group">
                            <label for="startup_key_employees">Ключевые сотрудники:</label>
                            <textarea id="startup_key_employees" name="startup_key_employees" rows="5"><?php echo htmlspecialchars(is_array($startupData['startup_key_employees'] ?? null) ? json_encode($startupData['startup_key_employees'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($startupData['startup_key_employees'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">ФИО, роль, подробный бэкграунд (образование, опыт)</small>
                            <?php if (isset($errors['startup_team'])): ?>
                                <span class="error-message"><?php echo $errors['startup_team']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="personnel_count">Численность команды:</label>
                            <input type="number" id="personnel_count" name="personnel_count" min="0"
                                   value="<?php echo htmlspecialchars($_POST['personnel_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_social_links">Ссылки на соцсети:</label>
                            <textarea id="startup_social_links" name="startup_social_links" rows="2"><?php echo htmlspecialchars($startupData['startup_social_links'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">ТГ-каналы и т.п.</small>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">V. Рынок и конкурентные преимущества</h3>
                        <div class="form-group<?php echo requiredClass('startup_target_market', $companyType); ?>">
                            <label for="startup_target_market">Целевой рынок:</label>
                            <textarea id="startup_target_market" name="startup_target_market" rows="3"<?php echo requiredAttr('startup_target_market', $companyType); ?>><?php echo htmlspecialchars($startupData['startup_target_market'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php if (isset($errors['startup_target_market'])): ?>
                                <span class="error-message"><?php echo $errors['startup_target_market']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="startup_market_size">Размер рынка (TAM/SAM/SOM):</label>
                            <textarea id="startup_market_size" name="startup_market_size" rows="2"><?php echo htmlspecialchars($startupData['startup_market_size'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="startup_competitors">Конкуренты и аналоги:</label>
                            <textarea id="startup_competitors" name="startup_competitors" rows="3"><?php echo htmlspecialchars($startupData['startup_competitors'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="startup_competitive_advantages">Преимущества и недостатки существующих продуктов на рынке:</label>
                            <textarea id="startup_competitive_advantages" name="startup_competitive_advantages" rows="3"><?php echo htmlspecialchars($startupData['startup_competitive_advantages'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="company_website">Сайт/продукт:</label>
                            <input type="url" id="company_website" name="company_website"
                                   value="<?php echo htmlspecialchars($_POST['company_website'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="https://example.com">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">VI. Дорожная карта развития</h3>
                        <div class="form-group">
                            <label for="startup_roadmap">План развития:</label>
                            <textarea id="startup_roadmap" name="startup_roadmap" rows="5"><?php echo htmlspecialchars($startupData['startup_roadmap'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">Ключевые шаги на ближайшие 12-24 мес.</small>
                        </div>

                        <div class="form-group">
                            <label for="startup_scaling_plans">Планы по масштабированию:</label>
                            <textarea id="startup_scaling_plans" name="startup_scaling_plans" rows="3"><?php echo htmlspecialchars($startupData['startup_scaling_plans'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="startup_funding_usage">На что будет направлено финансирование, привлеченное в сделке (если cash-in):</label>
                            <textarea id="startup_funding_usage" name="startup_funding_usage" rows="3"><?php echo htmlspecialchars($startupData['startup_funding_usage'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">VII. Финансовые показатели и прогнозы</h3>
                        <h4 style="margin-top: 0; margin-bottom: 16px;">Фактические финансовые показатели за 2023-2025 (если есть):</h4>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px;">
                            <div class="form-group">
                                <label>2023:</label>
                                <input type="number" name="startup_revenue_2023" placeholder="Выручка" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_revenue_2023'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="startup_expenses_2023" placeholder="Расходы" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_expenses_2023'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="startup_profit_2023" placeholder="Прибыль/убыток" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_profit_2023'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label>2024:</label>
                                <input type="number" name="startup_revenue_2024" placeholder="Выручка" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_revenue_2024'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="startup_expenses_2024" placeholder="Расходы" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_expenses_2024'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="startup_profit_2024" placeholder="Прибыль/убыток" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_profit_2024'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group">
                                <label>2025:</label>
                                <input type="number" name="startup_revenue_2025" placeholder="Выручка" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_revenue_2025'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="startup_expenses_2025" placeholder="Расходы" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_expenses_2025'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="startup_profit_2025" placeholder="Прибыль/убыток" step="0.01" value="<?php echo htmlspecialchars($startupData['startup_profit_2025'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="startup_forecast">Прогнозные финансовые показатели (3-5 лет):</label>
                            <textarea id="startup_forecast" name="startup_forecast" rows="5"><?php echo htmlspecialchars(is_array($startupData['startup_forecast'] ?? null) ? json_encode($startupData['startup_forecast'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($startupData['startup_forecast'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">Выручка, Расходы, Прибыль/убыток по годам</small>
                        </div>

                        <div class="form-group">
                            <label for="startup_unit_economics">Юнит-экономика:</label>
                            <textarea id="startup_unit_economics" name="startup_unit_economics" rows="5"><?php echo htmlspecialchars(is_array($startupData['startup_unit_economics'] ?? null) ? json_encode($startupData['startup_unit_economics'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($startupData['startup_unit_economics'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">Текущий объем продаж, цена за 1, переменные и постоянные затраты, точка безубыточности</small>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">VIII. Инвестиции</h3>
                        <div class="form-group">
                            <label for="startup_valuation">Текущая оценка компании, руб.:</label>
                            <input type="number" id="startup_valuation" name="startup_valuation" min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($startupData['startup_valuation'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_investment_amount">Требуемая сумма инвестиций, руб.:</label>
                            <input type="number" id="startup_investment_amount" name="startup_investment_amount" min="0" step="0.01"
                                   value="<?php echo htmlspecialchars($startupData['startup_investment_amount'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="startup_previous_investments">Предыдущие инвестиции (если были):</label>
                            <textarea id="startup_previous_investments" name="startup_previous_investments" rows="5"><?php echo htmlspecialchars(is_array($startupData['startup_previous_investments'] ?? null) ? json_encode($startupData['startup_previous_investments'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : ($startupData['startup_previous_investments'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <small style="color: var(--text-secondary);">Сумма, инвестор, тип инвестора (друзья и родственники / бизнес-ангел / фонд или корпорация), как использовались инвестиции</small>
                        </div>
                    </div>
                    <?php elseif ($companyType === 'mature'): ?>
                    <!-- СЕКЦИИ ДЛЯ ЗРЕЛЫХ КОМПАНИЙ -->
                    <div class="form-section">
                        <h3 class="form-section-title">II. Описание бизнеса компании</h3>
                        <div class="form-group<?php echo requiredClass('company_description', $companyType); ?>">
                            <label for="company_description">Краткое описание деятельности компании:</label>
                            <textarea id="company_description" name="company_description" rows="4"<?php echo requiredAttr('company_description', $companyType); ?>><?php echo htmlspecialchars($_POST['company_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Регионы присутствия:</label>
                            <?php
                            // Список всех регионов
                            $allRegions = [
                                'Вся РФ',
                                'Москва',
                                'Санкт-Петербург',
                                'Московская область',
                                'Ленинградская область',
                                'Краснодарский край',
                                'Свердловская область',
                                'Республика Татарстан',
                                'Новосибирская область',
                                'Нижегородская область',
                                'Ростовская область',
                                'Челябинская область',
                                'Самарская область',
                                'Красноярский край',
                                'Воронежская область',
                                'Пермский край',
                                'Волгоградская область',
                                'Республика Башкортостан',
                                'Омская область',
                                'Тюменская область',
                                'Кемеровская область',
                                'Иркутская область',
                                'Республика Дагестан',
                                'Ставропольский край',
                                'Белгородская область',
                                'Курская область',
                                'Липецкая область',
                                'Тульская область',
                                'Калужская область',
                                'Ярославская область',
                                'Тверская область',
                                'Владимирская область',
                                'Рязанская область',
                                'Тамбовская область',
                                'Пензенская область',
                                'Ульяновская область',
                                'Саратовская область',
                                'Астраханская область',
                                'Республика Крым',
                                'Севастополь'
                            ];
                            
                            // Определяем выбранные регионы
                            $selectedRegions = [];
                            if (isset($_POST['presence_regions'])) {
                                if (is_array($_POST['presence_regions'])) {
                                    // Новый формат - массив из чекбоксов
                                    $selectedRegions = array_map('trim', $_POST['presence_regions']);
                                } else {
                                    // Старый формат - строка, разбиваем по запятой
                                    $selectedRegions = array_map('trim', explode(',', $_POST['presence_regions']));
                                }
                            }
                            $selectedRegions = array_filter($selectedRegions); // Убираем пустые значения
                            ?>
                            <div class="regions-checkboxes" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; margin-top: 8px; max-height: 300px; overflow-y: auto; padding: 8px; border: 1px solid #e0e0e0; border-radius: 6px; background: #fafafa;">
                                <?php foreach ($allRegions as $region): ?>
                                    <?php
                                    $checked = in_array(trim($region), $selectedRegions, true) ? 'checked' : '';
                                    $regionEscaped = htmlspecialchars($region, ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px 0; user-select: none;">
                                        <input type="checkbox" 
                                               name="presence_regions[]" 
                                               value="<?php echo $regionEscaped; ?>" 
                                               <?php echo $checked; ?>
                                               style="cursor: pointer; width: 18px; height: 18px; margin: 0;">
                                        <span style="font-size: 14px;"><?php echo $regionEscaped; ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group<?php echo requiredClass('products_services', $companyType); ?>">
                            <label for="products_services">Продукция/услуги компании:</label>
                            <textarea id="products_services" name="products_services" rows="3"<?php echo requiredAttr('products_services', $companyType); ?>><?php echo htmlspecialchars($_POST['products_services'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="company_brands">Бренды компании:</label>
                            <input type="text" id="company_brands" name="company_brands"
                                   value="<?php echo htmlspecialchars($_POST['company_brands'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                            <div class="form-group">
                            <label>Собственные производственные мощности:</label>
                                <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="own_production" value="yes" <?php echo (($_POST['own_production'] ?? 'yes') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                        </label>
                                <label class="radio-label">
                                    <input type="radio" name="own_production" value="no" <?php echo (($_POST['own_production'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                        </label>
                                </div>
                            </div>

                                    <div class="form-group">
                            <label for="production_sites_count">Количество производственных площадок:</label>
                                        <input type="number" id="production_sites_count" name="production_sites_count" min="0"
                                               value="<?php echo htmlspecialchars($_POST['production_sites_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label for="production_sites_region">Регион расположения производственных площадок:</label>
                                        <input type="text" id="production_sites_region" name="production_sites_region"
                                               value="<?php echo htmlspecialchars($_POST['production_sites_region'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                    <div class="form-group">
                            <label for="production_area">Площадь производственной площадки:</label>
                                        <input type="text" id="production_area" name="production_area"
                                               value="<?php echo htmlspecialchars($_POST['production_area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label for="production_capacity">Производственная мощность:</label>
                                        <input type="text" id="production_capacity" name="production_capacity" placeholder="мощность; единицы"
                                               value="<?php echo htmlspecialchars($_POST['production_capacity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                    <div class="form-group">
                            <label for="production_load">Текущая загрузка мощностей:</label>
                                        <input type="text" id="production_load" name="production_load"
                                               value="<?php echo htmlspecialchars($_POST['production_load'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label>Право собственности на здание:</label>
                                        <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="production_building_ownership" value="yes" <?php echo (($_POST['production_building_ownership'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="production_building_ownership" value="no" <?php echo (($_POST['production_building_ownership'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                                </label>
                                        </div>
                                    </div>

                                    <div class="form-group">
                            <label>Право собственности на земельный участок:</label>
                                        <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="production_land_ownership" value="yes" <?php echo (($_POST['production_land_ownership'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="production_land_ownership" value="no" <?php echo (($_POST['production_land_ownership'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                                </label>
                            </div>
                        </div>

                            <div class="form-group">
                            <label>Контрактное производство:</label>
                                <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="contract_production_usage" value="yes" <?php echo (($_POST['contract_production_usage'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                        </label>
                                <label class="radio-label">
                                    <input type="radio" name="contract_production_usage" value="no" <?php echo (($_POST['contract_production_usage'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                        </label>
                                </div>
                            </div>

                                <div class="form-group">
                            <label for="contract_production_region">Регион расположения контрактных производителей:</label>
                                    <input type="text" id="contract_production_region" name="contract_production_region"
                                           value="<?php echo htmlspecialchars($_POST['contract_production_region'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                <div class="form-group">
                            <label for="contract_production_logistics">Как осуществляется логистика от производства до клиентов:</label>
                                    <textarea id="contract_production_logistics" name="contract_production_logistics" rows="3"><?php echo htmlspecialchars($_POST['contract_production_logistics'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                            <div class="form-group">
                            <label>Офлайн-продажи:</label>
                                <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="offline_sales_presence" value="yes" <?php echo (($_POST['offline_sales_presence'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                        </label>
                                <label class="radio-label">
                                    <input type="radio" name="offline_sales_presence" value="no" <?php echo (($_POST['offline_sales_presence'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                </label>
                                </div>
                            </div>

                                    <div class="form-group">
                            <label for="offline_sales_points">Количество розничных точек:</label>
                                        <input type="number" id="offline_sales_points" name="offline_sales_points" min="0"
                                               value="<?php echo htmlspecialchars($_POST['offline_sales_points'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label for="offline_sales_regions">Регионы расположения розничных точек:</label>
                                        <input type="text" id="offline_sales_regions" name="offline_sales_regions"
                                               value="<?php echo htmlspecialchars($_POST['offline_sales_regions'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label for="offline_sales_area">Общая площадь розничных точек:</label>
                                        <input type="text" id="offline_sales_area" name="offline_sales_area"
                                               value="<?php echo htmlspecialchars($_POST['offline_sales_area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label>Реализация через сторонние розничные магазины:</label>
                                        <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="offline_sales_third_party" value="yes" <?php echo (($_POST['offline_sales_third_party'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="offline_sales_third_party" value="no" <?php echo (($_POST['offline_sales_third_party'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                                </label>
                                        </div>
                                    </div>

                                    <div class="form-group">
                            <label>Реализация через дистрибьюторов:</label>
                                        <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="offline_sales_distributors" value="yes" <?php echo (($_POST['offline_sales_distributors'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="offline_sales_distributors" value="no" <?php echo (($_POST['offline_sales_distributors'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                                </label>
                            </div>
                        </div>

                            <div class="form-group">
                            <label>Онлайн-продажи:</label>
                                <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="online_sales_presence" value="yes" <?php echo (($_POST['online_sales_presence'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                    <span>да</span>
                                        </label>
                                <label class="radio-label">
                                    <input type="radio" name="online_sales_presence" value="no" <?php echo (($_POST['online_sales_presence'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                        </label>
                                </div>
                            </div>

                                    <div class="form-group">
                            <label for="online_sales_share">Доля онлайн-продаж:</label>
                                        <input type="text" id="online_sales_share" name="online_sales_share"
                                               value="<?php echo htmlspecialchars($_POST['online_sales_share'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>

                                    <div class="form-group">
                            <label for="online_sales_channels">В каких онлайн-магазинах и маркетплейсах присутствует продукция:</label>
                                        <textarea id="online_sales_channels" name="online_sales_channels" rows="3"><?php echo htmlspecialchars($_POST['online_sales_channels'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group<?php echo requiredClass('main_clients', $companyType); ?>">
                            <label for="main_clients">Основные клиенты:</label>
                            <textarea id="main_clients" name="main_clients" rows="3"<?php echo requiredAttr('main_clients', $companyType); ?>><?php echo htmlspecialchars($_POST['main_clients'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group<?php echo requiredClass('sales_share', $companyType); ?>">
                            <label for="sales_share">Доля продаж в РФ, %</label>
                            <div class="input-suffix-container">
                                <input type="number"
                                       id="sales_share"
                                       name="sales_share"
                                       min="0"
                                       max="100"
                                       step="1"
                                       class="input-with-suffix"
                                       value="<?php echo htmlspecialchars(preg_replace('/[^0-9\\.]/', '', $_POST['sales_share'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="input-suffix">%</span>
                            </div>
                            <small style="color: var(--text-secondary); display: block; margin-top: 8px;">Введите число от 0 до 100, знак «%» подставится автоматически</small>
                        </div>

                            <div class="form-group<?php echo requiredClass('personnel_count', $companyType); ?>">
                            <label for="personnel_count">Численность персонала:</label>
                            <input type="number" id="personnel_count" name="personnel_count" min="0"<?php echo requiredAttr('personnel_count', $companyType); ?>
                                       value="<?php echo htmlspecialchars($_POST['personnel_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                            <label for="company_website">Сайт компании:</label>
                            <input type="text" id="company_website" name="company_website"
                                   placeholder="www.example.com или https://example.com"
                                       value="<?php echo htmlspecialchars($_POST['company_website'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="additional_info">Дополнительная информация:</label>
                            <textarea id="additional_info" name="additional_info" rows="3"><?php echo htmlspecialchars($_POST['additional_info'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">III. Основные операционные и финансовые показатели</h3>

                        <div class="form-group">
                            <label for="production_table">Объемы производства:</label>
                            <div class="table-container">
                                <table class="form-table production-table" id="production_table">
                                    <thead>
                                        <tr>
                                            <th style="width: 25%;">Вид продукции</th>
                                            <th style="width: 15%;">Ед. изм.</th>
                                            <th style="width: 10%;">2022 факт</th>
                                            <th style="width: 10%;">2023 факт</th>
                                            <th style="width: 10%;">2024 факт</th>
                                            <th style="width: 10%;">2025 факт</th>
                                            <th style="width: 10%;">2026 бюджет</th>
                                        </tr>
                                    </thead>
                                    <tbody id="production_rows">
                        <?php
                        $production = $_POST['production'] ?? [];
                        error_log("RENDERING PRODUCTION - count: " . count($production));
                        if (empty($production)) {
                            // Добавляем пустую строку по умолчанию
                            $production[] = [
                                'product' => '',
                                'unit' => '',
                                '2022_fact' => '',
                                '2023_fact' => '',
                                '2024_fact' => '',
                                '2025_fact' => '',
                                '2026_budget' => ''
                            ];
                            error_log("RENDERING PRODUCTION - added default empty row");
                        }
                        foreach ($production as $index => $row):
                            error_log("RENDERING PRODUCTION - row $index: product='" . ($row['product'] ?? 'empty') . "'");
                        endforeach;
                        foreach ($production as $index => $row): ?>
                                        <tr>
                                            <td><input type="text" name="production[<?php echo $index; ?>][product]" value="<?php echo htmlspecialchars($row['product'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="production[<?php echo $index; ?>][unit]" value="<?php echo htmlspecialchars($row['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="production[<?php echo $index; ?>][2022_fact]" value="<?php echo htmlspecialchars($row['2022_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="production[<?php echo $index; ?>][2023_fact]" value="<?php echo htmlspecialchars($row['2023_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="production[<?php echo $index; ?>][2024_fact]" value="<?php echo htmlspecialchars($row['2024_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="production[<?php echo $index; ?>][2025_fact]" value="<?php echo htmlspecialchars($row['2025_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="production[<?php echo $index; ?>][2026_budget]" value="<?php echo htmlspecialchars($row['2026_budget'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <button type="button" class="btn btn-secondary btn-small" id="add_production_row" style="margin-top: 10px;">+ Добавить строку</button>
                            </div>
                        </div>

                            <div class="form-group<?php echo requiredClass('financial_results_vat', $companyType); ?>">
                            <label>Финансовые результаты:</label>
                                <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="financial_results_vat" value="with_vat" <?php echo (($_POST['financial_results_vat'] ?? '') === 'with_vat') ? 'checked' : ''; ?><?php echo requiredAttr('financial_results_vat', $companyType); ?>>
                                        <span>с НДС</span>
                                    </label>
                                <label class="radio-label">
                                        <input type="radio" name="financial_results_vat" value="without_vat" <?php echo (($_POST['financial_results_vat'] ?? '') === 'without_vat') ? 'checked' : ''; ?>>
                                        <span>без НДС</span>
                                    </label>
                                </div>
                            </div>

                        <div class="form-group">
                            <label for="financial_results_table">Таблица финансовых результатов:</label>
                            <div style="margin: 8px 0 12px; display: flex; gap: 12px; align-items: center;">
                                <span style="color: var(--text-secondary);">Ед. изм. для всей таблицы:</span>
                                <select id="financial-unit-select" class="form-control" style="max-width: 200px;">
                                    <option value="">— выбрать —</option>
                                    <option value="тыс. руб.">тыс. руб.</option>
                                    <option value="млн. руб.">млн. руб.</option>
                                </select>
                            </div>
                            <div class="table-container">
                                <table class="form-table" id="financial_results_table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30%;">Показатель</th>
                                            <th style="width: 10%;">Ед. изм.</th>
                                            <th style="width: 10%;">2022 факт</th>
                                            <th style="width: 10%;">2023 факт</th>
                                            <th style="width: 10%;">2024 факт</th>
                                            <th style="width: 10%;">2025 факт</th>
                                            <th style="width: 10%;">2026 бюджет</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $financial = $_POST['financial'] ?? [];
                                        $metrics = [
                                            'revenue' => 'Выручка',
                                            'cost_of_sales' => 'Себестоимость продаж',
                                            'commercial_expenses' => 'Коммерческие расходы',
                                            'management_expenses' => 'Управленческие расходы',
                                            'sales_profit' => 'Прибыль от продаж',
                                            'depreciation' => 'Амортизация',
                                            'fixed_assets_acquisition' => 'Приобретение основных средств'
                                        ];
                                        // Инициализируем пустые данные для financial, если их нет
                                        foreach ($metrics as $key => $label) {
                                            if (!isset($financial[$key])) {
                                                $financial[$key] = [
                                                    'unit' => '',
                                                    '2022_fact' => '',
                                                    '2023_fact' => '',
                                                    '2024_fact' => '',
                                                    '2025_fact' => '',
                                                    '2026_budget' => ''
                                                ];
                                            }
                                        }
                                        foreach ($metrics as $key => $label): ?>
                                        <tr>
                                            <td><?php echo $label; ?></td>
                                            <td><input class="financial-unit" type="text" name="financial[<?php echo $key; ?>][unit]" value="<?php echo htmlspecialchars($financial[$key]['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="financial[<?php echo $key; ?>][2022_fact]" value="<?php echo htmlspecialchars($financial[$key]['2022_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="financial[<?php echo $key; ?>][2023_fact]" value="<?php echo htmlspecialchars($financial[$key]['2023_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="financial[<?php echo $key; ?>][2024_fact]" value="<?php echo htmlspecialchars($financial[$key]['2024_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="financial[<?php echo $key; ?>][2025_fact]" value="<?php echo htmlspecialchars($financial[$key]['2025_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="financial[<?php echo $key; ?>][2026_budget]" value="<?php echo htmlspecialchars($financial[$key]['2026_budget'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="balance_table">Балансовые показатели:</label>
                            <div style="margin: 8px 0 12px; display: flex; gap: 12px; align-items: center;">
                                <span style="color: var(--text-secondary);">Ед. изм. для всей таблицы:</span>
                                <select id="balance-unit-select" class="form-control" style="max-width: 200px;">
                                    <option value="">— выбрать —</option>
                                    <option value="тыс. руб.">тыс. руб.</option>
                                    <option value="млн. руб.">млн. руб.</option>
                                </select>
                            </div>
                            <div class="table-container">
                                <table class="form-table" id="balance_table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30%;">Показатель</th>
                                            <th style="width: 10%;">Ед. изм.</th>
                                            <th style="width: 15%;">31.12.2022 факт</th>
                                            <th style="width: 15%;">31.12.2023 факт</th>
                                            <th style="width: 15%;">31.12.2024 факт</th>
                                            <th style="width: 15%;">31.12.2025 факт</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $balance = $_POST['balance'] ?? [];
                                        $balanceMetrics = [
                                            'fixed_assets' => 'Основные средства',
                                            'inventory' => 'Запасы',
                                            'receivables' => 'Дебиторская задолженность',
                                            'payables' => 'Кредиторская задолженность',
                                            'loans' => 'Кредиты и займы',
                                            'cash' => 'Денежные средства',
                                            'net_assets' => 'Чистые активы'
                                        ];
                                        // Инициализируем пустые данные для balance, если их нет
                                        foreach ($balanceMetrics as $key => $label) {
                                            if (!isset($balance[$key])) {
                                                $balance[$key] = [
                                                    'unit' => '',
                                                    '2022_fact' => '',
                                                    '2023_fact' => '',
                                                    '2024_fact' => '',
                                                    '2025_q3_fact' => ''
                                                ];
                                            }
                                        }
                                        foreach ($balanceMetrics as $key => $label): ?>
                                        <tr>
                                            <td><?php echo $label; ?></td>
                                            <td><input class="balance-unit" type="text" name="balance[<?php echo $key; ?>][unit]" value="<?php echo htmlspecialchars($balance[$key]['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="balance[<?php echo $key; ?>][2022_fact]" value="<?php echo htmlspecialchars($balance[$key]['2022_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="balance[<?php echo $key; ?>][2023_fact]" value="<?php echo htmlspecialchars($balance[$key]['2023_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="balance[<?php echo $key; ?>][2024_fact]" value="<?php echo htmlspecialchars($balance[$key]['2024_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            <td><input type="text" name="balance[<?php echo $key; ?>][2025_fact]" value="<?php echo htmlspecialchars($balance[$key]['2025_fact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="form-group<?php echo requiredClass('financial_source', $companyType); ?>">
                            <label>Источник финансовых показателей:</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="financial_source" value="rsbu" <?php echo (($_POST['financial_source'] ?? '') === 'rsbu') ? 'checked' : ''; ?><?php echo requiredAttr('financial_source', $companyType); ?>>
                                    <span>a. РСБУ</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="financial_source" value="ifrs" <?php echo (($_POST['financial_source'] ?? '') === 'ifrs') ? 'checked' : ''; ?>>
                                    <span>b. МСФО</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="financial_source" value="management" <?php echo (($_POST['financial_source'] ?? '') === 'management') ? 'checked' : ''; ?>>
                                    <span>c. Управленческая отчетность</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>
                    <!-- Конец условного рендеринга по типу компании -->

                    <div class="form-group checkbox-group<?php echo requiredClass('agree', $companyType); ?>">
                        <label class="checkbox-label">
                            <input type="checkbox" name="agree" <?php echo isset($_POST['agree']) ? 'checked' : ''; ?><?php echo requiredAttr('agree', $companyType); ?>>
                            <span>Я соглашаюсь на обработку персональных данных и использование ИИ для подготовки<br>материалов<span style="color: red;">*</span></span>
                        </label>
                        <?php if (isset($errors['agree'])): ?>
                            <span class="error-message"><?php echo $errors['agree']; ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions" style="margin-top: 40px; text-align: center;">
                        <button type="submit" name="submit_form" value="1" class="btn btn-primary btn-large">
                        <span>Отправить анкету</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    </div>
                </form>
                    <?php endif; ?>
                    <!-- Конец блока выбора типа компании / формы -->
            </div>
        </div>
    </section>

    <script src="script.js?v=<?php echo time(); ?>"></script>
    <style>
        .form-legend {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 12px 16px;
            border-radius: 12px;
            background: rgba(20, 184, 166, 0.08);
            border: 1px solid rgba(20, 184, 166, 0.2);
        }

        .legend-marker {
            width: 18px;
            height: 18px;
            border-radius: 6px;
            background: linear-gradient(135deg, #2dd4bf, #0ea5e9);
            box-shadow: 0 0 12px rgba(45, 212, 191, 0.4);
        }

        .form-group.required-field,
        .checkbox-group.required-field {
            border-left: 4px solid #14b8a6;
            padding-left: 18px;
            background: rgba(20, 184, 166, 0.06);
            border-radius: 16px;
        }

        .form-group.required-field > label::after {
            content: ' *';
            color: var(--accent-color);
            font-weight: 600;
        }

        /* Убираем автоматическую звездочку для checkbox-group, так как она добавляется вручную в HTML */
        .checkbox-group.required-field .checkbox-label::after,
        .checkbox-group.required-field .checkbox-label span::after,
        .checkbox-group.required-field label::after {
            content: '' !important;
            display: none !important;
        }
        
        /* Специально для поля согласия - убираем все автоматические звездочки */
        input[name="agree"] + span::after,
        label:has(input[name="agree"])::after,
        label:has(input[name="agree"]) span::after {
            content: '' !important;
            display: none !important;
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-10px);
                height: 0;
                margin: 0;
                padding: 0;
                overflow: hidden;
            }
        }
        #draft-saved-message {
            transition: all 0.5s ease-out;
            background: #d4edda !important;
            border: 2px solid #28a745 !important;
            color: #155724 !important;
            padding: 20px !important;
            border-radius: 12px !important;
            margin-bottom: 24px !important;
            display: block !important;
            opacity: 1 !important;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #draft-saved-message .success-icon {
            font-size: 24px;
            color: #28a745;
            margin-right: 10px;
        }

        /* Стили для скрытых полей формы */
        .form-group[style*="display: none"] {
            pointer-events: none;
            user-select: none;
        }

        .form-group[style*="display: none"] input,
        .form-group[style*="display: none"] textarea,
        .form-group[style*="display: none"] select {
            background-color: #f8f9fa !important;
            border-color: #dee2e6 !important;
            color: #6c757d !important;
            cursor: not-allowed !important;
        }

        .form-group[style*="display: none"] label {
            color: #6c757d !important;
        }

        /* Стили для таблиц в формах */
        .table-container {
            overflow-x: auto;
            margin: 15px 0;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            background: white;
        }

        .form-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .form-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            border: none;
        }

        .form-table td {
            padding: 8px;
            border-bottom: 1px solid #e9ecef;
            background: white;
        }

        .form-table td input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-table td input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }

        .form-table tr:nth-child(even) td {
            background: #f8f9fa;
        }

        .form-table tr:hover td {
            background: #e3f2fd;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
        }
        .input-suffix-container {
            position: relative;
            display: inline-flex;
            align-items: center;
            width: 100%;
            max-width: 280px;
        }
        .input-with-suffix {
            padding-right: 36px;
        }
        .input-suffix {
            position: absolute;
            right: 12px;
            color: var(--text-secondary);
            pointer-events: none;
            font-weight: 600;
        }
        .error-message {
            display: block;
            color: var(--accent-color);
            font-size: 14px;
            margin-top: 8px;
            margin-bottom: 0;
            line-height: 1.4;
        }
        .form-group small {
            display: block;
            margin-top: 8px;
            line-height: 1.4;
        }
    </style>
           <script>
               // Автоматически скрываем сообщение о сохранении черновика через 3 секунды
               document.addEventListener('DOMContentLoaded', function() {
                   const draftMessage = document.getElementById('draft-saved-message');
                   if (draftMessage) {
                       console.log('✅ Сообщение о сохранении черновика найдено');
                       // Показываем сообщение на 3 секунды, затем плавно скрываем
                       setTimeout(function() {
                           draftMessage.style.opacity = '0';
                           draftMessage.style.transform = 'translateY(-10px)';
                           setTimeout(function() {
                               draftMessage.style.display = 'none';
                           }, 500); // Время для анимации
                       }, 3000); // Показываем 3 секунды (было 1 секунда)
                   } else {
                       console.log('❌ Сообщение о сохранении черновика НЕ найдено');
                       // Проверяем, должен ли оно быть
                       const urlParams = new URLSearchParams(window.location.search);
                       if (urlParams.get('saved') === '1') {
                           console.warn('⚠️ В URL есть ?saved=1, но сообщение не отображается!');
                       }
                   }

                   // Инициализация динамических секций формы
                   initFormToggles();
               });

               function initFormToggles() {
                   // Собственные производственные мощности
                   const ownProductionRadios = document.querySelectorAll('input[name="own_production"]');
                   const productionFieldIds = ['production_sites_count', 'production_sites_region', 'production_area', 'production_capacity', 'production_load'];

                   function toggleProductionFields() {
                       const isYes = document.querySelector('input[name="own_production"]:checked')?.value === 'yes';
                       productionFieldIds.forEach(id => {
                           const field = document.getElementById(id);
                           if (field) {
                               const formGroup = field.closest('.form-group');
                               if (formGroup) {
                                   if (isYes) {
                                       formGroup.style.display = 'block';
                                       formGroup.style.opacity = '1';
                                   } else {
                                       formGroup.style.display = 'none';
                                       formGroup.style.opacity = '0.5';
                                   }
                               }
                           }
                       });
                   }

                   ownProductionRadios.forEach(radio => radio.addEventListener('change', toggleProductionFields));
                   toggleProductionFields(); // Инициализация

                   // Контрактное производство
                   const contractProductionRadios = document.querySelectorAll('input[name="contract_production_usage"]');
                   const contractFieldIds = ['contract_production_region', 'contract_production_logistics'];

                   function toggleContractFields() {
                       const isYes = document.querySelector('input[name="contract_production_usage"]:checked')?.value === 'yes';
                       contractFieldIds.forEach(id => {
                           const field = document.getElementById(id);
                           if (field) {
                               const formGroup = field.closest('.form-group');
                               if (formGroup) {
                                   if (isYes) {
                                       formGroup.style.display = 'block';
                                       formGroup.style.opacity = '1';
                                   } else {
                                       formGroup.style.display = 'none';
                                       formGroup.style.opacity = '0.5';
                                   }
                               }
                           }
                       });
                   }

                   contractProductionRadios.forEach(radio => radio.addEventListener('change', toggleContractFields));
                   toggleContractFields(); // Инициализация

                   // Офлайн-продажи
                   const offlineSalesRadios = document.querySelectorAll('input[name="offline_sales_presence"]');
                   const offlineFieldIds = ['offline_sales_points', 'offline_sales_regions', 'offline_sales_area'];

                   function toggleOfflineFields() {
                       const isYes = document.querySelector('input[name="offline_sales_presence"]:checked')?.value === 'yes';
                       offlineFieldIds.forEach(id => {
                           const field = document.getElementById(id);
                           if (field) {
                               const formGroup = field.closest('.form-group');
                               if (formGroup) {
                                   if (isYes) {
                                       formGroup.style.display = 'block';
                                       formGroup.style.opacity = '1';
                                   } else {
                                       formGroup.style.display = 'none';
                                       formGroup.style.opacity = '0.5';
                                   }
                               }
                           }
                       });
                   }

                   offlineSalesRadios.forEach(radio => radio.addEventListener('change', toggleOfflineFields));
                   toggleOfflineFields(); // Инициализация

                   // Автозаполнение колонки ед. изм. для финансов и баланса
                   function initUnitSelect(selectId, inputSelector) {
                       const selectEl = document.getElementById(selectId);
                       if (!selectEl) return;
                       const inputs = Array.from(document.querySelectorAll(inputSelector));
                       if (!inputs.length) return;

                       // Определяем исходное значение: берем первое непустое из колонок
                       const initial = inputs.map(i => i.value.trim()).find(v => v !== '');
                       if (initial) {
                           selectEl.value = initial;
                       }

                       selectEl.addEventListener('change', () => {
                           const val = selectEl.value;
                           if (!val) return;
                           inputs.forEach(inp => { inp.value = val; });
                       });
                   }

                   initUnitSelect('financial-unit-select', '.financial-unit');
                   initUnitSelect('balance-unit-select', '.balance-unit');

                   // Онлайн-продажи
                   const onlineSalesRadios = document.querySelectorAll('input[name="online_sales_presence"]');
                   const onlineFieldIds = ['online_sales_share', 'online_sales_channels'];

                   function toggleOnlineFields() {
                       const isYes = document.querySelector('input[name="online_sales_presence"]:checked')?.value === 'yes';
                       onlineFieldIds.forEach(id => {
                           const field = document.getElementById(id);
                           if (field) {
                               const formGroup = field.closest('.form-group');
                               if (formGroup) {
                                   if (isYes) {
                                       formGroup.style.display = 'block';
                                       formGroup.style.opacity = '1';
                                   } else {
                                       formGroup.style.display = 'none';
                                       formGroup.style.opacity = '0.5';
                                   }
                               }
                           }
                       });
                   }

                   onlineSalesRadios.forEach(radio => radio.addEventListener('change', toggleOnlineFields));
                   toggleOnlineFields(); // Инициализация

                   // Скролл к первой ошибке при загрузке страницы, если есть ошибки валидации
                   <?php if (!empty($errors)): ?>
                   document.addEventListener('DOMContentLoaded', function() {
                       // Ищем первую ошибку валидации
                       const firstError = document.querySelector('.error-message');
                       const firstErrorField = document.querySelector('.form-group.has-error');
                       const targetElement = firstError || firstErrorField;
                       
                       if (targetElement) {
                           setTimeout(() => {
                               targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                               // Подсвечиваем поле с ошибкой
                               if (firstErrorField) {
                                   firstErrorField.style.border = '2px solid #FF3B30';
                                   firstErrorField.style.borderRadius = '8px';
                                   firstErrorField.style.padding = '12px';
                                   setTimeout(() => {
                                       firstErrorField.style.border = '';
                                       firstErrorField.style.padding = '';
                                   }, 5000);
                               }
                           }, 300);
                       }
                   });
                   <?php endif; ?>

                   // Валидация регионов присутствия при отправке формы
                   const sellerForm = document.querySelector('form');
                   if (sellerForm) {
                       // Отслеживаем, какая кнопка была нажата
                       let clickedButton = null;
                       sellerForm.addEventListener('click', function(e) {
                           if (e.target.type === 'submit' || e.target.closest('button[type="submit"]')) {
                               clickedButton = e.target.type === 'submit' ? e.target : e.target.closest('button[type="submit"]');
                               console.log('Button clicked:', clickedButton.name, clickedButton.value);
                           }
                       });
                       
                       sellerForm.addEventListener('submit', function(e) {
                           console.log('Form submit event triggered');
                           
                           // Определяем, какая кнопка была нажата
                           let isDraftSave = false;
                           if (e.submitter) {
                               // Современные браузеры
                               isDraftSave = e.submitter.name === 'save_draft' || 
                                           e.submitter.getAttribute('formnovalidate') !== null;
                               console.log('Using e.submitter, isDraftSave:', isDraftSave);
                           } else if (clickedButton) {
                               // Fallback для старых браузеров
                               isDraftSave = clickedButton.name === 'save_draft' || 
                                           clickedButton.getAttribute('formnovalidate') !== null;
                               console.log('Using clickedButton, isDraftSave:', isDraftSave);
                           } else {
                               // Если не удалось определить, проверяем наличие скрытого поля
                               const saveDraftFlag = document.querySelector('input[name="save_draft_flag"]');
                               isDraftSave = saveDraftFlag && saveDraftFlag.value === '1';
                               console.log('Using saveDraftFlag, isDraftSave:', isDraftSave);
                           }
                           
                           // Регионы присутствия - необязательное поле, валидация убрана
                           console.log('Form submit allowed, isDraftSave:', isDraftSave);
                           // Сбрасываем отслеживание кнопки
                           clickedButton = null;
                       });
                   }

                   // Динамическое добавление строк в таблицу объемов производства
                   const addProductionRowBtn = document.getElementById('add_production_row');
                   const productionRows = document.getElementById('production_rows');

                   if (addProductionRowBtn && productionRows) {
                       function getNextProductionIndex() {
                           const existingRows = productionRows.querySelectorAll('tr');
                           let maxIndex = -1;
                           existingRows.forEach(row => {
                               const inputs = row.querySelectorAll('input[name^="production["]');
                               inputs.forEach(input => {
                                   const match = input.name.match(/production\[(\d+)\]/);
                                   if (match && parseInt(match[1]) > maxIndex) {
                                       maxIndex = parseInt(match[1]);
                                   }
                               });
                           });
                           return maxIndex + 1;
                       }

                       addProductionRowBtn.addEventListener('click', function() {
                           const rowIndex = getNextProductionIndex();
                           const newRow = document.createElement('tr');
                           newRow.innerHTML = `
                               <td><input type="text" name="production[${rowIndex}][product]"></td>
                               <td><input type="text" name="production[${rowIndex}][unit]"></td>
                               <td><input type="text" name="production[${rowIndex}][2022_fact]"></td>
                               <td><input type="text" name="production[${rowIndex}][2023_fact]"></td>
                               <td><input type="text" name="production[${rowIndex}][2024_fact]"></td>
                               <td><input type="text" name="production[${rowIndex}][2025_fact]"></td>
                               <td><input type="text" name="production[${rowIndex}][2026_budget]"></td>
                           `;
                           productionRows.appendChild(newRow);
                       });
                   }
               }
           </script>
</body>
</html>
