<?php
/**
 * calculate_multiplier_valuation.php
 *
 * Расчет оценки компании по методу мультипликаторов.
 * 
 * Основные этапы:
 * 1. Получение данных из анкеты продавца
 * 2. Определение сектора компании через ИИ
 * 3. Извлечение финансовых показателей (выручка, прибыль от продаж, амортизация)
 * 4. Применение мультипликаторов в зависимости от сектора
 * 5. Расчет стоимости компании
 * 
 * @package SmartBizSell
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Определяет единицы измерения из строки
 * Поддерживает: "тыс. руб.", "млн. руб.", "тыс руб", "млн руб" и их варианты
 * 
 * @param string $unit Строка с единицами измерения
 * @return string 'thousands' для тысяч, 'millions' для миллионов, 'unknown' если не определено
 */
function detectUnit(string $unit): string {
    $unit = mb_strtolower(trim($unit));
    if (empty($unit)) {
        return 'unknown';
    }
    // Проверяем на наличие "тыс" (тысячи)
    if (preg_match('/тыс/', $unit)) {
        return 'thousands';
    }
    // Проверяем на наличие "млн" (миллионы)
    if (preg_match('/млн/', $unit)) {
        return 'millions';
    }
    return 'unknown';
}

/**
 * Конвертирует значение в миллионы рублей с учетом единиц измерения
 * Если значение в тысячах, делит на 1000; если в миллионах, оставляет как есть
 * 
 * @param mixed $value Значение для конвертации
 * @param string $unit Единицы измерения ('thousands', 'millions', 'unknown')
 * @return float Значение в миллионах рублей
 */
function convertToMillions($value, string $unit): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    $numValue = is_numeric($value) ? (float)$value : 0.0;
    if ($numValue == 0) {
        return 0.0;
    }
    
    switch ($unit) {
        case 'thousands':
            // Конвертируем из тысяч в миллионы (делим на 1000)
            return $numValue / 1000.0;
        case 'millions':
            // Уже в миллионах, возвращаем как есть
            return $numValue;
        case 'unknown':
        default:
            // Если единицы не определены, предполагаем миллионы (для обратной совместимости)
            return $numValue;
    }
}

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

$apiKey = TOGETHER_API_KEY ?? null;
if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API-ключ together.ai не настроен.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем form_id из запроса, если он передан
    $requestData = json_decode(file_get_contents('php://input'), true);
    $requestedFormId = isset($requestData['form_id']) ? (int)$requestData['form_id'] : null;

    if ($requestedFormId) {
        // Загружаем конкретную анкету по ID, если она принадлежит пользователю
        $effectiveUserId = getEffectiveUserId();
        $stmt = $pdo->prepare("
            SELECT *
            FROM seller_forms
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$requestedFormId, $effectiveUserId]);
        $form = $stmt->fetch();
        
        if (!$form) {
            echo json_encode(['success' => false, 'message' => 'Анкета не найдена или не принадлежит вам.']);
            exit;
        }
    } else {
        // Если form_id не указан, используем последнюю отправленную анкету
        $effectiveUserId = getEffectiveUserId();
        $stmt = $pdo->prepare("
            SELECT *
            FROM seller_forms
            WHERE user_id = ?
              AND status IN ('submitted','review','approved')
            ORDER BY submitted_at DESC, updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$effectiveUserId]);
        $form = $stmt->fetch();

        if (!$form) {
            echo json_encode(['success' => false, 'message' => 'Нет отправленных анкет для расчета оценки. Заполните и отправьте анкету продавца.']);
            exit;
        }
    }

    // Извлекаем данные из анкеты
    $formData = json_decode($form['data_json'] ?? '{}', true);
    if (!is_array($formData)) {
        $formData = [];
    }

    // Получаем описание деятельности и продукцию/услуги для определения сектора
    $activityDescription = $formData['activity_description'] ?? $form['activity_description'] ?? '';
    $productsServices = $formData['products_services'] ?? $form['products_services'] ?? '';
    
    // Определяем сектор через ИИ
    $sector = determineSector($activityDescription, $productsServices, $apiKey);
    
    // Извлекаем финансовые показатели за последний фактический период
    $financialData = extractFinancialData($form);
    
    if (isset($financialData['error'])) {
        echo json_encode(['success' => false, 'message' => $financialData['error']]);
        exit;
    }
    
    // Рассчитываем оценку по мультипликаторам
    $valuation = calculateMultiplierValuation($sector, $financialData);
    
    // Сохраняем результаты расчета в БД
    $dataJson = !empty($form['data_json']) ? json_decode($form['data_json'], true) : [];
    if (!is_array($dataJson)) {
        $dataJson = [];
    }
    
    $dataJson['multiplier_valuation'] = [
        'sector' => $sector,
        'financial_data' => $financialData,
        'valuation' => $valuation,
        'calculated_at' => date('c'),
    ];
    
    $updatedDataJson = json_encode($dataJson, JSON_UNESCAPED_UNICODE);
    $effectiveUserId = getEffectiveUserId();
    $updateStmt = $pdo->prepare("
        UPDATE seller_forms 
        SET data_json = ?, 
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $updateStmt->execute([$updatedDataJson, $form['id'], $effectiveUserId]);
    
    echo json_encode([
        'success' => true,
        'sector' => $sector,
        'financial_data' => $financialData,
        'valuation' => $valuation,
    ]);
    
} catch (Exception $e) {
    error_log('Multiplier valuation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка при расчете оценки: ' . $e->getMessage()]);
}

/**
 * Вспомогательная функция для извлечения значения из массива по нескольким возможным ключам.
 * 
 * @param array $row Массив данных
 * @param array $keys Массив возможных ключей для поиска
 * @return string Найденное значение или пустая строка
 */
function pickValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return (string)$row[$key];
        }
    }
    return '';
}

/**
 * Конвертирует финансовые данные в унифицированный формат.
 * Упрощенная версия convertFinancialRows из dashboard.php
 * 
 * @param array $financial Финансовые данные
 * @return array Конвертированные данные
 */
function convertFinancialRowsSimple(array $financial): array
{
    if (empty($financial)) {
        return [];
    }
    
    // Если данные уже в старом формате (с полем 'metric'), возвращаем как есть
    $first = reset($financial);
    if (is_array($first) && isset($first['metric'])) {
        return $financial;
    }
    
    // Маппинг ключей нового формата на названия метрик
    $map = [
        'revenue' => 'Выручка',
        'cost_of_sales' => 'Себестоимость продаж',
        'commercial_expenses' => 'Коммерческие расходы',
        'management_expenses' => 'Управленческие расходы',
        'sales_profit' => 'Прибыль от продаж',
        'depreciation' => 'Амортизация',
        'net_profit' => 'Чистая прибыль',
    ];
    
    // Поля для извлечения значений (поддержка разных форматов ключей)
    $fields = [
        'fact_2022' => ['fact_2022', '2022_fact'],
        'fact_2023' => ['fact_2023', '2023_fact'],
        'fact_2024' => ['fact_2024', '2024_fact'],
        'fact_2025' => ['fact_2025', '2025_fact', '2025_q3_fact', '2025_9m_fact', '2025_budget'],
        'budget_2026' => ['budget_2026', '2026_budget'],
    ];
    
    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($financial[$key]) || !is_array($financial[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        // Сохраняем единицы измерения из исходных данных
        $row['unit'] = $financial[$key]['unit'] ?? '';
        foreach ($fields as $legacyKey => $aliases) {
            $value = pickValue($financial[$key], $aliases);
            $row[$legacyKey] = $value !== '' ? $value : null;
        }
        $result[] = $row;
    }
    
    return $result;
}

/**
 * Конвертирует балансовые данные в унифицированный формат.
 * Упрощенная версия convertBalanceRows из dashboard.php
 * 
 * @param array $balance Балансовые данные
 * @return array Конвертированные данные
 */
function convertBalanceRowsSimple(array $balance): array
{
    if (empty($balance)) {
        return [];
    }
    
    // Если данные уже в старом формате (с полем 'metric'), возвращаем как есть
    $first = reset($balance);
    if (is_array($first) && isset($first['metric'])) {
        return $balance;
    }
    
    // Маппинг ключей нового формата на названия метрик
    $map = [
        'fixed_assets' => 'Основные средства',
        'inventory' => 'Запасы',
        'receivables' => 'Дебиторская задолженность',
        'payables' => 'Кредиторская задолженность',
        'short_term_loans' => 'Краткосрочные займы',
        'long_term_loans' => 'Долгосрочные займы',
        'cash' => 'Денежные средства',
        'net_assets' => 'Чистые активы',
    ];
    
    // Поля для извлечения значений (поддержка разных форматов ключей)
    $fields = [
        'fact_2022' => ['fact_2022', '2022_fact'],
        'fact_2023' => ['fact_2023', '2023_fact'],
        'fact_2024' => ['fact_2024', '2024_fact'],
        'fact_2025' => ['fact_2025', '2025_fact', '2025_q3_fact', '2025_9m_fact', '2025_budget'],
        'budget_2026' => ['budget_2026', '2026_budget'],
    ];
    
    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($balance[$key]) || !is_array($balance[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        // Сохраняем единицы измерения из исходных данных
        $row['unit'] = $balance[$key]['unit'] ?? '';
        foreach ($fields as $legacyKey => $aliases) {
            $value = pickValue($balance[$key], $aliases);
            $row[$legacyKey] = $value !== '' ? $value : null;
        }
        $result[] = $row;
    }
    
    return $result;
}

/**
 * Определяет сектор компании через ИИ на основе описания деятельности и продукции/услуг.
 * 
 * @param string $activityDescription Описание деятельности компании
 * @param string $productsServices Описание продукции/услуг
 * @param string $apiKey API ключ для Together.ai
 * @return string Определенный сектор
 */
function determineSector(string $activityDescription, string $productsServices, string $apiKey): string
{
    // Список возможных секторов
    $sectors = [
        'Горнодобывающая и металлургия',
        'Здравоохранение',
        'Коммунальные услуги',
        'Логистика',
        'Недвижимость',
        'Нефтегаз',
        'Потребительские товары',
        'Ритейл',
        'Сельское хозяйство',
        'Сфера услуг',
        'TMT',
        'Тяжёлая промышленность',
        'Финансовый сектор',
        'Средний по рынку',
    ];
    
    // Формируем промпт для ИИ
    $prompt = "Определи сектор экономики для компании на основе следующей информации:\n\n";
    $prompt .= "Описание деятельности: " . ($activityDescription ?: 'не указано') . "\n\n";
    $prompt .= "Продукция/услуги: " . ($productsServices ?: 'не указано') . "\n\n";
    $prompt .= "Выбери ОДИН сектор из следующего списка:\n";
    foreach ($sectors as $sector) {
        $prompt .= "- " . $sector . "\n";
    }
    $prompt .= "\nОтветь ТОЛЬКО названием сектора, без дополнительных пояснений.";
    
    // Вызываем ИИ
    $response = callTogetherAI($prompt, $apiKey);
    
    // Очищаем ответ и проверяем, соответствует ли он одному из секторов
    $response = trim($response);
    $response = preg_replace('/[^\p{L}\s\-]/u', '', $response); // Удаляем знаки препинания
    
    // Ищем совпадение с одним из секторов (без учета регистра)
    foreach ($sectors as $sector) {
        if (stripos($response, $sector) !== false || stripos($sector, $response) !== false) {
            return $sector;
        }
    }
    
    // Если точного совпадения нет, проверяем ключевые слова
    $responseLower = mb_strtolower($response);
    
    if (stripos($responseLower, 'tmt') !== false || 
        stripos($responseLower, 'технологи') !== false || 
        stripos($responseLower, 'интернет') !== false ||
        stripos($responseLower, 'телеком') !== false ||
        stripos($responseLower, 'медиа') !== false) {
        return 'TMT';
    }
    
    if (stripos($responseLower, 'финанс') !== false || 
        stripos($responseLower, 'банк') !== false ||
        stripos($responseLower, 'страхован') !== false) {
        return 'Финансовый сектор';
    }
    
    if (stripos($responseLower, 'ритейл') !== false || 
        stripos($responseLower, 'розничн') !== false ||
        stripos($responseLower, 'магазин') !== false) {
        return 'Ритейл';
    }
    
    if (stripos($responseLower, 'логистик') !== false || 
        stripos($responseLower, 'транспорт') !== false ||
        stripos($responseLower, 'доставк') !== false) {
        return 'Логистика';
    }
    
    if (stripos($responseLower, 'сельск') !== false || 
        stripos($responseLower, 'агро') !== false ||
        stripos($responseLower, 'ферм') !== false) {
        return 'Сельское хозяйство';
    }
    
    if (stripos($responseLower, 'здрав') !== false || 
        stripos($responseLower, 'медицин') !== false ||
        stripos($responseLower, 'клиник') !== false) {
        return 'Здравоохранение';
    }
    
    if (stripos($responseLower, 'недвижим') !== false || 
        stripos($responseLower, 'нефтегаз') !== false ||
        stripos($responseLower, 'нефть') !== false ||
        stripos($responseLower, 'газ') !== false) {
        if (stripos($responseLower, 'нефтегаз') !== false || 
            stripos($responseLower, 'нефть') !== false ||
            stripos($responseLower, 'газ') !== false) {
            return 'Нефтегаз';
        }
        return 'Недвижимость';
    }
    
    // По умолчанию возвращаем "Средний по рынку"
    return 'Средний по рынку';
}

/**
 * Вызывает Together.ai API для определения сектора.
 * 
 * @param string $prompt Промпт для ИИ
 * @param string $apiKey API ключ
 * @return string Ответ ИИ
 */
function callTogetherAI(string $prompt, string $apiKey): string
{
    $url = 'https://api.together.xyz/v1/chat/completions';
    
    $data = [
        'model' => TOGETHER_MODEL ?? 'meta-llama/Llama-3-8b-chat-hf',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты помощник для классификации компаний по секторам экономики. Отвечай только названием сектора из предложенного списка, без дополнительных пояснений.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.3,
        'max_tokens' => 50,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Together.ai API error: HTTP $httpCode, Response: $response");
        return 'Средний по рынку'; // Возвращаем значение по умолчанию при ошибке
    }
    
    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        return 'Средний по рынку';
    }
    
    return trim($result['choices'][0]['message']['content']);
}

/**
 * Извлекает финансовые данные из анкеты продавца.
 * Использует ту же логику, что и extractFinancialAndBalance в dashboard.php
 * 
 * @param array $form Данные анкеты из базы данных
 * @return array Массив с финансовыми показателями или ошибкой
 */
function extractFinancialData(array $form): array
{
    // Используем ту же логику извлечения, что и в dashboard.php
    // Пытаемся получить данные из старых полей
    $financial = json_decode($form['financial_results'] ?? '[]', true);
    $balance   = json_decode($form['balance_indicators'] ?? '[]', true);
    
    // Если старые поля пусты, пытаемся получить из data_json (новый формат)
    if (!empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        if (empty($financial) && isset($decoded['financial']) && is_array($decoded['financial'])) {
            $financial = $decoded['financial'];
        }
        if (empty($balance) && isset($decoded['balance']) && is_array($decoded['balance'])) {
            $balance = $decoded['balance'];
        }
    }
    
    if (empty($financial) || !is_array($financial)) {
        return ['error' => 'Финансовые данные не найдены в анкете.'];
    }
    
    // Конвертируем финансовые данные в унифицированный формат (если нужно)
    $financial = convertFinancialRowsSimple($financial);
    
    // Преобразуем в ассоциативный массив по метрикам
    $finRows = [];
    foreach ($financial as $row) {
        if (isset($row['metric'])) {
            $finRows[$row['metric']] = $row;
        }
    }
    
    // Извлекаем выручку за последний фактический период (2025)
    $revenue = null;
    if (isset($finRows['Выручка'])) {
        $revenueRow = $finRows['Выручка'];
        // Определяем единицы измерения для выручки
        $revenueUnit = detectUnit($revenueRow['unit'] ?? '');
        // Приоритет: fact_2025, затем fact_2024, затем fact_2023
        $revenueRaw = null;
        if (!empty($revenueRow['fact_2025'])) {
            $revenueRaw = $revenueRow['fact_2025'];
        } elseif (!empty($revenueRow['fact_2024'])) {
            $revenueRaw = $revenueRow['fact_2024'];
        } elseif (!empty($revenueRow['fact_2023'])) {
            $revenueRaw = $revenueRow['fact_2023'];
        }
        // Конвертируем в миллионы рублей с учетом единиц
        if ($revenueRaw !== null) {
            $revenue = convertToMillions($revenueRaw, $revenueUnit);
        }
    }
    
    if ($revenue === null || $revenue <= 0) {
        return ['error' => 'Не указана выручка за последний фактический период.'];
    }
    
    // Извлекаем прибыль от продаж
    $operatingProfit = null;
    if (isset($finRows['Прибыль от продаж'])) {
        $profitRow = $finRows['Прибыль от продаж'];
        // Определяем единицы измерения для прибыли
        $profitUnit = detectUnit($profitRow['unit'] ?? '');
        // Приоритет: fact_2025, затем fact_2024, затем fact_2023
        $profitRaw = null;
        if (!empty($profitRow['fact_2025'])) {
            $profitRaw = $profitRow['fact_2025'];
        } elseif (!empty($profitRow['fact_2024'])) {
            $profitRaw = $profitRow['fact_2024'];
        } elseif (!empty($profitRow['fact_2023'])) {
            $profitRaw = $profitRow['fact_2023'];
        }
        // Конвертируем в миллионы рублей с учетом единиц
        if ($profitRaw !== null) {
            $operatingProfit = convertToMillions($profitRaw, $profitUnit);
        }
    }
    
    // Если прибыль от продаж не найдена, пытаемся рассчитать
    if ($operatingProfit === null) {
        $cogs = 0;
        $commercial = 0;
        $admin = 0;
        
        if (isset($finRows['Себестоимость продаж'])) {
            $cogsRow = $finRows['Себестоимость продаж'];
            $cogsUnit = detectUnit($cogsRow['unit'] ?? '');
            $cogsRaw = $cogsRow['fact_2025'] ?? $cogsRow['fact_2024'] ?? $cogsRow['fact_2023'] ?? null;
            $cogs = $cogsRaw !== null ? convertToMillions($cogsRaw, $cogsUnit) : 0;
        }
        
        if (isset($finRows['Коммерческие расходы'])) {
            $commercialRow = $finRows['Коммерческие расходы'];
            $commercialUnit = detectUnit($commercialRow['unit'] ?? '');
            $commercialRaw = $commercialRow['fact_2025'] ?? $commercialRow['fact_2024'] ?? $commercialRow['fact_2023'] ?? null;
            $commercial = $commercialRaw !== null ? convertToMillions($commercialRaw, $commercialUnit) : 0;
        }
        
        if (isset($finRows['Управленческие расходы'])) {
            $adminRow = $finRows['Управленческие расходы'];
            $adminUnit = detectUnit($adminRow['unit'] ?? '');
            $adminRaw = $adminRow['fact_2025'] ?? $adminRow['fact_2024'] ?? $adminRow['fact_2023'] ?? null;
            $admin = $adminRaw !== null ? convertToMillions($adminRaw, $adminUnit) : 0;
        }
        
        // Прибыль от продаж = Выручка - Себестоимость - Коммерческие - Управленческие
        $operatingProfit = $revenue - $cogs - $commercial - $admin;
    }
    
    // Извлекаем амортизацию
    $depreciation = 0;
    if (isset($finRows['Амортизация'])) {
        $deprRow = $finRows['Амортизация'];
        $deprUnit = detectUnit($deprRow['unit'] ?? '');
        // Приоритет: fact_2025, затем fact_2024, затем fact_2023
        $deprRaw = $deprRow['fact_2025'] ?? $deprRow['fact_2024'] ?? $deprRow['fact_2023'] ?? null;
        $depreciation = $deprRaw !== null ? convertToMillions($deprRaw, $deprUnit) : 0;
    }
    
    // Конвертируем балансовые данные в унифицированный формат (если нужно)
    if (!empty($balance) && is_array($balance)) {
        $balance = convertBalanceRowsSimple($balance);
    }
    
    // Преобразуем баланс в ассоциативный массив по метрикам
    $balRows = [];
    if (!empty($balance) && is_array($balance)) {
        foreach ($balance as $row) {
            if (isset($row['metric'])) {
                $balRows[$row['metric']] = $row;
            }
        }
    }
    
    // Если амортизация не указана, пытаемся рассчитать как 10% от основных средств
    if ($depreciation <= 0 && !empty($balRows)) {
        if (isset($balRows['Основные средства'])) {
            $fixedAssetsRow = $balRows['Основные средства'];
            $fixedAssetsUnit = detectUnit($fixedAssetsRow['unit'] ?? '');
            // Приоритет: fact_2025, затем fact_2024, затем fact_2023
            $fixedAssetsRaw = $fixedAssetsRow['fact_2025'] ?? $fixedAssetsRow['fact_2024'] ?? $fixedAssetsRow['fact_2023'] ?? null;
            $fixedAssets = $fixedAssetsRaw !== null ? convertToMillions($fixedAssetsRaw, $fixedAssetsUnit) : 0;
            if ($fixedAssets > 0) {
                // Амортизация = 10% от основных средств предыдущего года
                // Для упрощения используем текущие основные средства
                $depreciation = $fixedAssets * 0.10;
            }
        }
    }
    
    // Извлекаем долг и денежные средства из баланса
    $debt = 0;
    $cash = 0;
    
    if (!empty($balRows)) {
        // Долг (краткосрочные + долгосрочные займы)
        if (isset($balRows['Краткосрочные займы'])) {
            $shortDebtRow = $balRows['Краткосрочные займы'];
            $shortDebtUnit = detectUnit($shortDebtRow['unit'] ?? '');
            // Приоритет: fact_2025, затем fact_2024, затем fact_2023
            $shortDebtRaw = $shortDebtRow['fact_2025'] ?? $shortDebtRow['fact_2024'] ?? $shortDebtRow['fact_2023'] ?? null;
            $debt += $shortDebtRaw !== null ? convertToMillions($shortDebtRaw, $shortDebtUnit) : 0;
        }
        if (isset($balRows['Долгосрочные займы'])) {
            $longDebtRow = $balRows['Долгосрочные займы'];
            $longDebtUnit = detectUnit($longDebtRow['unit'] ?? '');
            // Приоритет: fact_2025, затем fact_2024, затем fact_2023
            $longDebtRaw = $longDebtRow['fact_2025'] ?? $longDebtRow['fact_2024'] ?? $longDebtRow['fact_2023'] ?? null;
            $debt += $longDebtRaw !== null ? convertToMillions($longDebtRaw, $longDebtUnit) : 0;
        }
        
        // Денежные средства
        if (isset($balRows['Денежные средства'])) {
            $cashRow = $balRows['Денежные средства'];
            $cashUnit = detectUnit($cashRow['unit'] ?? '');
            // Приоритет: fact_2025, затем fact_2024, затем fact_2023
            $cashRaw = $cashRow['fact_2025'] ?? $cashRow['fact_2024'] ?? $cashRow['fact_2023'] ?? null;
            $cash = $cashRaw !== null ? convertToMillions($cashRaw, $cashUnit) : 0;
        }
    }
    
    // Для финансового сектора нужна чистая прибыль
    $netProfit = null;
    if (isset($finRows['Чистая прибыль'])) {
        $netProfitRow = $finRows['Чистая прибыль'];
        $netProfitUnit = detectUnit($netProfitRow['unit'] ?? '');
        // Приоритет: fact_2025, затем fact_2024, затем fact_2023
        $netProfitRaw = $netProfitRow['fact_2025'] ?? $netProfitRow['fact_2024'] ?? $netProfitRow['fact_2023'] ?? null;
        $netProfit = $netProfitRaw !== null ? convertToMillions($netProfitRaw, $netProfitUnit) : null;
    }
    
    return [
        'revenue' => $revenue,
        'operating_profit' => $operatingProfit,
        'depreciation' => $depreciation,
        'ebitda' => $operatingProfit + $depreciation, // Прибыль от продаж + амортизация
        'debt' => $debt,
        'cash' => $cash,
        'net_profit' => $netProfit,
    ];
}

/**
 * Рассчитывает оценку компании по методу мультипликаторов.
 * 
 * Алгоритм расчета:
 * - Для нефинансовых секторов:
 *   EV₁ = Выручка × EV/Выручка
 *   EV₂ = (Прибыль от продаж + амортизация) × EV/Прибыль от продаж
 *   Итоговая EV = среднее из EV₁ и EV₂
 *   Equity Value = EV - Долг + Денежные средства
 * 
 * - Для финансового сектора:
 *   Equity Value = Чистая прибыль × P/E
 * 
 * @param string $sector Сектор компании
 * @param array $financialData Финансовые данные
 * @return array Результаты расчета оценки
 */
function calculateMultiplierValuation(string $sector, array $financialData): array
{
    // Мультипликаторы по секторам
    // Формат: [EV/Выручка, EV/Прибыль от продаж]
    $multipliers = [
        'Средний по рынку' => [0.9, 4.8],
        'TMT' => [2.0, 6.1],
        'Сфера услуг' => [1.8, 5.5],
        'Логистика' => [1.6, 5.4],
        'Сельское хозяйство' => [0.9, 5.2],
        'Ритейл' => [0.5, 5.0],
        'Потребительские товары' => [0.5, 4.3],
        'Тяжёлая промышленность' => [0.4, 3.8],
        'Финансовый сектор' => null, // Используется только P/E = 7.3
    ];
    
    $revenue = $financialData['revenue'];
    $operatingProfit = $financialData['operating_profit'];
    $depreciation = $financialData['depreciation'];
    $debt = $financialData['debt'];
    $cash = $financialData['cash'];
    $netProfit = $financialData['net_profit'];
    
    $appliedMultipliers = [];
    $equityValue = null;
    
    // Для финансового сектора используется только P/E
    if ($sector === 'Финансовый сектор') {
        if ($netProfit === null || $netProfit <= 0) {
            return [
                'error' => 'Для финансового сектора необходимо указать чистую прибыль.',
                'sector' => $sector,
                'applied_multipliers' => [],
            ];
        }
        
        $peMultiplier = 7.3;
        $equityValue = $netProfit * $peMultiplier;
        
        $appliedMultipliers = [
            'P/E' => $peMultiplier,
        ];
    } else {
        // Для остальных секторов используем EV/Выручка и EV/Прибыль от продаж
        $sectorMultipliers = $multipliers[$sector] ?? $multipliers['Средний по рынку'];
        
        if ($sectorMultipliers === null) {
            $sectorMultipliers = $multipliers['Средний по рынку'];
        }
        
        $evRevenueMultiplier = $sectorMultipliers[0];
        $evOperatingProfitMultiplier = $sectorMultipliers[1];
        
        // EV₁ = Выручка × EV/Выручка
        $ev1 = $revenue * $evRevenueMultiplier;
        
        // EV₂ = (Прибыль от продаж + амортизация) × EV/Прибыль от продаж
        $operatingProfitPlusDepreciation = $operatingProfit + $depreciation;
        $ev2 = $operatingProfitPlusDepreciation * $evOperatingProfitMultiplier;
        
        // Итоговая EV = среднее из EV₁ и EV₂
        $ev = ($ev1 + $ev2) / 2;
        
        // Итоговая стоимость (Equity Value) = EV - Долг + Денежные средства
        $equityValue = $ev - $debt + $cash;
        
        $appliedMultipliers = [
            'EV/Выручка' => $evRevenueMultiplier,
            'EV/Прибыль от продаж' => $evOperatingProfitMultiplier,
        ];
    }
    
    return [
        'sector' => $sector,
        'applied_multipliers' => $appliedMultipliers,
        'equity_value' => $equityValue,
        'ev' => isset($ev) ? $ev : null,
        'ev1' => isset($ev1) ? $ev1 : null,
        'ev2' => isset($ev2) ? $ev2 : null,
    ];
}

