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
 * 4. callAICompletions() — отправляет запрос в выбранный AI провайдер (Together.ai или Alibaba Cloud) и возвращает текст ответа модели.
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

/**
 * Класс для детального логирования процесса генерации тизеров
 */
class TeaserLogger
{
    private $logFile = null;
    private $logDir = null;
    private $sessionStartTime = null;
    private $formId = null;
    
    /**
     * Начинает сессию логирования для конкретного form_id
     */
    public function startSession($formId): void
    {
        $this->formId = $formId;
        $this->sessionStartTime = microtime(true);
        
        // Создаем директорию для логов, если её нет
        $this->logDir = __DIR__ . '/logs/teaser';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        
        // Создаем имя файла с timestamp
        $timestamp = date('Y-m-d_H-i-s');
        $this->logFile = $this->logDir . '/teaser_' . $timestamp . '.log';
        
        // Открываем файл для записи
        $this->log("========================================");
        $this->log("Teaser Generation Started");
        $this->log("Form ID: " . ($formId ?? 'unknown'));
        $this->log("Timestamp: " . date('Y-m-d H:i:s'));
        $this->log("========================================");
    }
    
    /**
     * Логирует этап процесса с данными
     */
    public function logStep(string $stepName, $data = null, string $level = 'INFO'): void
    {
        $stepStartTime = microtime(true);
        
        $this->log("Step: " . $stepName, $level);
        
        if ($data !== null) {
            if (is_array($data)) {
                // Логируем структуру массива (ключи, размеры, ключевые поля)
                $summary = $this->summarizeArray($data);
                if (!empty($summary)) {
                    $this->log("Input/Output: " . $summary, $level);
                }
            } else {
                $this->log("Data: " . (string)$data, $level);
            }
        }
        
        if ($this->sessionStartTime !== null) {
            $duration = round(microtime(true) - $stepStartTime, 3);
            $this->log("Duration: " . $duration . "s", $level);
        }
    }
    
    /**
     * Логирует ошибку с деталями исключения
     */
    public function logError(string $message, $exception = null): void
    {
        $this->log("ERROR: " . $message, 'ERROR');
        
        if ($exception instanceof Exception || $exception instanceof Throwable) {
            $this->log("Error Type: " . get_class($exception), 'ERROR');
            $this->log("Error Message: " . $exception->getMessage(), 'ERROR');
            $this->log("Error File: " . $exception->getFile() . ":" . $exception->getLine(), 'ERROR');
            
            // Логируем trace, но ограничиваем его размер
            $trace = $exception->getTraceAsString();
            if (strlen($trace) > 2000) {
                $trace = substr($trace, 0, 2000) . "... (truncated)";
            }
            $this->log("Error Trace: " . $trace, 'ERROR');
        }
    }
    
    /**
     * Специальный метод для логирования DCF данных
     */
    public function logDCF($dcfData): void
    {
        if ($dcfData === null) {
            $this->log("DCF Data: null", 'WARNING');
            return;
        }
        
        if (!is_array($dcfData)) {
            $this->log("DCF Data: invalid type (" . gettype($dcfData) . ")", 'WARNING');
            return;
        }
        
        // Логируем структуру результата
        $resultKeys = array_keys($dcfData);
        $this->log("DCF Result Structure: keys=[" . implode(', ', $resultKeys) . "]", 'INFO');
        
        // Проверяем наличие ошибки
        if (isset($dcfData['error'])) {
            $this->log("DCF Error: " . ($dcfData['error'] ?? 'unknown'), 'ERROR');
            return;
        }
        
        $rowsCount = isset($dcfData['rows']) && is_array($dcfData['rows']) ? count($dcfData['rows']) : 0;
        $this->log("DCF Data: rows_count=" . $rowsCount, 'INFO');
        
        // Если rows_count=0, логируем детали для диагностики
        if ($rowsCount === 0) {
            $this->log("DCF Data: WARNING - rows_count=0. This means DCF calculation returned data but with empty rows array.", 'WARNING');
            $this->log("DCF Data: Available keys in result: " . implode(', ', $resultKeys), 'WARNING');
            
            // Логируем другие поля, которые могут быть в результате
            if (isset($dcfData['columns'])) {
                $columnsCount = is_array($dcfData['columns']) ? count($dcfData['columns']) : 0;
                $this->log("DCF Data: columns_count=" . $columnsCount, 'WARNING');
            }
            if (isset($dcfData['warnings'])) {
                $warnings = is_array($dcfData['warnings']) ? $dcfData['warnings'] : [];
                $this->log("DCF Data: warnings=" . json_encode($warnings), 'WARNING');
            }
            if (isset($dcfData['valuation'])) {
                $this->log("DCF Data: valuation exists but rows are empty", 'WARNING');
            }
        }
        
        if (isset($dcfData['rows']) && is_array($dcfData['rows']) && $rowsCount > 0) {
            // Логируем доступные метрики
            $labels = [];
            foreach ($dcfData['rows'] as $row) {
                if (isset($row['label'])) {
                    $labels[] = $row['label'];
                }
            }
            $this->log("DCF Metrics: " . implode(', ', $labels), 'INFO');
            
            // Логируем детали для выручки и прибыли
            $revenueRow = null;
            $profitRow = null;
            foreach ($dcfData['rows'] as $row) {
                if (isset($row['label'])) {
                    $label = trim($row['label']);
                    if ($label === 'Выручка') {
                        $revenueRow = $row;
                    } elseif ($label === 'Прибыль от продаж') {
                        $profitRow = $row;
                    }
                }
            }
            
            if ($revenueRow) {
                $this->logDCFRow('Revenue', $revenueRow);
            } else {
                $this->log("DCF Revenue Row: not found", 'WARNING');
            }
            
            if ($profitRow) {
                $this->logDCFRow('Profit', $profitRow);
            } else {
                $this->log("DCF Profit Row: not found", 'WARNING');
            }
        } elseif (!isset($dcfData['rows'])) {
            $this->log("DCF Data: WARNING - 'rows' key is missing in DCF result", 'WARNING');
        }
    }
    
    /**
     * Логирует детали строки DCF данных
     */
    private function logDCFRow(string $rowType, array $row): void
    {
        if (!isset($row['values']) || !is_array($row['values'])) {
            $this->log("DCF " . $rowType . " Row: values not available", 'WARNING');
            return;
        }
        
        $values = $row['values'];
        $keys = array_keys($values);
        $nonNullCount = 0;
        $periodCounts = ['fact' => 0, 'forecast' => 0];
        
        foreach ($values as $key => $value) {
            if ($value !== null && is_numeric($value)) {
                $nonNullCount++;
                if (in_array($key, ['2022', '2023', '2024', '2025'])) {
                    $periodCounts['fact']++;
                } elseif (in_array($key, ['P1', 'P2', 'P3', 'P4', 'P5'])) {
                    $periodCounts['forecast']++;
                }
            }
        }
        
        $this->log("DCF " . $rowType . " Row: values_keys=[" . implode(', ', $keys) . "], non_null_count=" . $nonNullCount . ", fact_periods=" . $periodCounts['fact'] . ", forecast_periods=" . $periodCounts['forecast'], 'INFO');
    }
    
    /**
     * Завершает сессию логирования
     */
    public function endSession(): void
    {
        if ($this->sessionStartTime !== null) {
            $totalDuration = round(microtime(true) - $this->sessionStartTime, 3);
            $this->log("Total Duration: " . $totalDuration . "s", 'INFO');
        }
        
        $this->log("========================================");
        $this->log("Teaser Generation Completed");
        $this->log("========================================");
    }
    
    /**
     * Метод для записи в лог-файл
     */
    public function log(string $message, string $level = 'INFO'): void
    {
        if ($this->logFile === null) {
            return; // Сессия не начата
        }
        
        $timestamp = date('Y-m-d H:i:s.v');
        $logLine = "[" . $timestamp . "] [" . $level . "] " . $message . PHP_EOL;
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Создает краткое описание массива для логирования
     */
    private function summarizeArray(array $data): string
    {
        $keys = array_keys($data);
        $summary = "keys=[" . implode(', ', $keys) . "]";
        
        // Добавляем размеры для больших массивов
        $size = strlen(json_encode($data));
        if ($size > 1024) {
            $summary .= ", size=" . round($size / 1024, 2) . "KB";
        }
        
        // Добавляем ключевые поля, если они есть
        $keyFields = ['form_id', 'id', 'asset_name', 'rows_count', 'payload_size'];
        $keyValues = [];
        foreach ($keyFields as $field) {
            if (isset($data[$field])) {
                $keyValues[] = $field . "=" . (is_scalar($data[$field]) ? $data[$field] : gettype($data[$field]));
            }
        }
        if (!empty($keyValues)) {
            $summary .= ", " . implode(', ', $keyValues);
        }
        
        return $summary;
    }
}

// Если мы в режиме загрузки только функций (для dashboard.php), не выполняем основной код
if (!defined('TEASER_FUNCTIONS_ONLY') || !TEASER_FUNCTIONS_ONLY) {
    // Отключаем вывод ошибок PHP в ответ, чтобы не ломать JSON
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
    
    // Включаем буферизацию вывода, чтобы перехватить любые случайные выводы
    ob_start();
    
    // Устанавливаем заголовок JSON до любого вывода
    header('Content-Type: application/json; charset=utf-8');

    if (!isLoggedIn()) {
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
        ob_end_flush();
        exit;
    }

    $user = getCurrentUser();
    if (!$user) {
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Сессия недействительна.']);
        ob_end_flush();
        exit;
    }

$requestPayload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($requestPayload)) {
    $requestPayload = [];
}
$action = $requestPayload['action'] ?? 'teaser';

    // Получаем API ключ в зависимости от выбранного провайдера
    $provider = getCurrentAIProvider();
    if ($provider === 'alibaba') {
        $apiKey = ALIBABA_API_KEY;
        if (empty($apiKey)) {
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'API-ключ Alibaba Cloud не настроен.']);
            ob_end_flush();
            exit;
        }
    } else {
$apiKey = TOGETHER_API_KEY;
if (empty($apiKey)) {
            ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API-ключ together.ai не настроен.']);
            ob_end_flush();
    exit;
        }
}

    $pdo = getDBConnection();

    // Получаем form_id из запроса, если он передан
    // Используем уже прочитанный $requestPayload, так как php://input можно прочитать только один раз
    $requestedFormId = isset($requestPayload['form_id']) ? (int)$requestPayload['form_id'] : null;

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
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Анкета не найдена или не принадлежит вам.']);
            ob_end_flush();
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
            ob_clean();
        echo json_encode(['success' => false, 'message' => 'Нет отправленных анкет для формирования тизера.']);
            ob_end_flush();
        exit;
        }
    }

    // Логируем содержимое data_json сразу после загрузки формы из БД для диагностики
    if (!empty($form['data_json'])) {
        $formDataJson = json_decode($form['data_json'], true);
        if (is_array($formDataJson)) {
            $dataJsonKeys = array_keys($formDataJson);
            error_log('generate_teaser.php: form loaded from DB - data_json contains keys: ' . implode(', ', array_slice($dataJsonKeys, 0, 30)));
            if (isset($formDataJson['final_price'])) {
                error_log('generate_teaser.php: form loaded from DB - data_json contains final_price=' . $formDataJson['final_price']);
            } else {
                error_log('generate_teaser.php: form loaded from DB - data_json does NOT contain final_price');
            }
        } else {
            error_log('generate_teaser.php: form loaded from DB - data_json is not a valid JSON array');
        }
    } else {
        error_log('generate_teaser.php: form loaded from DB - data_json is empty or null');
    }

    // Определяем тип компании для условной генерации тизера
    $companyType = $form['company_type'] ?? null;
    $isStartup = ($companyType === 'startup');

    /**
     * Проверяет, заполнены ли все обязательные поля анкеты
     * 
     * @param array $form Данные анкеты из БД
     * @param bool $isStartup Является ли компания стартапом
     * @return array ['valid' => bool, 'missing_fields' => array] Результат проверки
     */
    function validateRequiredFields(array $form, bool $isStartup = false): array
    {
        // Для стартапов проверяем только минимально необходимые поля
        if ($isStartup) {
            $requiredFields = [
                'company_inn',
                'asset_name',
                'deal_share_range',
                'deal_goal',
                'asset_disclosure',
            ];
        } else {
            // Для зрелых компаний проверяем все поля
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
            ];
        }
        
        $missingFields = [];
        
        // Извлекаем данные из data_json или из отдельных полей
        $formData = [];
        if (!empty($form['data_json'])) {
            $decoded = json_decode($form['data_json'], true);
            if (is_array($decoded)) {
                $formData = $decoded;
            }
        }
        
        // Проверяем каждое обязательное поле
        foreach ($requiredFields as $field) {
            $value = $formData[$field] ?? $form[$field] ?? null;
            
            // Проверяем, что значение не пустое
            if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
                $missingFields[] = $field;
            }
        }
        
        // Проверяем наличие финансовых данных только для зрелых компаний
        if (!$isStartup) {
            $hasFinancialData = false;
            if (!empty($form['financial_results'])) {
                $financialData = json_decode($form['financial_results'], true);
                if (is_array($financialData) && !empty($financialData)) {
                    // Проверяем, есть ли хотя бы один период с данными
                    foreach ($financialData as $key => $value) {
                        if (is_array($value) && !empty($value)) {
                            // Проверяем наличие хотя бы одного непустого значения
                            foreach ($value as $v) {
                                if ($v !== null && $v !== '' && $v !== 0) {
                                    $hasFinancialData = true;
                                    break 2;
                                }
                            }
                        } elseif (!is_array($value) && $value !== null && $value !== '' && $value !== 0) {
                            $hasFinancialData = true;
                            break;
                        }
                    }
                }
            }
            
            if (!$hasFinancialData) {
                $missingFields[] = 'financial_results';
            }
        }
        
        return [
            'valid' => empty($missingFields),
            'missing_fields' => $missingFields
        ];
    }
    
    // Проверяем заполненность всех обязательных полей перед генерацией тизера
    $validation = validateRequiredFields($form, $isStartup);
    if (!$validation['valid']) {
        $missingCount = count($validation['missing_fields']);
        $message = $isStartup 
            ? "Заполните минимально необходимые поля для генерации тизера стартапа."
            : "Анкета не полностью заполнена. Заполните все обязательные поля для генерации тизера.";
        if ($missingCount <= 3) {
            // Если пропущено немного полей, показываем какие именно
            $fieldLabels = [
                'company_inn' => 'ИНН компании',
                'asset_name' => 'Название актива',
                'deal_share_range' => 'Диапазон доли сделки',
                'deal_goal' => 'Цель сделки',
                'asset_disclosure' => 'Раскрытие названия',
                'company_description' => 'Описание компании',
                'presence_regions' => 'Регионы присутствия',
                'products_services' => 'Продукты и услуги',
                'main_clients' => 'Основные клиенты',
                'sales_share' => 'Доля продаж',
                'personnel_count' => 'Количество персонала',
                'financial_results_vat' => 'НДС в финансовых результатах',
                'financial_source' => 'Источник финансовых данных',
                'financial_results' => 'Финансовые результаты',
            ];
            $missingLabels = [];
            foreach ($validation['missing_fields'] as $field) {
                $missingLabels[] = $fieldLabels[$field] ?? $field;
            }
            $message .= " Пропущены: " . implode(', ', $missingLabels) . ".";
        }
        ob_clean();
        echo json_encode(['success' => false, 'message' => $message]);
        ob_end_flush();
        exit;
    }

    // Обертываем весь код генерации в try-catch для перехвата всех ошибок
    try {
        // Инициализация системы логирования
        $logger = new TeaserLogger();
        $logger->startSession($form['id'] ?? 'unknown');
        $logger->logStep('start', ['form_id' => $form['id'] ?? 'unknown', 'action' => $action ?? 'teaser']);
        
        // ВАЖНО: Перезагружаем форму из БД непосредственно перед генерацией тизера,
        // чтобы получить самую свежую версию data_json (включая обновленную final_price)
        // Это гарантирует, что если пользователь изменил цену после загрузки страницы,
        // но до генерации тизера, то новая цена будет использована
        $formId = $form['id'];
        $effectiveUserId = getEffectiveUserId();
        $refreshStmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ? AND user_id = ?");
        $refreshStmt->execute([$formId, $effectiveUserId]);
        $refreshedForm = $refreshStmt->fetch();
        if ($refreshedForm) {
            $form = $refreshedForm; // Используем свежую версию формы с актуальным data_json
            $logger->log('Form refreshed from DB before teaser generation to get latest data_json', 'INFO');
        }
        
        // Логируем содержимое data_json сразу после перезагрузки формы из БД для диагностики
        if (!empty($form['data_json'])) {
            $formDataJson = json_decode($form['data_json'], true);
            if (is_array($formDataJson)) {
                $dataJsonKeys = array_keys($formDataJson);
                $logger->log('Form loaded from DB - data_json contains keys: ' . implode(', ', array_slice($dataJsonKeys, 0, 30)), 'INFO');
                if (isset($formDataJson['final_price'])) {
                    $logger->log('Form loaded from DB - data_json contains final_price=' . $formDataJson['final_price'], 'INFO');
                } else {
                    $logger->log('Form loaded from DB - data_json does NOT contain final_price', 'WARNING');
                }
            } else {
                $logger->log('Form loaded from DB - data_json is not a valid JSON array', 'WARNING');
            }
        } else {
            $logger->log('Form loaded from DB - data_json is empty or null', 'WARNING');
        }
        
        error_log('Teaser generation started for form_id: ' . ($form['id'] ?? 'unknown'));
        
        // Проверяем, что функция существует
        if (!function_exists('buildTeaserPayload')) {
            throw new RuntimeException('Function buildTeaserPayload not found');
        }
        
        // Логируем входные данные формы (структуру, без полных дампов)
        $formInputSummary = [
            'form_id' => $form['id'] ?? null,
            'has_financial_data' => !empty($form['financial_results']) || !empty($form['balance_indicators']) || !empty($form['data_json']),
            'form_keys' => array_keys($form),
        ];
        $logger->logStep('buildTeaserPayload', ['input' => $formInputSummary]);

    $formPayload = buildTeaserPayload($form);
        
        // Логируем выходной payload (структуру и размер)
        $payloadSummary = [
            'payload_keys' => array_keys($formPayload),
            'payload_size' => round(strlen(json_encode($formPayload)) / 1024, 2) . 'KB',
        ];
        $logger->logStep('buildTeaserPayload', ['output' => $payloadSummary]);
        
        error_log('buildTeaserPayload completed');
        
        // Создаем маскированную версию payload для тизера
        // Это промежуточная переменная, которая НЕ сохраняется в анкету
        // Она используется только для генерации тизера и не изменяет исходные данные анкеты
        if (!function_exists('buildMaskedTeaserPayload')) {
            throw new RuntimeException('Function buildMaskedTeaserPayload not found');
        }
        $maskedPayload = buildMaskedTeaserPayload($formPayload);
        $logger->logStep('buildMaskedTeaserPayload', ['masked_payload_keys' => array_keys($maskedPayload)]);
        error_log('buildMaskedTeaserPayload completed');
    
    // Получаем данные DCF модели для графика
    // Используем упрощенную функцию для извлечения данных напрямую из формы
        if (!function_exists('extractDCFDataForChart')) {
            throw new RuntimeException('Function extractDCFDataForChart not found');
        }
        
        // Проверяем, что $form не пустой перед вызовом функции
        if (empty($form) || !is_array($form)) {
            error_log('Warning: $form is empty or not an array before extractDCFDataForChart');
            $dcfData = null;
            $logger->logStep('extractDCFDataForChart', ['error' => 'Form is empty or not an array'], 'WARNING');
        } else {
            // Логируем финансовые данные в форме перед вызовом extractDCFDataForChart
            $hasFinancialResults = !empty($form['financial_results']);
            $hasBalanceIndicators = !empty($form['balance_indicators']);
            $hasFinancialInJson = false;
            $financialDataInJson = null;
            if (!empty($form['data_json'])) {
                $formData = json_decode($form['data_json'], true);
                if (is_array($formData)) {
                    $hasFinancialInJson = !empty($formData['financial']);
                    if ($hasFinancialInJson) {
                        $financialDataInJson = is_array($formData['financial']) ? array_keys($formData['financial']) : ['non-array'];
                    }
                }
            }
            
            $logger->logStep('extractDCFDataForChart', [
                'input' => [
                    'form_id' => $form['id'] ?? 'unknown',
                    'has_form' => !empty($form) && is_array($form),
                    'financial_data_check' => [
                        'financial_results' => $hasFinancialResults ? 'yes' : 'no',
                        'balance_indicators' => $hasBalanceIndicators ? 'yes' : 'no',
                        'data_json_financial' => $hasFinancialInJson ? 'yes' : 'no',
                        'financial_keys_in_json' => $financialDataInJson,
                    ],
                ],
            ]);
            
            $dcfData = extractDCFDataForChart($form, $logger);
            // Детальное логирование DCF данных
            $logger->logDCF($dcfData);
        }
        error_log('extractDCFDataForChart completed');

    if ($action === 'investors') {
            // Для инвесторов используем маскированные данные
            $investorPool = buildInvestorPool($maskedPayload, $apiKey);
        if (empty($investorPool)) {
                ob_clean();
            echo json_encode(['success' => false, 'message' => 'Не найдены подходящие инвесторы.']);
                ob_end_flush();
            exit;
        }

        $html = renderInvestorSection($investorPool);
        $snapshot = [
            'html' => $html,
            'generated_at' => date('c'),
        ];
        persistInvestorSnapshot($form, $formPayload, $snapshot);

            // Очищаем буфер вывода перед отправкой JSON
            ob_clean();
        echo json_encode([
            'success' => true,
            'html' => $html,
            'generated_at' => $snapshot['generated_at'],
        ]);
            ob_end_flush();
        exit;
    }

        // Используем маскированные данные для генерации тизера
        if (!function_exists('buildTeaserPrompt')) {
            throw new RuntimeException('Function buildTeaserPrompt not found');
        }
        $prompt = buildTeaserPrompt($maskedPayload, $isStartup);
        $logger->logStep('buildTeaserPrompt', ['prompt_length' => strlen($prompt), 'prompt_size' => round(strlen($prompt) / 1024, 2) . 'KB', 'is_startup' => $isStartup]);
        error_log('buildTeaserPrompt completed, prompt length: ' . strlen($prompt));
        
        if (empty($prompt)) {
            throw new RuntimeException('Не удалось сформировать запрос к AI. Проверьте данные анкеты.');
        }
        
        if (!function_exists('callAICompletions')) {
            throw new RuntimeException('Function callAICompletions not found');
        }
        error_log('Calling AI API...');
        
        // Валидация промпта перед отправкой
        if (empty($prompt) || strlen(trim($prompt)) < 10) {
            throw new RuntimeException('Промпт слишком короткий или пустой');
        }
        
        try {
            $apiStartTime = microtime(true);
            $rawResponse = callAICompletions($prompt, $apiKey, 3); // 3 попытки с retry
            $apiDuration = round(microtime(true) - $apiStartTime, 3);
            $logger->logStep('callAICompletions', ['response_length' => strlen($rawResponse), 'response_size' => round(strlen($rawResponse) / 1024, 2) . 'KB', 'duration' => $apiDuration . 's']);
            error_log('AI API call completed, response length: ' . strlen($rawResponse));
        } catch (RuntimeException $e) {
            // Логируем детали ошибки для отладки
            $logger->logError('API call failed', $e);
            error_log('API call failed: ' . $e->getMessage());
            throw $e;
        }
        
        if (empty($rawResponse)) {
            throw new RuntimeException('Пустой ответ от AI. Попробуйте снова.');
        }
        
        // Проверяем, что ответ не слишком короткий (может быть обрезан)
        if (strlen($rawResponse) < 50) {
            $logger->logStep('callAICompletions', ['warning' => 'Very short response from AI'], 'WARNING');
            error_log('Warning: Very short response from AI: ' . substr($rawResponse, 0, 100));
        }
        
        // Логируем первые 200 символов ответа для отладки
        error_log('AI response preview: ' . substr($rawResponse, 0, 200));
        
        if (!function_exists('parseTeaserResponse')) {
            throw new RuntimeException('Function parseTeaserResponse not found');
        }
    $teaserData = parseTeaserResponse($rawResponse);
        
        // Логируем структуру полученных данных от AI
        $teaserDataSummary = [
            'blocks' => array_keys($teaserData),
            'has_overview' => !empty($teaserData['overview']),
            'has_financials' => !empty($teaserData['financials']),
            'has_products' => !empty($teaserData['products']),
        ];
        $logger->logStep('parseTeaserResponse', ['output' => $teaserDataSummary]);
        error_log('parseTeaserResponse completed');
        
        if (empty($teaserData) || !is_array($teaserData)) {
            throw new RuntimeException('Не удалось обработать ответ от AI. Попробуйте снова.');
        }
        
        if (!function_exists('normalizeTeaserData')) {
            throw new RuntimeException('Function normalizeTeaserData not found');
        }
        $teaserData = normalizeTeaserData($teaserData, $maskedPayload, $isStartup);
        $logger->logStep('normalizeTeaserData', ['blocks_after_normalization' => array_keys($teaserData), 'is_startup' => $isStartup]);
        error_log('normalizeTeaserData completed');
        
        if (!function_exists('ensureOverviewWithAi')) {
            throw new RuntimeException('Function ensureOverviewWithAi not found');
        }
        // Проверяем, нужно ли очищать текст от "Актив" (определяем один раз)
        $assetDisclosure = $maskedPayload['asset_disclosure'] ?? '';
        $isNameHidden = ($assetDisclosure === 'no' || $assetDisclosure === 'нет');
        
        $teaserData = ensureOverviewWithAi($teaserData, $maskedPayload, $apiKey);
        $logger->logStep('ensureOverviewWithAi', ['has_overview_summary' => !empty($teaserData['overview']['summary'])]);
        error_log('ensureOverviewWithAi completed');
        
        // Очищаем данные сразу после ensureOverviewWithAi (она делает дополнительный вызов к ИИ)
        if ($isNameHidden) {
            if (!function_exists('cleanTeaserDataFromHiddenCompanyReferences')) {
                throw new RuntimeException('Function cleanTeaserDataFromHiddenCompanyReferences not found');
            }
            $teaserData = cleanTeaserDataFromHiddenCompanyReferences($teaserData, true);
            error_log('cleanTeaserDataFromHiddenCompanyReferences after ensureOverviewWithAi completed');
        }
        
        if (!function_exists('ensureProductsLocalized')) {
            throw new RuntimeException('Function ensureProductsLocalized not found');
        }
        $teaserData = ensureProductsLocalized($teaserData, $maskedPayload, $apiKey);
        $logger->logStep('ensureProductsLocalized', ['has_products' => !empty($teaserData['products'])]);
        error_log('ensureProductsLocalized completed');
        
        // Очищаем данные сразу после ensureProductsLocalized (она делает дополнительный вызов к ИИ)
        if ($isNameHidden) {
            $teaserData = cleanTeaserDataFromHiddenCompanyReferences($teaserData, true);
            error_log('cleanTeaserDataFromHiddenCompanyReferences after ensureProductsLocalized completed');
        }
        
        // Очищаем фразы о развитии для cash-out сделок
        $isOnlyCashOut = isOnlyCashOut($maskedPayload['deal_goal'] ?? '');
        if ($isOnlyCashOut) {
            if (!function_exists('cleanTeaserDataFromCashOutDevelopmentPhrases')) {
                throw new RuntimeException('Function cleanTeaserDataFromCashOutDevelopmentPhrases not found');
            }
            $teaserData = cleanTeaserDataFromCashOutDevelopmentPhrases($teaserData, true);
            error_log('cleanTeaserDataFromCashOutDevelopmentPhrases completed');
        }
    
    // Генерируем краткое описание для hero блока из overview summary
        // Используем маскированные данные
        if (!function_exists('buildHeroDescription')) {
            throw new RuntimeException('Function buildHeroDescription not found');
        }
        $heroDescription = buildHeroDescription($teaserData, $maskedPayload);
        $logger->logStep('buildHeroDescription', ['hero_description_length' => strlen($heroDescription ?? '')]);
        error_log('buildHeroDescription completed');
        
        // Очищаем heroDescription и overview summary от нежелательных сочетаний
        // $isNameHidden уже определен выше
        if ($isNameHidden) {
            if (!function_exists('cleanHiddenCompanyText')) {
                throw new RuntimeException('Function cleanHiddenCompanyText not found');
            }
            $heroDescription = cleanHiddenCompanyText($heroDescription ?? '', true);
            
            // Очищаем overview summary в teaserData
            if (!empty($teaserData['overview']['summary'])) {
                $teaserData['overview']['summary'] = cleanHiddenCompanyText($teaserData['overview']['summary'], true);
            }
        }
        
        // Очищаем фразы о развитии для cash-out сделок в heroDescription
        $isOnlyCashOut = isOnlyCashOut($maskedPayload['deal_goal'] ?? '');
        if ($isOnlyCashOut) {
            if (!function_exists('cleanCashOutDevelopmentPhrases')) {
                throw new RuntimeException('Function cleanCashOutDevelopmentPhrases not found');
            }
            $heroDescription = cleanCashOutDevelopmentPhrases($heroDescription ?? '', true);
            error_log('cleanCashOutDevelopmentPhrases for heroDescription completed');
        }
        
        // Используем маскированное название актива для рендеринга
        // Для скрытых активов не показываем "Актив" вообще
        $displayAssetName = $maskedPayload['asset_name'] ?? 'Актив';
        if ($isNameHidden && $displayAssetName === 'Актив') {
            $displayAssetName = ''; // Пустая строка для скрытых активов
        }
        if (!function_exists('renderTeaserHtml')) {
            throw new RuntimeException('Function renderTeaserHtml not found');
        }
        error_log('Rendering teaser HTML...');
        $html = renderTeaserHtml($teaserData, $displayAssetName, $maskedPayload, $dcfData, $logger, $apiKey, $isStartup);
        $logger->logStep('renderTeaserHtml', ['html_length' => strlen($html), 'html_size' => round(strlen($html) / 1024, 2) . 'KB']);
        error_log('renderTeaserHtml completed, HTML length: ' . strlen($html));
        
        // Пост-обработка HTML для скрытых активов: удаление сочетаний "Компания Актив"
        // $isNameHidden уже определен выше
        if ($isNameHidden) {
            if (!function_exists('cleanHiddenCompanyReferences')) {
                throw new RuntimeException('Function cleanHiddenCompanyReferences not found');
            }
            $html = cleanHiddenCompanyReferences($html, true);
            $logger->logStep('cleanHiddenCompanyReferences', ['html_length_after_cleanup' => strlen($html)]);
            error_log('cleanHiddenCompanyReferences completed, HTML length after cleanup: ' . strlen($html));
        }

        // Сохраняем snapshot с исходными данными (не маскированными)
        // Маскированные данные используются только для генерации HTML тизера
        if (!function_exists('persistTeaserSnapshot')) {
            throw new RuntimeException('Function persistTeaserSnapshot not found');
        }
        error_log('Saving teaser snapshot...');
    $snapshot = persistTeaserSnapshot($form, $formPayload, [
        'html' => $html,
        'hero_description' => $heroDescription,
        'generated_at' => date('c'),
        'model' => TOGETHER_MODEL,
    ], $logger);
        $logger->logStep('persistTeaserSnapshot', ['snapshot_created' => !empty($snapshot), 'generated_at' => $snapshot['generated_at'] ?? null]);
        error_log('persistTeaserSnapshot completed');
        
        // Завершаем сессию логирования
        $logger->endSession();

        // Запись в published_teasers создается только при нажатии кнопки "Отправить на модерацию"
        // через submit_teaser_moderation.php, а не автоматически после обновления тизера

        ob_clean();
    echo json_encode([
        'success' => true,
        'html' => $html,
        'generated_at' => $snapshot['generated_at'] ?? null,
    ]);
        ob_end_flush();
} catch (Exception $e) {
        // Логируем ошибку в систему логирования, если она была инициализирована
        if (isset($logger)) {
            $logger->logError('Teaser generation error', $e);
            $logger->endSession();
        }
        
        $errorMessage = $e->getMessage();
        $errorTrace = $e->getTraceAsString();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        
        error_log('Teaser generation error: ' . $errorMessage);
        error_log('Error file: ' . $errorFile . ':' . $errorLine);
        error_log('Error trace: ' . $errorTrace);
        
        // Формируем понятное сообщение для пользователя
        $userMessage = 'Не удалось создать тизер. Попробуйте позже.';
        if (strpos($errorMessage, 'JSON') !== false || strpos($errorMessage, 'parse') !== false) {
            $userMessage = 'Ошибка при обработке ответа от AI. Попробуйте снова.';
        } elseif (strpos($errorMessage, 'API') !== false) {
            $userMessage = 'Ошибка при обращении к AI. Проверьте настройки API.';
        } elseif (strpos($errorMessage, 'pattern') !== false) {
            $userMessage = 'Ошибка при обработке данных. Попробуйте снова.';
        } elseif (strpos($errorMessage, 'function') !== false && strpos($errorMessage, 'not found') !== false) {
            $userMessage = 'Ошибка: функция не найдена. Проверьте конфигурацию.';
        }
        
        // Убеждаемся, что заголовок JSON установлен
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        ob_clean();
    http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => $userMessage,
            'error' => $errorMessage, // Включаем детальную ошибку для отладки
            'file' => basename($errorFile), // Имя файла для отладки
            'line' => $errorLine // Номер строки для отладки
        ]);
        ob_end_flush();
        exit;
    } catch (Throwable $e) {
        // Логируем ошибку в систему логирования, если она была инициализирована
        if (isset($logger)) {
            $logger->logError('Fatal error in teaser generation', $e);
            $logger->endSession();
        }
        
        $errorMessage = $e->getMessage();
        $errorTrace = $e->getTraceAsString();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        
        error_log('Fatal error in teaser generation: ' . $errorMessage);
        error_log('Error file: ' . $errorFile . ':' . $errorLine);
        error_log('Error trace: ' . $errorTrace);
        
        // Убеждаемся, что заголовок JSON установлен
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Критическая ошибка при генерации тизера.',
            'error' => $errorMessage,
            'file' => basename($errorFile), // Имя файла для отладки
            'line' => $errorLine // Номер строки для отладки
        ]);
        ob_end_flush();
        exit;
}
} // Конец проверки TEASER_FUNCTIONS_ONLY

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

    // Логируем содержимое data_json формы для диагностики
    $dataKeys = array_keys($data);
    error_log('buildTeaserPayload: form data_json contains keys: ' . implode(', ', array_slice($dataKeys, 0, 30)));
    if (isset($data['final_price'])) {
        error_log('buildTeaserPayload: form data_json contains final_price=' . $data['final_price']);
    } else {
        error_log('buildTeaserPayload: form data_json does NOT contain final_price');
    }

    // Добавляем финальную цену продажи из data_json, если она есть
    // Обеспечиваем, что цена доступна в обоих полях для совместимости
    if (isset($data['final_price']) && $data['final_price'] > 0) {
        $data['final_selling_price'] = $data['final_price'];
    } elseif (isset($data['final_selling_price']) && $data['final_selling_price'] > 0) {
        // Если есть только final_selling_price, копируем в final_price для совместимости
        $data['final_price'] = $data['final_selling_price'];
    }
    
    // ВАЖНО: Сохраняем исходный data_json в payload, чтобы renderHeroBlock мог прочитать final_price
    // даже если он не был скопирован в верхний уровень $data
    if (!empty($form['data_json'])) {
        $data['_original_data_json'] = $form['data_json'];
    }
    
    // Логируем наличие цены для отладки
    if (isset($data['final_price']) && $data['final_price'] > 0) {
        error_log('buildTeaserPayload: Found final_price in data_json: ' . $data['final_price']);
    } elseif (isset($data['final_selling_price']) && $data['final_selling_price'] > 0) {
        error_log('buildTeaserPayload: Found final_selling_price in data_json: ' . $data['final_selling_price']);
    } else {
        error_log('buildTeaserPayload: No final price found in data_json');
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
            $value = $form[$column] ?? '';
            // Специальная обработка для deal_goal: если это JSON-строка, декодируем её
            if ($key === 'deal_goal' && is_string($value) && !empty($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $data[$key] = $decoded;
                } else {
                    $data[$key] = $value;
                }
            } else {
                $data[$key] = $value;
            }
        }
        
        // Преобразуем массив регионов в строку для обратной совместимости
        if (isset($data['presence_regions']) && is_array($data['presence_regions'])) {
            $data['presence_regions'] = implode(', ', array_filter(array_map('trim', $data['presence_regions'])));
        }

        $data['production'] = !empty($form['production_volumes']) ? (json_decode($form['production_volumes'], true) ?: []) : [];
        $data['financial']  = !empty($form['financial_results']) ? (json_decode($form['financial_results'], true) ?: []) : [];
        $data['balance']    = !empty($form['balance_indicators']) ? (json_decode($form['balance_indicators'], true) ?: []) : [];
    }
    
    // Добавляем данные стартапа, если они есть
    // Проверяем company_type из data_json или из отдельного поля
    $companyType = $data['company_type'] ?? $form['company_type'] ?? null;
    if ($companyType === 'startup') {
        // Извлекаем данные стартапа из data_json (уже в $data) или из отдельных полей формы
        $startupFields = [
            'startup_product_description', 'startup_technology_description', 'startup_patents_ip',
            'startup_product_stage', 'startup_user_count', 'startup_mrr', 'startup_dau_mau',
            'startup_registrations', 'startup_conversion_rate', 'startup_retention_rate',
            'startup_pilot_projects', 'startup_shareholders', 'startup_key_employees',
            'startup_social_links', 'startup_target_market', 'startup_market_size',
            'startup_competitors', 'startup_competitive_advantages', 'startup_roadmap',
            'startup_scaling_plans', 'startup_funding_usage',
            'startup_revenue_2023', 'startup_revenue_2024', 'startup_revenue_2025',
            'startup_expenses_2023', 'startup_expenses_2024', 'startup_expenses_2025',
            'startup_profit_2023', 'startup_profit_2024', 'startup_profit_2025',
            'startup_revenue_forecast_1', 'startup_expenses_forecast_1', 'startup_profit_forecast_1',
            'startup_revenue_forecast_2', 'startup_expenses_forecast_2', 'startup_profit_forecast_2',
            'startup_revenue_forecast_3', 'startup_expenses_forecast_3', 'startup_profit_forecast_3',
            'startup_current_sales_volume', 'startup_price_per_unit', 'startup_variable_costs_per_unit',
            'startup_fixed_costs', 'startup_break_even_volume', 'startup_current_valuation',
            'startup_investment_needed', 'startup_previous_investments', 'startup_investment_usage',
            'company_founded_date'
        ];
        
        // Добавляем поля стартапа из формы, если их нет в data_json
        foreach ($startupFields as $field) {
            if (!isset($data[$field]) && isset($form[$field])) {
                $data[$field] = $form[$field];
            }
        }
        
        // Также проверяем data_json напрямую, если он есть, но поля не были извлечены
        if (!empty($form['data_json'])) {
            $decodedDataJson = json_decode($form['data_json'], true);
            if (is_array($decodedDataJson)) {
                foreach ($startupFields as $field) {
                    if (!isset($data[$field]) && isset($decodedDataJson[$field])) {
                        $data[$field] = $decodedDataJson[$field];
                    }
                }
            }
        }
    }
    
    // Преобразуем массив регионов в строку для обратной совместимости (если данные из data_json)
    if (isset($data['presence_regions']) && is_array($data['presence_regions'])) {
        $data['presence_regions'] = implode(', ', array_filter(array_map('trim', $data['presence_regions'])));
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
 * Создает маскированную версию payload для тизера.
 * 
 * Если asset_disclosure = 'no', заменяет asset_name на "Актив" во всех местах,
 * где оно используется в тизере. Исходные данные анкеты не изменяются.
 * 
 * @param array $payload Исходные данные анкеты
 * @return array Маскированные данные для тизера
 */
/**
 * Создает маскированную версию payload для генерации тизера
 * 
 * Эта функция создает промежуточную переменную с маскированными данными,
 * которая используется только для генерации тизера. Исходные данные анкеты
 * остаются без изменений.
 * 
 * Если в анкете указано "нет" в поле "РАСКРЫТИЕ НАЗВАНИЯ", функция заменяет:
 * - asset_name на "Актив"
 * - упоминания названия в company_brands на "Актив"
 * 
 * @param array $payload Исходные данные анкеты
 * @return array Маскированная версия payload (новая копия, исходные данные не изменяются)
 */
function buildMaskedTeaserPayload(array $payload): array
{
    // Создаем копию payload, чтобы не изменять исходные данные
    $maskedPayload = $payload;
    
    // Проверяем, нужно ли маскировать название актива
    // Значение может быть 'no', 'нет' или другими вариантами
    $assetDisclosure = $maskedPayload['asset_disclosure'] ?? '';
    $shouldMask = ($assetDisclosure === 'no' || $assetDisclosure === 'нет');
    
    if ($shouldMask) {
        // Заменяем название актива на "Актив" во всех местах
        $maskedPayload['asset_name'] = 'Актив';
        
        // Скрываем названия брендов для клиентов со скрытым именем
        // Очищаем поле company_brands, чтобы бренды не появлялись в тизере
        $maskedPayload['company_brands'] = '';
    }
    
    // ВАЖНО: Сохраняем final_price и _original_data_json в маскированном payload
    // чтобы renderHeroBlock мог прочитать цену даже после маскирования
    // Эти поля не содержат названия актива, поэтому их можно безопасно сохранить
    
    return $maskedPayload;
}

/**
 * Формирует промпт для AI.
 * Структура ответа описана явно и строго — модель должна вернуть JSON
 * с заранее известными ключами, чтобы дальнейший парсинг был детерминированным.
 * Дополнительно подмешиваются выдержки с корпоративного сайта, если они есть.
 */
/**
 * Проверяет, содержит ли значение полезную информацию (не пустое, не ноль, не placeholder).
 * 
 * @param mixed $value Значение для проверки
 * @return bool true если значение содержит полезную информацию, false иначе
 */
function hasUsefulData($value): bool
{
    if ($value === null) {
        return false;
    }
    
    // Если это число, проверяем что оно не ноль
    if (is_numeric($value)) {
        $numValue = (float)$value;
        return $numValue != 0;
    }
    
    // Если это строка, проверяем что она не пустая и не placeholder
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        
        // Проверяем на placeholder-тексты
        $placeholders = [
            'уточняется',
            'обсуждается',
            'не указано',
            'не указана',
            'не указан',
            'не указаны',
            'не заполнено',
            'не заполнена',
            'не заполнен',
            'не заполнены',
            'дополнительные сведения доступны по запросу',
            'информация уточняется',
            'данные уточняются',
            'н/д',
            'n/a',
            'n/a.',
            '—',
            '-',
            '0',
            '0.0',
            '0,0',
        ];
        
        $lowerTrimmed = mb_strtolower($trimmed);
        foreach ($placeholders as $placeholder) {
            if ($lowerTrimmed === mb_strtolower($placeholder)) {
                return false;
            }
        }
        
        return true;
    }
    
    // Для массивов проверяем, есть ли хотя бы один элемент с полезными данными
    if (is_array($value)) {
        foreach ($value as $item) {
            if (hasUsefulData($item)) {
                return true;
            }
        }
        return false;
    }
    
    // Для других типов считаем полезным, если не null
    return true;
}

/**
 * Очищает snapshot сайта от JavaScript кода, HTML тегов и странных символов.
 * Оставляет только читаемый текст на русском языке.
 * 
 * @param string $snapshot Исходный snapshot сайта
 * @return string Очищенный текст
 */
function cleanWebsiteSnapshot(string $snapshot): string
{
    // Удаляем HTML теги
    $clean = strip_tags($snapshot);
    
    // Удаляем JavaScript код (функции, переменные, вызовы)
    $clean = preg_replace('/function\s*[a-zA-Z_$][a-zA-Z0-9_$]*\s*\([^)]*\)\s*\{[^}]*\}/s', '', $clean);
    $clean = preg_replace('/var\s+[a-zA-Z_$][a-zA-Z0-9_$]*\s*=/', '', $clean);
    $clean = preg_replace('/let\s+[a-zA-Z_$][a-zA-Z0-9_$]*\s*=/', '', $clean);
    $clean = preg_replace('/const\s+[a-zA-Z_$][a-zA-Z0-9_$]*\s*=/', '', $clean);
    $clean = preg_replace('/document\.(getElementById|querySelector|createElement|scripts|scripts\.length)/i', '', $clean);
    $clean = preg_replace('/window\.(location|history|navigator)/i', '', $clean);
    $clean = preg_replace('/[a-zA-Z_$][a-zA-Z0-9_$]*\s*=\s*[a-zA-Z_$][a-zA-Z0-9_$]*\s*\.(push|pop|shift|unshift|splice)/i', '', $clean);
    $clean = preg_replace('/for\s*\([^)]*\)\s*\{[^}]*\}/s', '', $clean);
    $clean = preg_replace('/if\s*\([^)]*\)\s*\{[^}]*\}/s', '', $clean);
    $clean = preg_replace('/\bmi\s*=\s*[^;]+;/i', '', $clean);
    $clean = preg_replace('/\bmi\.[a-zA-Z]+\s*=/i', '', $clean);
    
    // Удаляем строки, которые выглядят как код (содержат много специальных символов)
    $lines = explode("\n", $clean);
    $filteredLines = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        // Пропускаем строки, которые выглядят как код
        if (preg_match('/[{}();=<>\[\]]{3,}/', $line)) {
            continue;
        }
        // Пропускаем строки с большим количеством английских слов подряд без русских букв
        if (preg_match('/^[a-zA-Z0-9\s.,;:(){}[\]<>=\-+*\/&|!@#$%^]+$/', $line) && 
            !preg_match('/[а-яА-ЯёЁ]/u', $line) && 
            mb_strlen($line) > 20) {
            continue;
        }
        $filteredLines[] = $line;
    }
    $clean = implode("\n", $filteredLines);
    
    // Удаляем множественные пробелы и переносы строк
    $clean = preg_replace('/\s+/', ' ', $clean);
    $clean = preg_replace('/\n{3,}/', "\n\n", $clean);
    
    // Удаляем строки, которые повторяются более одного раза
    $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $clean);
    $uniqueSentences = [];
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }
        // Проверяем, не является ли это повторением
        $isDuplicate = false;
        foreach ($uniqueSentences as $existing) {
            if (mb_strtolower($sentence) === mb_strtolower($existing) || 
                similar_text(mb_strtolower($sentence), mb_strtolower($existing)) > 90) {
                $isDuplicate = true;
                break;
            }
        }
        if (!$isDuplicate) {
            $uniqueSentences[] = $sentence;
        }
    }
    $clean = implode(' ', $uniqueSentences);
    
    return trim($clean);
}

/**
 * Формирует промпт для генерации тизера стартапа.
 * 
 * @param string $displayNameInPrompt Отображаемое имя компании
 * @param string $nameInstruction Инструкция по скрытому имени
 * @param string $cashOutInstruction Инструкция по cash-out
 * @param string $json JSON данные анкеты
 * @param string $siteNote Заметка о сайте
 * @return string Промпт для AI
 */
function buildStartupTeaserPrompt(string $displayNameInPrompt, string $nameInstruction, string $cashOutInstruction, string $json, string $siteNote): string
{
    return <<<PROMPT
Ты — инвестиционный банкир. Подготовь лаконичный тизер {$displayNameInPrompt} для потенциальных инвесторов.

Важно:
- Отвечай строго на русском языке. НЕ используй английские слова, кроме обязательных технических терминов (например, MVP, SaaS, B2B).
- ИСПОЛЬЗУЙ ВСЮ ИМЕЮЩУЮСЯ ИНФОРМАЦИЮ из анкеты. Если поле заполнено — используй его. Если поле пустое или отсутствует — пиши «уточняется».
- Для стартапов многие поля могут быть не заполнены — это нормально. Используй то, что есть, и указывай «уточняется» для отсутствующих данных.
- При необходимости дополни данные публичными отраслевыми фактами (без выдумывания конкретных чисел, если они неупомянуты).
- Соблюдай структуру данных. Все текстовые поля — короткие абзацы, списки — массивы строк.
- Для блока "overview.summary": напиши 2-4 абзаца по 2-3 предложения в каждом. Каждый абзац должен быть отделён пустой строкой. Используй деловой стиль без канцелярита. Структура: 1) описание продукта и компании, 2) ключевые показатели и тракция, 3) рынок и конкурентные преимущества, 4) инвестиционный запрос и планы развития.
- ИГНОРИРУЙ любые фрагменты JavaScript кода, HTML теги, технические символы ({}, [], (), =, function, var, let, const, document, window и т.д.) из данных сайта. Используй только читаемый текст на русском языке.
- НЕ повторяй одни и те же предложения или фразы. Каждое предложение должно добавлять новую информацию.
- Если в данных сайта есть повторяющиеся фразы или код — пропусти их и используй только уникальную информацию.{$nameInstruction}{$cashOutInstruction}

Структура ответа — строго валидный JSON:
{
  "overview": {
      "title": "...",
      "summary": "...",
      "key_metrics": ["...", "..."]
  },
  "product_technology": {
      "product_description": "...",
      "technology": "...",
      "ip_patents": "...",
      "product_stage": "..."
  },
  "team": {
      "founders": "...",
      "key_employees": "...",
      "headcount": "...",
      "social_links": "..."
  },
  "traction": {
      "users": "...",
      "mrr": "...",
      "dau_mau": "...",
      "conversion": "...",
      "retention": "...",
      "pilots": "..."
  },
  "market": {
      "trend": "...",
      "size": "...",
      "growth": "...",
      "sources": ["...", "..."]
  },
  "financials_forecast": {
      "revenue_2023": "...",
      "revenue_2024": "...",
      "revenue_2025": "...",
      "forecast": "...",
      "unit_economics": "...",
      "valuation": "..."
  },
  "roadmap": {
      "development_plan": "...",
      "scaling_plans": "...",
      "funding_usage": "..."
  },
  "highlights": {
      "bullets": ["...", "...", "..."]
  },
  "deal_terms": {
      "structure": "...",
      "share_for_sale": "...",
      "valuation_expectation": "...",
      "price": "...",
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

ВАЖНО: Если в данных анкеты указана цена предложения Продавца (final_selling_price или final_price), используй её в поле "price" раздела "deal_terms" как "Цена актива: X млн ₽". Если цена предложения Продавца не указана, используй поле "valuation_expectation" для указания ожидаемой оценки.
PROMPT;
}

function buildTeaserPrompt(array $payload, bool $isStartup = false): string
{
    $assetName = $payload['asset_name'] ?? 'Неизвестный актив';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $siteNote = '';
    if (!empty($payload['company_website']) && !empty($payload['company_website_snapshot'])) {
        // Очищаем snapshot от JavaScript кода и странных символов
        $cleanedSnapshot = cleanWebsiteSnapshot($payload['company_website_snapshot']);
        if (!empty($cleanedSnapshot)) {
        $siteNote = "\nДополнительные сведения с сайта {$payload['company_website']}:\n" .
                $cleanedSnapshot .
                "\nВАЖНО: При использовании информации с сайта игнорируй любые фрагменты кода, JavaScript функции, технические символы. Используй только читаемый текст на русском языке. Если в тексте есть английские слова или код - пропусти их.\n";
        }
    }

    // Проверяем, скрыто ли имя компании
    $isNameHidden = ($assetName === 'Актив');
    $nameInstruction = '';
    $displayNameInPrompt = $assetName;
    if ($isNameHidden) {
        $nameInstruction = "\nВАЖНО: Название компании скрыто. НЕ используй слово 'Актив' в тексте вообще. Можно использовать 'Компания' или 'Фирма', но НЕ упоминай 'Актив' ни в каком виде (ни отдельно, ни в сочетаниях типа 'Компания Актив', 'Компания «Актив»' и т.д.).";
        $displayNameInPrompt = 'компании'; // Используем нейтральное название вместо "Актив"
    }
    
    // Проверяем, является ли цель сделки только cash_out (выход продавца)
    $isOnlyCashOut = isOnlyCashOut($payload['deal_goal'] ?? '');
    $cashOutInstruction = '';
    if ($isOnlyCashOut) {
        $cashOutInstruction = "\nКРИТИЧЕСКИ ВАЖНО: Цель сделки - только cash-out (выход продавца). Продавец забирает деньги и выходит из бизнеса. НЕ используй фразы о том, что инвестиции позволят ускорить развитие, расширить присутствие, поддержать рост выручки, масштабировать бизнес или что-либо подобное. НЕ упоминай направления использования инвестиций на развитие компании. Фокус должен быть на текущем состоянии бизнеса и его привлекательности для покупателя, а не на планах развития.";
    }

    if ($isStartup) {
        // Промпт для стартапов
        return buildStartupTeaserPrompt($displayNameInPrompt, $nameInstruction, $cashOutInstruction, $json, $siteNote);
    } else {
        // Промпт для зрелых компаний (максимально упрощенная версия)
    return <<<PROMPT
Подготовь тизер {$displayNameInPrompt} для инвесторов на русском языке.{$nameInstruction}{$cashOutInstruction}

Используй данные анкеты. Если поле пустое — пиши «уточняется».

Верни JSON:
{
  "overview": {"title": "...", "summary": "...", "key_metrics": ["...", "..."]},
  "company_profile": {"industry": "...", "established": "...", "headcount": "...", "locations": "...", "operations": "...", "unique_assets": "..."},
  "products": {"portfolio": "...", "differentiators": "...", "key_clients": "...", "sales_channels": "..."},
  "market": {"trend": "...", "size": "...", "growth": "...", "sources": ["...", "..."]},
  "financials": {"revenue": "...", "ebitda": "...", "margins": "...", "capex": "...", "notes": "..."},
  "highlights": {"bullets": ["...", "...", "..."]},
  "deal_terms": {"structure": "...", "share_for_sale": "...", "valuation_expectation": "...", "price": "...", "use_of_proceeds": "..."},
  "next_steps": {"cta": "...", "contact": "...", "disclaimer": "..."}
}

Данные:
{$json}
{$siteNote}
PROMPT;
    }
}

/**
 * Очищает текст от нежелательных сочетаний типа "Компания Актив" для скрытых активов.
 * 
 * Заменяет "Компания Актив", "Компания «Актив»" и подобные на "Компания",
 * оставляя отдельные слова "Компания" и "Фирма" без изменений.
 * 
 * @param string $text Текст для обработки (может быть HTML или обычный текст)
 * @param bool $isNameHidden Флаг, указывающий, скрыто ли имя компании
 * @return string Очищенный текст
 */
function cleanHiddenCompanyText(string $text, bool $isNameHidden): string
{
    if (!$isNameHidden) {
        return $text;
    }
    
    // Удаляем все упоминания "Актив" из текста
    // Обрабатываем различные варианты: "Компания Актив", "Компания «Актив»", просто "Актив" и т.д.
    $patterns = [
        // "Компания Актив" (с пробелом) - заменяем на "Компания"
        '/\bКомпания\s+Актив\b/ui',
        '/\bкомпания\s+Актив\b/ui',
        // "Компания «Актив»" (с русскими кавычками) - заменяем на "Компания"
        '/\bКомпания\s*[«"]\s*Актив\s*[»"]\b/ui',
        '/\bкомпания\s*[«"]\s*Актив\s*[»"]\b/ui',
        // "Компания "Актив"" (с английскими кавычками) - заменяем на "Компания"
        '/\bКомпания\s*["\']\s*Актив\s*["\']\b/ui',
        '/\bкомпания\s*["\']\s*Актив\s*["\']\b/ui',
        // С множественными пробелами
        '/\bКомпания\s{2,}\s*Актив\b/ui',
        '/\bкомпания\s{2,}\s*Актив\b/ui',
        // Более общий паттерн для любых кавычек и пробелов
        '/\bКомпания\s*[«"\'"]\s*Актив\s*[»"\'"]\b/ui',
        '/\bкомпания\s*[«"\'"]\s*Актив\s*[»"\'"]\b/ui',
        // Просто "Актив" как отдельное слово (не в начале предложения, чтобы не удалить из других контекстов)
        // Удаляем только если это не часть другого слова
        '/\bАктив\b(?![а-яА-Я])/ui',
        // "Компания «Компания»" - заменяем на "Компания"
        '/\bКомпания\s*[«"]\s*Компания\s*[»"]\b/ui',
        '/\bкомпания\s*[«"]\s*компания\s*[»"]\b/ui',
        '/\bКомпания\s*["\']\s*Компания\s*["\']\b/ui',
        '/\bкомпания\s*["\']\s*компания\s*["\']\b/ui',
    ];
    
    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, 'Компания', $text);
    }
    
    // Дополнительно: удаляем оставшиеся упоминания "Актив" в кавычках
    $text = preg_replace('/[«"]\s*Актив\s*[»"]/ui', '', $text);
    $text = preg_replace('/["\']\s*Актив\s*["\']/ui', '', $text);
    
    // Удаляем лишние пробелы, которые могли остаться после удаления
    // Сохраняем двойные переносы строк, заменяем только множественные пробелы и табы
    $text = preg_replace('/[ \t]+/', ' ', $text); // Заменяем только пробелы и табы, не переносы строк
    // Нормализуем переносы строк: оставляем только одиночные и двойные
    $text = preg_replace('/\n{3,}/', "\n\n", $text); // Множественные переносы -> двойные
    $text = trim($text);
    
    return $text;
}

/**
 * Рекурсивно очищает все текстовые поля в массиве данных тизера от нежелательных сочетаний.
 * 
 * Проходит по всем полям массива и очищает строковые значения от "Актив", 
 * "Компания Актив", "Компания «Компания»" и т.д.
 * 
 * @param array $data Массив данных тизера
 * @param bool $isNameHidden Флаг, указывающий, скрыто ли имя компании
 * @return array Очищенные данные тизера
 */
function cleanTeaserDataFromHiddenCompanyReferences(array $data, bool $isNameHidden): array
{
    if (!$isNameHidden) {
        return $data;
    }
    
    if (!function_exists('cleanHiddenCompanyText')) {
        return $data;
    }
    
    $cleaned = [];
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            // Очищаем строковые значения
            $cleaned[$key] = cleanHiddenCompanyText($value, true);
        } elseif (is_array($value)) {
            // Рекурсивно обрабатываем вложенные массивы
            $cleaned[$key] = cleanTeaserDataFromHiddenCompanyReferences($value, true);
        } else {
            // Оставляем другие типы без изменений
            $cleaned[$key] = $value;
        }
    }
    
    return $cleaned;
}

/**
 * Очищает текст от фраз о развитии и инвестициях для cash-out сделок.
 * 
 * Удаляет фразы типа "Инвестиции позволят ускорить развитие", "расширение присутствия",
 * "поддержит рост выручки" и подобные, так как при cash-out продавец забирает деньги,
 * а не вкладывает их в развитие.
 * 
 * @param string $text Текст для обработки
 * @param bool $isCashOut Флаг, указывающий, является ли сделка cash-out
 * @return string Очищенный текст
 */
function cleanCashOutDevelopmentPhrases(string $text, bool $isCashOut): string
{
    if (!$isCashOut) {
        return $text;
    }
    
    // Паттерны для удаления фраз о развитии и инвестициях
    $patterns = [
        // Фразы о том, что инвестиции позволят что-то сделать
        '/Инвестиции\s+позволят\s+[^\.]+\./ui',
        '/инвестиции\s+позволят\s+[^\.]+\./ui',
        '/Инвестиции\s+позволяют\s+[^\.]+\./ui',
        '/инвестиции\s+позволяют\s+[^\.]+\./ui',
        
        // Фразы об ускорении развития
        '/ускорить\s+развитие[^\.]*\./ui',
        '/ускорение\s+развития[^\.]*\./ui',
        '/ускорит\s+развитие[^\.]*\./ui',
        '/ускорит\s+развитие\s+[^\.]+\./ui',
        
        // Фразы о расширении присутствия
        '/расширение\s+присутствия[^\.]*\./ui',
        '/расширить\s+присутствие[^\.]*\./ui',
        '/расширения\s+присутствия[^\.]*\./ui',
        
        // Фразы о поддержке роста
        '/поддержит\s+рост\s+выручки[^\.]*\./ui',
        '/поддержка\s+роста\s+выручки[^\.]*\./ui',
        '/поддержит\s+рост[^\.]*\./ui',
        
        // Фразы о масштабировании
        '/масштабирование\s+бизнеса[^\.]*\./ui',
        '/масштабировать\s+бизнес[^\.]*\./ui',
        '/масштабирования\s+бизнеса[^\.]*\./ui',
        
        // Фразы о направлении средств на развитие
        '/направлены\s+на\s+развитие[^\.]*\./ui',
        '/направление\s+средств\s+на\s+развитие[^\.]*\./ui',
        '/направлены\s+на\s+масштабирование[^\.]*\./ui',
        
        // Фразы о следующем этапе роста
        '/следующего\s+этапа\s+роста[^\.]*\./ui',
        '/следующий\s+этап\s+роста[^\.]*\./ui',
        '/для\s+следующего\s+этапа\s+роста[^\.]*\./ui',
        
        // Комбинированные фразы
        '/Инвестиции\s+позволят\s+ускорить\s+развитие[^\.]+\./ui',
        '/Инвестиции\s+позволят\s+расширить\s+присутствие[^\.]+\./ui',
        '/Инвестиции\s+позволят\s+поддержать\s+рост[^\.]+\./ui',
        '/Инвестиции\s+позволят\s+масштабировать[^\.]+\./ui',
    ];
    
    foreach ($patterns as $pattern) {
        $text = preg_replace($pattern, '', $text);
    }
    
    // Удаляем лишние пробелы и точки, которые могли остаться
    $text = preg_replace('/\s*\.\s*\./u', '.', $text); // Двойные точки
    // Сохраняем двойные переносы строк, заменяем только множественные пробелы и табы
    $text = preg_replace('/[ \t]+/', ' ', $text); // Заменяем только пробелы и табы, не переносы строк
    // Нормализуем переносы строк: оставляем только одиночные и двойные
    $text = preg_replace('/\n{3,}/', "\n\n", $text); // Множественные переносы -> двойные
    $text = trim($text);
    
    return $text;
}

/**
 * Рекурсивно очищает все текстовые поля в массиве данных тизера от фраз о развитии для cash-out.
 * 
 * @param array $data Массив данных тизера
 * @param bool $isCashOut Флаг, указывающий, является ли сделка cash-out
 * @return array Очищенные данные тизера
 */
function cleanTeaserDataFromCashOutDevelopmentPhrases(array $data, bool $isCashOut): array
{
    if (!$isCashOut) {
        return $data;
    }
    
    if (!function_exists('cleanCashOutDevelopmentPhrases')) {
        return $data;
    }
    
    $cleaned = [];
    foreach ($data as $key => $value) {
        if (is_string($value)) {
            // Очищаем строковые значения
            $cleaned[$key] = cleanCashOutDevelopmentPhrases($value, true);
        } elseif (is_array($value)) {
            // Рекурсивно обрабатываем вложенные массивы
            $cleaned[$key] = cleanTeaserDataFromCashOutDevelopmentPhrases($value, true);
        } else {
            // Оставляем другие типы без изменений
            $cleaned[$key] = $value;
        }
    }
    
    return $cleaned;
}

/**
 * Очищает HTML тизера от нежелательных сочетаний типа "Компания Актив" для скрытых активов.
 * 
 * Заменяет "Компания Актив" на "Компания" (или "Фирма"), оставляя отдельные слова
 * "Компания" и "Фирма" без изменений.
 * 
 * @param string $html HTML тизера для обработки
 * @param bool $isNameHidden Флаг, указывающий, скрыто ли имя компании
 * @return string Очищенный HTML
 */
function cleanHiddenCompanyReferences(string $html, bool $isNameHidden): string
{
    if (!$isNameHidden) {
        // Если имя не скрыто, возвращаем HTML без изменений
        return $html;
    }
    
    // Используем DOMDocument для безопасной обработки HTML
    if (!class_exists('DOMDocument')) {
        // Fallback: используем функцию очистки текста
        return cleanHiddenCompanyText($html, $isNameHidden);
    }
    
    // Создаем DOMDocument для безопасной обработки
    $dom = new DOMDocument('1.0', 'UTF-8');
    
    // Подавляем ошибки парсинга (HTML может быть невалидным)
    libxml_use_internal_errors(true);
    
    // Загружаем HTML с учетом кодировки UTF-8
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    
    // Очищаем ошибки парсинга
    libxml_clear_errors();
    
    // Создаем XPath для поиска текстовых узлов
    $xpath = new DOMXPath($dom);
    
    // Находим все текстовые узлы
    $textNodes = $xpath->query('//text()');
    
    if ($textNodes) {
        foreach ($textNodes as $textNode) {
            $text = $textNode->nodeValue;
            
            // Используем функцию очистки текста
            $cleaned = cleanHiddenCompanyText($text, $isNameHidden);
            
            // Обновляем текст узла только если были изменения
            if ($cleaned !== $text) {
                $textNode->nodeValue = $cleaned;
            }
        }
    }
    
    // Извлекаем body содержимое
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body) {
        $result = '';
        foreach ($body->childNodes as $node) {
            $result .= $dom->saveHTML($node);
        }
        return $result;
    }
    
    // Fallback: возвращаем исходный HTML
    return $html;
}

/**
 * Вызывает together.ai Completion API с retry логикой для повышения стабильности.
 * Оборачивает cURL-запрос, проверяет код ответа и пробует разные форматы
 * JSON, которые может вернуть Together (старый output.choices и новый choices).
 * 
 * @param string $prompt Промпт для отправки в API
 * @param string $apiKey API ключ для авторизации
 * @param int $maxRetries Максимальное количество попыток (по умолчанию 3)
 * @return string Текст ответа от AI
 * @throws RuntimeException При ошибках API или сети
 */
// Функция callTogetherCompletions() теперь определена в config.php
// Используется универсальная функция callAICompletions(), которая автоматически выбирает провайдера

/**
 * Парсит ответ AI в массив.
 * Если парсер не смог прочитать JSON, возвращаем минимальный каркас overview
 * с текстом, чтобы интерфейс всегда показал хотя бы что-то.
 */
/**
 * Парсит ответ от AI и извлекает JSON данные тизера
 * 
 * Обрабатывает различные форматы ответа:
 * - Чистый JSON
 * - JSON в кодовых блоках ```json ... ```
 * - Текстовый ответ (fallback)
 * 
 * @param string $text Сырой текст ответа от AI
 * @return array Массив с данными тизера
 */
function parseTeaserResponse(string $text): array
{
    $clean = trim($text);
    
    // Удаляем кодовые блоки ```json ... ``` или ``` ... ```
    // Используем совместимую функцию вместо str_starts_with для поддержки PHP 7.4+
    if (substr($clean, 0, 3) === '```') {
        // Удаляем начальный маркер кодового блока
        $clean = preg_replace('/^```[a-z]*\s*/i', '', $clean);
        // Удаляем конечный маркер кодового блока
        $clean = preg_replace('/```\s*$/s', '', $clean);
    }

    $clean = trim($clean);

    // Если строка пустая, возвращаем fallback
    if (empty($clean)) {
        error_log("Empty response from AI");
        return [
            'overview' => [
                'title' => 'Резюме',
                'summary' => 'Не удалось получить ответ от AI. Попробуйте снова.',
                'key_metrics' => [],
            ],
        ];
    }

    // Пытаемся найти JSON в тексте, если он не в начале
    // Иногда AI может добавить пояснения перед JSON
    if (!empty($clean) && $clean[0] !== '{') {
        // Ищем JSON объект в тексте
        // Используем более безопасный подход: ищем первый { и последний }
        $firstBrace = strpos($clean, '{');
        if ($firstBrace !== false) {
            $lastBrace = strrpos($clean, '}');
            if ($lastBrace !== false && $lastBrace > $firstBrace) {
                $clean = substr($clean, $firstBrace, $lastBrace - $firstBrace + 1);
            }
        }
    }

    // Пытаемся распарсить JSON
    $json = json_decode($clean, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json) && !empty($json)) {
        // Дополнительная валидация: проверяем, что это действительно структура тизера
        if (isset($json['overview']) || isset($json['company_profile']) || isset($json['products'])) {
            // Проверяем, не содержит ли ответ промпт в текстовых полях
            $promptPhrases = [
                'Ты инвестиционный банкир',
                'Ты — инвестиционный банкир',
                'Подготовь лаконичный тизер',
                'Подготовь тизер',
                'На основе фактов ниже',
                'Исходная версия',
                'Факты:',
                'Данные анкеты:',
                'Структура ответа',
                'Верни строго валидный JSON',
            ];
            
            // Проверяем overview.summary
            if (isset($json['overview']['summary'])) {
                $summary = (string)$json['overview']['summary'];
                foreach ($promptPhrases as $phrase) {
                    if (stripos($summary, $phrase) !== false) {
                        error_log('Parsed JSON contains prompt in overview.summary: ' . $phrase);
                        // Заменяем на fallback
                        $json['overview']['summary'] = 'Описание компании уточняется.';
                        break;
                    }
                }
            }
            
            // Проверяем другие текстовые поля в overview
            if (isset($json['overview']) && is_array($json['overview'])) {
                foreach ($json['overview'] as $key => $value) {
                    if (is_string($value) && $key !== 'summary') {
                        foreach ($promptPhrases as $phrase) {
                            if (stripos($value, $phrase) !== false) {
                                error_log("Parsed JSON contains prompt in overview.{$key}: " . $phrase);
                                $json['overview'][$key] = 'Уточняется.';
                                break;
                            }
                        }
                    }
                }
            }
            
            return $json;
        }
        // Если структура не похожа на тизер, но это валидный JSON, логируем и используем
        error_log('Parsed JSON but structure unexpected. Keys: ' . implode(', ', array_keys($json)));
        return $json;
    }
    
    // Если JSON не распарсился, пытаемся исправить распространенные проблемы
    if (json_last_error() !== JSON_ERROR_NONE) {
        // Пытаемся исправить незакрытые строки, незакрытые скобки и т.д.
        $fixed = $clean;
        
        // Удаляем trailing commas перед закрывающими скобками/фигурными скобками
        $fixed = preg_replace('/,\s*([}\]])/u', '$1', $fixed);
        
        // Пытаемся закрыть незакрытые строки (простая эвристика)
        $openQuotes = substr_count($fixed, '"') - substr_count($fixed, '\\"');
        if ($openQuotes % 2 !== 0) {
            // Нечетное количество кавычек - пытаемся закрыть последнюю (если она не экранирована)
            $lastQuotePos = strrpos($fixed, '"');
            if ($lastQuotePos !== false && ($lastQuotePos === 0 || $fixed[$lastQuotePos - 1] !== '\\')) {
                // Добавляем закрывающую кавычку в конец, если нужно
                $fixed = rtrim($fixed, ',') . '"';
            }
        }
        
        // Пытаемся закрыть незакрытые скобки
        $openBraces = substr_count($fixed, '{') - substr_count($fixed, '}');
        if ($openBraces > 0) {
            $fixed .= str_repeat('}', $openBraces);
        }
        
        $json = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json) && !empty($json)) {
            error_log('Successfully fixed JSON parsing errors');
            if (isset($json['overview']) || isset($json['company_profile']) || isset($json['products'])) {
                // Проверяем, не содержит ли ответ промпт в текстовых полях
                $promptPhrases = [
                    'Ты инвестиционный банкир',
                    'Ты — инвестиционный банкир',
                    'Подготовь лаконичный тизер',
                    'Подготовь тизер',
                    'На основе фактов ниже',
                    'Исходная версия',
                    'Факты:',
                    'Данные анкеты:',
                    'Структура ответа',
                    'Верни строго валидный JSON',
                ];
                
                // Проверяем overview.summary
                if (isset($json['overview']['summary'])) {
                    $summary = (string)$json['overview']['summary'];
                    foreach ($promptPhrases as $phrase) {
                        if (stripos($summary, $phrase) !== false) {
                            error_log('Fixed JSON contains prompt in overview.summary: ' . $phrase);
                            $json['overview']['summary'] = 'Описание компании уточняется.';
                            break;
                        }
                    }
                }
                
                return $json;
            }
        }
    }

    // Если JSON не удалось распарсить, логируем ошибку и возвращаем fallback
    $errorMsg = json_last_error_msg();
    $errorCode = json_last_error();
    $textPreview = mb_substr($clean, 0, 500, 'UTF-8');
    error_log("Failed to parse teaser JSON. Error code: $errorCode, Message: $errorMsg. Text preview: $textPreview");

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
function renderTeaserHtml(array $data, string $assetName, array $payload = [], ?array $dcfData = null, ?TeaserLogger $logger = null, ?string $apiKey = null, bool $isStartup = false): string
{
    // Рендерим hero блок в начале
    $heroHtml = renderHeroBlock($assetName, $data, $payload, $dcfData, $logger, $apiKey);
    
    $blocks = [];

    if (!empty($data['overview'])) {
        $overview = $data['overview'];
        $blocks[] = renderCard('Обзор возможности', [
            // Убираем subtitle, чтобы не показывать "Резюме" или другие заголовки
            'text' => formatOverviewTextWithParagraphs($overview['summary'] ?? ''),
            'list' => $overview['key_metrics'] ?? [],
        ], 'overview');
    }

    if ($isStartup) {
        // Блоки для стартапов
        
        // Продукт и технология
        if (!empty($data['product_technology'])) {
            $productTech = $data['product_technology'];
            $bullets = array_filter([
                formatMetric('Описание продукта', $productTech['product_description'] ?? ''),
                formatMetric('Технология', $productTech['technology'] ?? ''),
                formatMetric('Патенты и ИС', $productTech['ip_patents'] ?? ''),
                formatMetric('Стадия продукта', $productTech['product_stage'] ?? ''),
            ]);
            if ($bullets) {
                $blocks[] = renderCard('Продукт и технология', [
                    'list' => $bullets,
                ], 'product_technology');
            }
        }
        
        // Команда
        if (!empty($data['team'])) {
            $team = $data['team'];
            $bullets = array_filter([
                formatMetric('Основатели', $team['founders'] ?? ''),
                formatMetric('Ключевые сотрудники', $team['key_employees'] ?? ''),
                formatMetric('Численность команды', $team['headcount'] ?? ''),
                formatMetric('Социальные сети', $team['social_links'] ?? ''),
            ]);
            if ($bullets) {
                $blocks[] = renderCard('Команда', [
                    'list' => $bullets,
                ], 'team');
            }
        }
        
        // Traction
        if (!empty($data['traction'])) {
            $traction = $data['traction'];
            $bullets = [];
            
            // Проверяем каждое поле на наличие полезных данных перед добавлением
            if (hasUsefulData($traction['users'] ?? null)) {
                $bullets[] = formatMetric('Пользователи/клиенты', $traction['users'] ?? '');
            }
            if (hasUsefulData($traction['mrr'] ?? null)) {
                $bullets[] = formatMetric('MRR', $traction['mrr'] ?? '');
            }
            if (hasUsefulData($traction['dau_mau'] ?? null)) {
                $bullets[] = formatMetric('DAU/MAU', $traction['dau_mau'] ?? '');
            }
            if (hasUsefulData($traction['conversion'] ?? null)) {
                $bullets[] = formatMetric('Конверсия', $traction['conversion'] ?? '');
            }
            if (hasUsefulData($traction['retention'] ?? null)) {
                $bullets[] = formatMetric('Удержание', $traction['retention'] ?? '');
            }
            if (hasUsefulData($traction['pilots'] ?? null)) {
                $bullets[] = formatMetric('Пилоты и партнерства', $traction['pilots'] ?? '');
            }
            
            // Показываем блок только если есть хотя бы одно поле с полезными данными
            if (!empty($bullets)) {
                $blocks[] = renderCard('Traction', [
                    'list' => $bullets,
                ], 'traction');
            }
        }
    } else {
        // Блоки для зрелых компаний

    if (!empty($data['company_profile'])) {
        $profile = $data['company_profile'];
        $bullets = array_filter([
            formatMetric('Отрасль', $profile['industry'] ?? ''),
            formatMetric('Год основания', $profile['established'] ?? ''),
            formatMetric('Персонал', $profile['headcount'] ?? ''),
            formatMetric('Локации', $profile['locations'] ?? ''),
                // Показываем "Операционная модель" только если есть полезная информация
                (!empty($profile['operations']) && 
                 $profile['operations'] !== 'Дополнительные сведения доступны по запросу.' &&
                 trim($profile['operations']) !== '') 
                    ? formatMetric('Операционная модель', $profile['operations']) 
                    : null,
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
    }

    if (!empty($data['market'])) {
        $market = $data['market'];
        $marketText = formatMarketBlockText($market);
        $blocks[] = renderCard('Рынок и тенденции', [
            'text' => nl2br(escapeHtml($marketText['text'])),
            'footer' => escapeHtml($marketText['footer']),
        ], 'market');
    }

    if ($isStartup) {
        // Финансовые показатели и прогнозы для стартапов
        if (!empty($data['financials_forecast'])) {
            $financials = $data['financials_forecast'];
            $bullets = [];
            
            // Проверяем каждое поле на наличие полезных данных перед добавлением
            if (hasUsefulData($financials['revenue_2023'] ?? null)) {
                $bullets[] = formatMetric('Выручка 2023', $financials['revenue_2023'] ?? '');
            }
            if (hasUsefulData($financials['revenue_2024'] ?? null)) {
                $bullets[] = formatMetric('Выручка 2024', $financials['revenue_2024'] ?? '');
            }
            if (hasUsefulData($financials['revenue_2025'] ?? null)) {
                $bullets[] = formatMetric('Выручка 2025', $financials['revenue_2025'] ?? '');
            }
            if (hasUsefulData($financials['forecast'] ?? null)) {
                $bullets[] = formatMetric('Прогноз', $financials['forecast'] ?? '');
            }
            if (hasUsefulData($financials['unit_economics'] ?? null)) {
                $bullets[] = formatMetric('Юнит-экономика', $financials['unit_economics'] ?? '');
            }
            if (hasUsefulData($financials['valuation'] ?? null)) {
                $bullets[] = formatMetric('Текущая оценка', $financials['valuation'] ?? '');
            }
            
            // Показываем блок только если есть хотя бы одно поле с полезными данными
            if (!empty($bullets)) {
                $blocks[] = renderCard('Финансовые показатели и прогнозы', [
                    'list' => $bullets,
                ], 'financials_forecast');
            }
        }
        
        // Roadmap развития
        if (!empty($data['roadmap'])) {
            $roadmap = $data['roadmap'];
            $bullets = [];
            
            // Проверяем каждое поле на наличие полезных данных перед добавлением
            if (hasUsefulData($roadmap['development_plan'] ?? null)) {
                $bullets[] = formatMetric('План развития', $roadmap['development_plan'] ?? '');
            }
            if (hasUsefulData($roadmap['scaling_plans'] ?? null)) {
                $bullets[] = formatMetric('Планы масштабирования', $roadmap['scaling_plans'] ?? '');
            }
            if (hasUsefulData($roadmap['funding_usage'] ?? null)) {
                $bullets[] = formatMetric('Использование инвестиций', $roadmap['funding_usage'] ?? '');
            }
            
            // Показываем блок только если есть хотя бы одно поле с полезными данными
            if (!empty($bullets)) {
                $blocks[] = renderCard('Roadmap развития', [
                    'list' => $bullets,
                ], 'roadmap');
            }
        }
    } else {
        // Финансовый профиль для зрелых компаний
    if (!empty($data['financials'])) {
        $financials = $data['financials'];
        
            // Используем данные из DCF модели для первого прогнозного года (P1 - 2026П)
        $revenue = null;
        $profit = null;
        $margin = null;
        $capex = null;
            $year = '2026';
        
        if ($dcfData && !empty($dcfData['rows']) && is_array($dcfData['rows'])) {
            foreach ($dcfData['rows'] as $row) {
                if (!isset($row['label']) || !isset($row['values']) || !is_array($row['values'])) {
                    continue;
                }
                
                    // Получаем данные за P1 (2026П - первый прогнозный период)
                if ($row['label'] === 'Выручка' && isset($row['values']['P1']) && $row['values']['P1'] !== null && $row['values']['P1'] !== '') {
                    $revenue = (float)$row['values']['P1'];
                }
                if ($row['label'] === 'Прибыль от продаж' && isset($row['values']['P1']) && $row['values']['P1'] !== null && $row['values']['P1'] !== '') {
                    $profit = (float)$row['values']['P1'];
                }
                if ($row['label'] === 'CAPEX' && isset($row['values']['P1']) && $row['values']['P1'] !== null && $row['values']['P1'] !== '') {
                    $capex = (float)$row['values']['P1'];
                }
            }
            
            // Рассчитываем маржинальность как (прибыль / выручка) * 100%
            if ($revenue !== null && $profit !== null && $revenue != 0) {
                $margin = ($profit / $revenue) * 100;
            }
        }
        
        // Форматируем значения
        $revenueText = $revenue !== null ? number_format($revenue, 0, '.', ' ') . ' млн ₽' : ($financials['revenue'] ?? '');
        $profitText = $profit !== null ? number_format($profit, 0, '.', ' ') . ' млн ₽' : ($financials['ebitda'] ?? '');
        $marginText = $margin !== null ? number_format($margin, 1, '.', ' ') . '%' : ($financials['margins'] ?? '');
        $capexText = $capex !== null ? number_format($capex, 0, '.', ' ') . ' млн ₽' : ($financials['capex'] ?? '');
        
        // Формируем ссылку на источник данных
        $notesText = 'Данные за ' . $year . ' год из DCF модели.';
        if ($revenue === null && $profit === null) {
            $notesText = $financials['notes'] ?? 'Финансовые показатели подтверждены данными анкеты.';
        }
        
        $bullets = array_filter([
            formatMetric('Выручка', $revenueText),
            formatMetric('Прибыль от продаж', $profitText),
            formatMetric('Маржинальность', $marginText),
            formatMetric('CAPEX', $capexText),
            $notesText,
        ]);
        $blocks[] = renderCard('Финансовый профиль', [
            'list' => $bullets,
        ], 'financial');

        // Используем данные из DCF модели, если они доступны, иначе используем данные из анкеты
        $timeline = null;
        if ($dcfData && !empty($dcfData['rows']) && is_array($dcfData['rows'])) {
            error_log('buildTeaserHtml: Using DCF data. Rows count: ' . count($dcfData['rows']));
            $timeline = buildTeaserTimelineFromDCF($dcfData);
            // Если данные DCF не дали результата, пробуем резервный метод
            if (!$timeline || empty($timeline)) {
                error_log('buildTeaserHtml: DCF data failed, falling back to payload data');
                $timeline = buildTeaserTimeline($payload);
            } else {
                error_log('buildTeaserHtml: DCF data successful. Series count: ' . count($timeline));
            }
        } else {
            // Если DCF данные недоступны, используем данные из анкеты
            error_log('buildTeaserHtml: DCF data not available, using payload data');
            $timeline = buildTeaserTimeline($payload);
        }
        if ($timeline) {
            $blocks[] = renderTeaserChart($timeline);
            }
        }
    }

    if (!empty($data['highlights']['bullets'])) {
        $blocks[] = renderCard('Инвестиционные преимущества', [
            'list' => $data['highlights']['bullets'],
        ], 'highlights');
    }

    if (!empty($data['deal_terms'])) {
        $deal = $data['deal_terms'];
        
        // Форматируем структуру сделки: преобразуем массив в понятный текст
        $structureText = '';
        if (!empty($deal['structure'])) {
            if (is_array($deal['structure'])) {
                // Если структура - массив, преобразуем в понятный текст
                // Для стартапов cash_in заменяем на "привлечение инвестиций на развитие"
                $structureMap = [
                    'cash_out' => 'выход продавца',
                    'cash_in' => $isStartup ? 'привлечение инвестиций на развитие' : 'привлечение инвестиций',
                    'debt_refinancing' => 'рефинансирование долга',
                    'growth_capital' => 'капитал для роста',
                ];
                $structureParts = [];
                foreach ($deal['structure'] as $item) {
                    $itemStr = trim((string)$item);
                    // Убираем кавычки и скобки, если они есть
                    $itemStr = trim($itemStr, '[]"\'');
                    if (isset($structureMap[$itemStr])) {
                        $structureParts[] = $structureMap[$itemStr];
                    } elseif (!empty($itemStr)) {
                        $structureParts[] = $itemStr;
                    }
                }
                if (!empty($structureParts)) {
                    $structureText = implode(', ', $structureParts);
                }
            } else {
                $structureValue = (string)$deal['structure'];
                // Если это JSON-строка массива, пытаемся распарсить
                if (preg_match('/^\[.*\]$/', $structureValue)) {
                    $decoded = json_decode($structureValue, true);
                    if (is_array($decoded)) {
                        // Для стартапов cash_in заменяем на "привлечение инвестиций на развитие"
                        $structureMap = [
                            'cash_out' => 'выход продавца',
                            'cash_in' => $isStartup ? 'привлечение инвестиций на развитие' : 'привлечение инвестиций',
                            'debt_refinancing' => 'рефинансирование долга',
                            'growth_capital' => 'капитал для роста',
                        ];
                        $structureParts = [];
                        foreach ($decoded as $item) {
                            $itemStr = trim((string)$item);
                            if (isset($structureMap[$itemStr])) {
                                $structureParts[] = $structureMap[$itemStr];
                            } elseif (!empty($itemStr)) {
                                $structureParts[] = $itemStr;
                            }
                        }
                        if (!empty($structureParts)) {
                            $structureText = implode(', ', $structureParts);
                        }
                    } else {
                        $structureText = $structureValue;
                    }
                } else {
                    $structureText = $structureValue;
                }
            }
        }
        
        // Форматируем долю: добавляем знак %, если это число
        $shareText = '';
        if (!empty($deal['share_for_sale'])) {
            $shareValue = trim((string)$deal['share_for_sale']);
            // Проверяем, является ли значение числом (может быть "49", "49%", "49-51" и т.д.)
            if (preg_match('/^[\d\s\-]+$/', $shareValue)) {
                // Если это просто число или диапазон чисел, добавляем %
                $shareText = $shareValue . '%';
            } else {
                // Если уже есть % или другой текст, оставляем как есть
                $shareText = $shareValue;
            }
        }
        
        // Форматируем цену: убираем дублирование слова "Цена"
        // В $deal['price'] уже есть "Цена актива: X млн ₽" (см. строку 1772),
        // поэтому просто используем значение без formatMetric
        $priceText = '';
        if (!empty($deal['price'])) {
            $priceValue = trim((string)$deal['price']);
            // Если цена уже содержит "Цена актива:", используем как есть
            if (stripos($priceValue, 'Цена актива:') !== false) {
                $priceText = $priceValue;
            } elseif (stripos($priceValue, 'Цена:') !== false) {
                // Если есть просто "Цена:", заменяем на "Цена актива:"
                $priceText = str_ireplace('Цена:', 'Цена актива:', $priceValue);
            } else {
                // Если нет префикса, добавляем "Цена актива:"
                $priceText = 'Цена актива: ' . $priceValue;
            }
        }
        
        $bullets = array_filter([
            !empty($structureText) ? formatMetric('Структура сделки', $structureText) : null,
            !empty($shareText) ? formatMetric('Предлагаемая доля', $shareText) : null,
            // Добавляем цену, если она указана (без дублирования "Цена:")
            !empty($priceText) ? $priceText : null,
            // Если цена не указана, показываем ожидания по оценке
            empty($priceText) ? formatMetric('Ожидания по оценке', $deal['valuation_expectation'] ?? '') : null,
            // Показываем использование средств только если оно указано и цель сделки не только cash_out
            !empty($deal['use_of_proceeds']) ? formatMetric('Использование средств', $deal['use_of_proceeds']) : null,
        ]);
        
        // Для стартапов добавляем данные об оценке и инвестициях из анкеты
        if ($isStartup) {
            // Получаем единицы измерения из анкеты
            $financialUnit = $payload['startup_financial_unit'] ?? 'тыс. руб.';
            
            // Текущая оценка компании
            $valuation = $payload['startup_valuation'] ?? null;
            if (hasUsefulData($valuation)) {
                $valuationText = is_numeric($valuation) 
                    ? number_format((float)$valuation, 0, '.', ' ') . ' ' . $financialUnit
                    : $valuation;
                $bullets[] = formatMetric('Текущая оценка компании', $valuationText);
            }
            
            // Требуемая сумма инвестиций
            $investmentAmount = $payload['startup_investment_amount'] ?? null;
            if (hasUsefulData($investmentAmount)) {
                $investmentText = is_numeric($investmentAmount)
                    ? number_format((float)$investmentAmount, 0, '.', ' ') . ' ' . $financialUnit
                    : $investmentAmount;
                $bullets[] = formatMetric('Требуемая сумма инвестиций', $investmentText);
            }
        }
        
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

    // Возвращаем hero блок + основной контент
    return $heroHtml . '<div class="teaser-grid">' . implode('', $blocks) . '</div>';
}

/**
 * Рендерит HTML карточки тизера с заголовком, иконкой и содержимым.
 * 
 * Поддерживает различные варианты отображения через параметр $variant:
 * - 'overview', 'profile', 'products', 'market', 'financial', 'highlights', 'deal', 'next'
 * 
 * @param string $title Заголовок карточки
 * @param array $payload Данные карточки:
 *                      - 'subtitle': подзаголовок (опционально)
 *                      - 'text': основной текст (опционально)
 *                      - 'list': массив элементов списка (опционально)
 *                      - 'footer': текст в футере карточки (опционально)
 * @param string $variant Вариант стилизации карточки (для CSS)
 * @return string HTML код карточки
 */
function renderCard(string $title, array $payload, string $variant = ''): string
{
    $variantAttr = $variant !== '' ? ' data-variant="' . htmlspecialchars($variant, ENT_QUOTES, 'UTF-8') . '"' : '';
    $html = '<div class="teaser-card"' . $variantAttr . '>';
    $icon = getTeaserIcon($title);
    $html .= '<div class="teaser-card__icon" aria-hidden="true">' . $icon . '</div>';
    $html .= '<h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';

    if (!empty($payload['subtitle'])) {
        $html .= '<p class="teaser-card__subtitle">' . $payload['subtitle'] . '</p>';
    }

    if (!empty($payload['text'])) {
        // Проверяем, содержит ли текст уже HTML-теги (например, <p>)
        // Если содержит, не оборачиваем в дополнительный <p>
        $text = $payload['text'];
        if (strpos($text, '<p>') !== false || strpos($text, '<div>') !== false) {
            // Текст уже содержит HTML-теги, выводим как есть
            $html .= '<div class="teaser-card__text">' . $text . '</div>';
        } else {
            // Обычный текст, оборачиваем в <p>
            $html .= '<p>' . $text . '</p>';
        }
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

/**
 * Экранирует HTML-специальные символы для безопасного вывода в HTML.
 * 
 * @param mixed $value Значение для экранирования
 * @return string Экранированная строка
 */
function escapeHtml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Форматирует метрику в строку "Метка: Значение".
 * 
 * @param string $label Название метрики
 * @param string $value Значение метрики
 * @return string Отформатированная строка или пустая строка, если значение пустое
 */
function formatMetric(string $label, string $value): string
{
    if (trim($value) === '') {
        return '';
    }
    return "{$label}: {$value}";
}

/**
 * Возвращает SVG иконку для карточки тизера по её заголовку.
 * 
 * @param string $title Заголовок карточки
 * @return string SVG код иконки
 */
function getTeaserIcon(string $title): string
{
    $map = [
        'Обзор возможности' => 'overview',
        'Профиль компании' => 'company',
        'Продукты и клиенты' => 'products',
        'Рынок и тенденции' => 'market',
        'Финансовый профиль' => 'finance',
        'Инвестиционные преимущества' => 'highlights',
        'Параметры сделки' => 'deal',
        'Следующие шаги' => 'next',
    ];
    $key = $map[$title] ?? 'default';
    return teaserSvgIcon($key);
}

function teaserSvgIcon(string $name): string
{
    switch ($name) {
        case 'overview':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <rect x="5" y="16" width="4" height="11" rx="2" fill="#6366F1"/>
    <rect x="14" y="9" width="4" height="18" rx="2" fill="#8B5CF6"/>
    <rect x="23" y="4" width="4" height="23" rx="2" fill="#A5B4FC"/>
</svg>
SVG;
        case 'company':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <rect x="6" y="10" width="20" height="16" rx="3" fill="#0EA5E9" opacity="0.2"/>
    <rect x="9" y="6" width="14" height="20" rx="3" stroke="#0EA5E9" stroke-width="2" fill="none"/>
    <rect x="13" y="14" width="6" height="8" rx="1" fill="#0EA5E9"/>
</svg>
SVG;
        case 'products':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <circle cx="10" cy="10" r="4" stroke="#F97316" stroke-width="2"/>
    <circle cx="22" cy="10" r="4" stroke="#FACC15" stroke-width="2"/>
    <circle cx="16" cy="22" r="4" stroke="#FB923C" stroke-width="2"/>
    <path d="M12 12L15 19M20 12L17 19" stroke="#F97316" stroke-width="2" stroke-linecap="round"/>
</svg>
SVG;
        case 'market':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <circle cx="16" cy="16" r="11" stroke="#0EA5E9" stroke-width="2"/>
    <path d="M5 16H27M16 5C19.5 8.5 21.5 12.5 21.5 16C21.5 19.5 19.5 23.5 16 27C12.5 23.5 10.5 19.5 10.5 16C10.5 12.5 12.5 8.5 16 5Z" stroke="#38BDF8" stroke-width="2"/>
</svg>
SVG;
        case 'finance':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <circle cx="16" cy="16" r="11" stroke="#10B981" stroke-width="2" opacity="0.6"/>
    <path d="M16 7V16L23 19" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M10 21C12 23 14 24 16 24C20 24 23 21 23 17" stroke="#34D399" stroke-width="2" stroke-linecap="round"/>
</svg>
SVG;
        case 'highlights':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M16 6L18.4721 12.5279L25 15L18.4721 17.4721L16 24L13.5279 17.4721L7 15L13.5279 12.5279L16 6Z" fill="url(#gradStar)"/>
    <defs>
        <linearGradient id="gradStar" x1="7" y1="6" x2="25" y2="24" gradientUnits="userSpaceOnUse">
            <stop stop-color="#FDE047"/>
            <stop offset="1" stop-color="#F97316"/>
        </linearGradient>
    </defs>
</svg>
SVG;
        case 'deal':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M10 14L16 20L22 14" stroke="#F472B6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M8 12L14 18L11 23H7L4 19L8 12Z" stroke="#EC4899" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M24 12L18 18L21 23H25L28 19L24 12Z" stroke="#EC4899" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG;
        case 'next':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M8 16H24" stroke="#6366F1" stroke-width="2" stroke-linecap="round"/>
    <path d="M18 10L24 16L18 22" stroke="#A5B4FC" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="10" cy="16" r="3" fill="#6366F1"/>
</svg>
SVG;
        case 'chart':
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <path d="M6 22L13 15L18 21L26 10" stroke="#22D3EE" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    <circle cx="13" cy="15" r="2" fill="#22D3EE"/>
    <circle cx="18" cy="21" r="2" fill="#22D3EE"/>
    <circle cx="26" cy="10" r="2" fill="#22D3EE"/>
</svg>
SVG;
        default:
            return <<<SVG
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
    <circle cx="16" cy="16" r="10" stroke="#94A3B8" stroke-width="2"/>
    <circle cx="16" cy="16" r="3" fill="#94A3B8"/>
    <path d="M16 7V3" stroke="#94A3B8" stroke-width="2" stroke-linecap="round"/>
    <path d="M12 27L16 21L20 27" stroke="#94A3B8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
SVG;
    }
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

/**
 * Извлекает числовое значение из строки с учетом единиц измерения.
 * 
 * Поддерживает:
 * - Разделители: пробелы, запятые, точки
 * - Единицы: "млрд" (умножает на 1000), "тыс" (делит на 1000)
 * - Отрицательные числа
 * 
 * @param string $raw Исходная строка с числом
 * @return float|null Извлеченное число в млн рублей или null, если не найдено
 */

/**
 * Определяет единицы измерения из строки
 * Поддерживает: "тыс. руб.", "млн. руб.", "тыс руб", "млн руб" и их варианты
 * 
 * @param string $unit Строка с единицами измерения
 * @return string 'thousands' для тысяч, 'millions' для миллионов, 'unknown' если не определено
 */
function detectFinancialUnit(string $unit): string {
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
function convertFinancialToMillions($value, string $unit): float {
    $numValue = parseNumericValue($value);
    if ($numValue === null || $numValue == 0) {
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

function extractNumericValue(string $raw): ?float
{
    $normalized = str_replace([' ', ' '], '', $raw);
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

/**
 * Извлекает данные DCF модели для графика напрямую из формы
 * Использует те же алгоритмы, что и calculateUserDCF, но упрощенно - только для графика
 * 
 * @param array $form Данные формы из БД
 * @param TeaserLogger|null $logger Опциональный логгер для записи в файл
 * @return array|null Структура данных DCF (rows, columns) или null при ошибке
 */
function extractDCFDataForChart(array $form, ?TeaserLogger $logger = null): ?array
{
    // Сохраняем form_id в локальную переменную до любых операций
    $formId = $form['id'] ?? 'unknown';
    
    if ($logger) {
        $logger->log("extractDCFDataForChart called for form_id={$formId}", 'INFO');
    }
    
    // Проверяем, что $form не пустой и является массивом
    if (empty($form) || !is_array($form)) {
        if ($logger) {
            $logger->log("Invalid form data provided for form_id={$formId}: empty=" . (empty($form) ? 'yes' : 'no') . ", is_array=" . (is_array($form) ? 'yes' : 'no'), 'WARNING');
        }
        error_log('extractDCFDataForChart: Invalid form data provided');
        return null;
    }
    
    // ВАЖНО: Сохраняем копию $form в локальной переменной перед include
    // чтобы она не была изменена внутри dashboard.php
    $localForm = $form;
    
    // Используем output buffering для безопасного подключения dashboard.php
    ob_start();
    $dcfData = null;
    try {
        // Устанавливаем флаг, чтобы dashboard.php не выполнял HTML вывод
        if (!defined('DCF_API_MODE')) {
        define('DCF_API_MODE', true);
        }
        
        // Проверяем, определена ли функция calculateUserDCF
        if (!function_exists('calculateUserDCF')) {
            $dashboardPath = __DIR__ . '/dashboard.php';
            if (file_exists($dashboardPath)) {
                // Включаем файл с перехватом вывода
                include $dashboardPath;
            }
        }
        
        // Если функция доступна и $localForm валиден, вызываем её
        // Используем $localForm вместо $form, чтобы не зависеть от изменений в dashboard.php
        if (function_exists('calculateUserDCF') && !empty($localForm) && is_array($localForm)) {
            if ($logger) {
                $logger->log("Calling calculateUserDCF for form_id=" . ($localForm['id'] ?? 'unknown'), 'INFO');
            }
            
            $dcfData = calculateUserDCF($localForm);
            
            // Детальное логирование результата calculateUserDCF
            if ($dcfData === null) {
                if ($logger) {
                    $logger->log("calculateUserDCF returned null", 'WARNING');
                }
            } elseif (is_array($dcfData)) {
                $resultKeys = array_keys($dcfData);
                if ($logger) {
                    $logger->log("calculateUserDCF returned array with keys: " . implode(', ', $resultKeys), 'INFO');
                }
                
            if (isset($dcfData['error'])) {
                    $errorMsg = $dcfData['error'] ?? 'unknown';
                    if ($logger) {
                        $logger->log("DCF calculation returned error: " . $errorMsg, 'ERROR');
                    }
                $dcfData = null; // Игнорируем ошибки DCF для графика
                } elseif (isset($dcfData['rows'])) {
                    $rowsCount = is_array($dcfData['rows']) ? count($dcfData['rows']) : 0;
                    if ($logger) {
                        $logger->log("DCF calculation returned rows_count=" . $rowsCount, 'INFO');
                    }
                    
                    if ($rowsCount === 0) {
                        if ($logger) {
                            $logger->log("WARNING - rows_count=0. DCF data structure: " . json_encode($resultKeys), 'WARNING');
                            $dcfDataWithoutRows = array_diff_key($dcfData, ['rows' => true]);
                            $logger->log("Full DCF result (without rows): " . json_encode($dcfDataWithoutRows), 'WARNING');
                        }
                    }
                } else {
                    if ($logger) {
                        $logger->log("WARNING - DCF result has no 'rows' key. Available keys: " . implode(', ', $resultKeys), 'WARNING');
                    }
                }
            } else {
                if ($logger) {
                    $logger->log("calculateUserDCF returned non-array: " . gettype($dcfData), 'WARNING');
                }
            }
        } else {
            if ($logger) {
                // Детальное логирование причины, почему условие не выполнилось
                // Используем $localForm вместо $form
                $hasFunction = function_exists('calculateUserDCF');
                $formIdCheck = $localForm['id'] ?? 'unknown';
                $isEmpty = empty($localForm);
                $isArray = is_array($localForm);
                
                $logger->log("calculateUserDCF check failed for form_id={$formIdCheck}: function_exists=" . ($hasFunction ? 'yes' : 'no') . ", empty_form=" . ($isEmpty ? 'yes' : 'no') . ", is_array=" . ($isArray ? 'yes' : 'no'), 'WARNING');
            }
        }
    } catch (Exception $e) {
        error_log('DCF calculation error in teaser: ' . $e->getMessage());
        error_log('DCF calculation error trace: ' . $e->getTraceAsString());
        $dcfData = null;
    } catch (Throwable $e) {
        error_log('Fatal DCF calculation error in teaser: ' . $e->getMessage());
        error_log('DCF calculation error trace: ' . $e->getTraceAsString());
        $dcfData = null;
    } finally {
        // Очищаем буфер вывода (на случай, если dashboard.php что-то вывел)
        ob_end_clean();
    }
    
    return $dcfData;
}

/**
 * Извлекает данные для графика из результатов DCF модели
 * 
 * Преобразует структуру данных DCF (с периодами P1, P2, P3...) в формат,
 * понятный для renderTeaserChart (с метками 2026П, 2027П...)
 * 
 * ВАЖНО: P1 теперь соответствует 2026П (первый прогнозный период)
 * 
 * @param array $dcfData Результаты расчета DCF модели (rows, columns)
 * @return array|null Массив серий данных для графика или null, если данных недостаточно
 */
function buildTeaserTimelineFromDCF(array $dcfData): ?array
{
    if (empty($dcfData['rows']) || !is_array($dcfData['rows'])) {
        error_log('buildTeaserTimelineFromDCF: empty or invalid rows');
        return null;
    }
    
    // Маппинг периодов DCF на метки для графика
    // P1 теперь соответствует 2026П (первый прогнозный период)
    $periodMapping = [
        '2022' => '2022',
        '2023' => '2023',
        '2024' => '2024',
        '2025' => '2025',
        'P1' => '2026П',  // Первый прогнозный период - 2026 год
        'P2' => '2027П',  // Второй прогнозный период - 2027 год
        'P3' => '2028П',  // Третий прогнозный период - 2028 год
        'P4' => '2029П',
        'P5' => '2030П',
    ];
    
    // Находим строки с нужными метриками
    $revenueRow = null;
    $profitRow = null;
    
    foreach ($dcfData['rows'] as $row) {
        if (!isset($row['label']) || !isset($row['values'])) {
            continue;
        }
        $label = trim($row['label']);
        if ($label === 'Выручка') {
            $revenueRow = $row;
        } elseif ($label === 'Прибыль от продаж') {
            $profitRow = $row;
        }
    }
    
    // Логируем для отладки
    if (!$revenueRow) {
        $availableLabels = array_map(function($r) { return $r['label'] ?? 'no label'; }, $dcfData['rows']);
        error_log('buildTeaserTimelineFromDCF: revenue row not found. Available labels: ' . implode(', ', $availableLabels));
    } else {
        error_log('buildTeaserTimelineFromDCF: revenue row found. Values: ' . json_encode($revenueRow['values'] ?? 'no values'));
    }
    if (!$profitRow) {
        error_log('buildTeaserTimelineFromDCF: profit row not found');
    } else {
        error_log('buildTeaserTimelineFromDCF: profit row found. Values: ' . json_encode($profitRow['values'] ?? 'no values'));
    }
    
    $series = [];
    
    // Обрабатываем выручку
    if ($revenueRow && !empty($revenueRow['values']) && is_array($revenueRow['values'])) {
        $points = [];
        foreach ($periodMapping as $dcfPeriod => $chartLabel) {
            if (isset($revenueRow['values'][$dcfPeriod])) {
                $value = $revenueRow['values'][$dcfPeriod];
                if ($value !== null && is_numeric($value)) {
                    // DCF значения уже нормализованы до млн ₽ на этапе расчета
                    $points[] = [
                        'label' => $chartLabel,
                        'value' => (float)$value,
                    ];
                }
            }
        }
        if (count($points) >= 2) {
            $series[] = [
                'title' => 'Выручка',
                'unit' => 'млн ₽',
                'points' => $points,
            ];
        } else {
            error_log('buildTeaserTimelineFromDCF: revenue points count < 2. Points: ' . json_encode($points));
            error_log('buildTeaserTimelineFromDCF: revenue row values: ' . json_encode($revenueRow['values']));
        }
    } else {
        if ($revenueRow) {
            error_log('buildTeaserTimelineFromDCF: revenue row values empty or not array. Values: ' . json_encode($revenueRow['values'] ?? 'no values key'));
        }
    }
    
    // Обрабатываем прибыль от продаж
    if ($profitRow && !empty($profitRow['values']) && is_array($profitRow['values'])) {
        $points = [];
        foreach ($periodMapping as $dcfPeriod => $chartLabel) {
            if (isset($profitRow['values'][$dcfPeriod])) {
                $value = $profitRow['values'][$dcfPeriod];
                if ($value !== null && is_numeric($value)) {
                    // DCF значения уже нормализованы до млн ₽ на этапе расчета
                    $points[] = [
                        'label' => $chartLabel,
                        'value' => (float)$value,
                    ];
                }
            }
        }
        if (count($points) >= 2) {
            $series[] = [
                'title' => 'Прибыль от продаж',
                'unit' => 'млн ₽',
                'points' => $points,
            ];
        } else {
            error_log('buildTeaserTimelineFromDCF: profit points count < 2. Points: ' . json_encode($points));
            error_log('buildTeaserTimelineFromDCF: profit row values: ' . json_encode($profitRow['values']));
        }
    } else {
        if ($profitRow) {
            error_log('buildTeaserTimelineFromDCF: profit row values empty or not array. Values: ' . json_encode($profitRow['values'] ?? 'no values key'));
        }
    }
    
    return $series ?: null;
}

/**
 * Строит временную линию финансовых данных из payload анкеты (резервный метод).
 * 
 * Используется как fallback, когда данные DCF модели недоступны.
 * Извлекает финансовые показатели из структуры payload['financial'] и формирует
 * серии данных для графика динамики финансов.
 * 
 * @param array $payload Данные анкеты с финансовыми показателями
 * @return array|null Массив серий данных для графика или null, если данных недостаточно
 */
function buildTeaserTimeline(array $payload): ?array
{
    if (empty($payload['financial']) || !is_array($payload['financial'])) {
        return null;
    }
    // Маппинг колонок финансовых данных на метки периодов для графика
    $periods = [
        '2022_fact' => '2022',
        '2023_fact' => '2023',
        '2024_fact' => '2024',
        '2025_fact' => '2025',
        '2026_budget' => '2026П',
    ];
    // Определение метрик для отображения на графике
    $metrics = [
        'revenue' => ['title' => 'Выручка', 'unit' => 'млн ₽'],
        'sales_profit' => ['title' => 'Прибыль от продаж', 'unit' => 'млн ₽'],
    ];
    $series = [];

    // Обработка каждой метрики
    foreach ($metrics as $key => $meta) {
        if (empty($payload['financial'][$key]) || !is_array($payload['financial'][$key])) {
            continue;
        }
        $row = $payload['financial'][$key];
        // Определяем единицы измерения из поля unit
        $unitStr = $row['unit'] ?? '';
        $unit = detectFinancialUnit($unitStr);
        
        $points = [];
        // Сбор точек данных для каждого периода
        foreach ($periods as $column => $label) {
            if (empty($row[$column])) {
                continue;
            }
            // Конвертируем значение в миллионы рублей с учетом единиц
            $value = convertFinancialToMillions($row[$column], $unit);
            if ($value === null || $value == 0) {
                continue;
            }
            $points[] = [
                'label' => $label,
                'value' => $value,
            ];
        }
        // Добавляем серию только если есть минимум 2 точки данных
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

/**
 * Проверяет наличие метки периода в сериях данных графика.
 * 
 * @param array $series Массив серий данных графика
 * @param string $label Метка периода для поиска (например, '2025П')
 * @return bool true, если метка найдена хотя бы в одной серии
 */
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

/**
 * Извлекает числовое значение для указанной метки периода из массива точек.
 * 
 * @param array $points Массив точек данных [['label' => '2026П', 'value' => 1000], ...]
 * @param string $label Метка периода для поиска
 * @return float|null Значение точки или null, если метка не найдена
 */
function valueForLabel(array $points, string $label): ?float
{
    foreach ($points as $point) {
        if ($point['label'] === $label) {
            return $point['value'];
        }
    }
    return null;
}

/**
 * Генерирует HTML для блока финансового графика с использованием ApexCharts
 * 
 * Функциональность:
 * - Формирует список периодов (годы) из данных серий
 * - Создает JSON payload для ApexCharts с категориями, сериями данных и цветами
 * - Поддерживает отображение нескольких метрик (Выручка, Прибыль от продаж)
 * - Генерирует HTML контейнер с атрибутом data-chart, содержащим JSON данные
 * - График инициализируется на клиенте через JavaScript (initTeaserCharts)
 * 
 * Параметры:
 * @param array $series Массив серий данных, каждая содержит:
 *                      - 'title': название метрики (например, 'Выручка')
 *                      - 'unit': единица измерения (например, 'млн ₽')
 *                      - 'points': массив точек данных с 'label' и 'value'
 * 
 * Возвращает:
 * @return string HTML код блока графика или пустую строку, если данных недостаточно
 * 
 * Создано: 2025-01-XX
 */
function renderTeaserChart(array $series): string
{
    // Порядок отображения периодов (фактические годы и прогнозные)
    // P1 теперь соответствует 2026П
    $periodOrder = ['2022', '2023', '2024', '2025', '2026П', '2027П', '2028П'];
    $labels = [];
    
    // Сбор меток периодов в правильном порядке
    foreach ($periodOrder as $label) {
        if (seriesHasLabel($series, $label)) {
            $labels[] = $label;
        }
    }
    // Добавление любых дополнительных меток, не входящих в стандартный порядок
    foreach ($series as $metric) {
        foreach ($metric['points'] as $point) {
            if (!in_array($point['label'], $labels, true)) {
                $labels[] = $point['label'];
            }
        }
    }
    // Проверка наличия достаточного количества данных для графика
    if (count($labels) < 2) {
        return '';
    }

    /**
     * Формирование JSON payload для ApexCharts
     * Структура данных, которая будет передана в JavaScript для инициализации графика
     */
    $apexPayload = [
        'categories' => $labels,  // Метки по оси X (периоды)
        'unit' => 'млн ₽',        // Единица измерения
        'series' => [],           // Массив серий данных
        'colors' => ['#6366F1', '#0EA5E9', '#F97316', '#10B981'],  // Цвета для линий графика
    ];

    // Преобразование данных серий в формат ApexCharts
    foreach ($series as $index => $metric) {
        $dataPoints = [];
        // Создание массива точек данных для каждого периода
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

    // Кодирование JSON данных для безопасного размещения в HTML атрибуте
    $chartJson = htmlspecialchars(json_encode($apexPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');

    // Генерация HTML блока графика
    $html = '<div class="teaser-card teaser-chart-card" data-variant="chart">';
    $html .= '<div class="teaser-card__icon" aria-hidden="true">' . teaserSvgIcon('chart') . '</div>';
    $html .= '<h3>Динамика финансов</h3>';
    // Контейнер с атрибутом data-chart, содержащим JSON данные для ApexCharts
    $html .= '<div class="teaser-chart" data-chart="' . $chartJson . '"></div>';
    $html .= '<p class="teaser-chart__note">Показатели указаны в млн ₽. Источник: анкета продавца (факт + бюджет).</p>';
    $html .= '</div>';
    return $html;
}

function normalizeTeaserData(array $data, array $payload, bool $isStartup = false): array
{
    $placeholder = 'Дополнительные сведения доступны по запросу.';
    $placeholderStartup = 'уточняется'; // Более подходящий placeholder для стартапов
    $assetName = $payload['asset_name'] ?? 'Актив';
    $companyDesc = trim((string)($payload['company_description'] ?? ''));

    $data['overview'] = [
        'title' => $data['overview']['title'] ?? $assetName,
        'summary' => buildHeroSummary(
            $data['overview']['summary'] ?? null,
            $payload,
            $isStartup ? $placeholderStartup : $placeholder
        ),
        'key_metrics' => normalizeArray($data['overview']['key_metrics'] ?? [
            formatMetric('Персонал', $payload['personnel_count'] ?? ($isStartup ? $placeholderStartup : 'уточняется')),
            formatMetric('Доля продаж онлайн', $payload['online_sales_share'] ?? ($isStartup ? $placeholderStartup : 'уточняется')),
        ]),
    ];

    if ($isStartup) {
        // Нормализация данных для стартапов - используем "уточняется" для пустых полей
        $data['product_technology'] = [
            'product_description' => $data['product_technology']['product_description'] ?? ($payload['startup_product_description'] ?? $placeholderStartup),
            'technology' => $data['product_technology']['technology'] ?? ($payload['startup_technology_description'] ?? $placeholderStartup),
            'ip_patents' => $data['product_technology']['ip_patents'] ?? ($payload['startup_patents_ip'] ?? $placeholderStartup),
            'product_stage' => $data['product_technology']['product_stage'] ?? ($payload['startup_product_stage'] ?? $placeholderStartup),
        ];

        $data['team'] = [
            'founders' => $data['team']['founders'] ?? ($payload['startup_shareholders'] ?? $placeholderStartup),
            'key_employees' => $data['team']['key_employees'] ?? ($payload['startup_key_employees'] ?? $placeholderStartup),
            'headcount' => $data['team']['headcount'] ?? ($payload['personnel_count'] ?? $placeholderStartup),
            'social_links' => $data['team']['social_links'] ?? ($payload['startup_social_links'] ?? $placeholderStartup),
        ];

        $data['traction'] = [
            'users' => $data['traction']['users'] ?? ($payload['startup_user_count'] ?? $placeholderStartup),
            'mrr' => $data['traction']['mrr'] ?? ($payload['startup_mrr'] ?? $placeholderStartup),
            'dau_mau' => $data['traction']['dau_mau'] ?? ($payload['startup_dau_mau'] ?? $placeholderStartup),
            'conversion' => $data['traction']['conversion'] ?? ($payload['startup_conversion_rate'] ?? $placeholderStartup),
            'retention' => $data['traction']['retention'] ?? ($payload['startup_retention_rate'] ?? $placeholderStartup),
            'pilots' => $data['traction']['pilots'] ?? ($payload['startup_pilot_projects'] ?? $placeholderStartup),
        ];

        $data['roadmap'] = [
            'development_plan' => $data['roadmap']['development_plan'] ?? ($payload['startup_roadmap'] ?? $placeholderStartup),
            'scaling_plans' => $data['roadmap']['scaling_plans'] ?? ($payload['startup_scaling_plans'] ?? $placeholderStartup),
            'funding_usage' => $data['roadmap']['funding_usage'] ?? ($payload['startup_funding_usage'] ?? $placeholderStartup),
        ];

        // Финансовые показатели и прогнозы для стартапов
        $revenue2023 = $payload['startup_revenue_2023'] ?? null;
        $revenue2024 = $payload['startup_revenue_2024'] ?? null;
        $revenue2025 = $payload['startup_revenue_2025'] ?? null;
        
        $data['financials_forecast'] = [
            'revenue_2023' => $data['financials_forecast']['revenue_2023'] ?? ($revenue2023 !== null ? number_format($revenue2023, 0, '.', ' ') . ' млн ₽' : $placeholderStartup),
            'revenue_2024' => $data['financials_forecast']['revenue_2024'] ?? ($revenue2024 !== null ? number_format($revenue2024, 0, '.', ' ') . ' млн ₽' : $placeholderStartup),
            'revenue_2025' => $data['financials_forecast']['revenue_2025'] ?? ($revenue2025 !== null ? number_format($revenue2025, 0, '.', ' ') . ' млн ₽' : $placeholderStartup),
            'forecast' => $data['financials_forecast']['forecast'] ?? $placeholderStartup,
            'unit_economics' => $data['financials_forecast']['unit_economics'] ?? $placeholderStartup,
            'valuation' => $data['financials_forecast']['valuation'] ?? ($payload['startup_current_valuation'] ?? $placeholderStartup),
        ];
    } else {
        // Нормализация данных для зрелых компаний
        // Формируем описание операционной модели на основе данных анкеты
        $operationsModel = $data['company_profile']['operations'] ?? null;
        // Если AI не предоставил описание или это placeholder, пытаемся сформировать из данных анкеты
        if (empty($operationsModel) || $operationsModel === $placeholder || trim($operationsModel) === '') {
            $operationsModel = buildOperationsModel($payload);
        }

    $data['company_profile'] = [
        'industry' => $data['company_profile']['industry'] ?? ($payload['products_services'] ?? $placeholder),
        'established' => $data['company_profile']['established'] ?? ($payload['production_area'] ? 'Бизнес с развитой инфраструктурой' : $placeholder),
        'headcount' => $data['company_profile']['headcount'] ?? ($payload['personnel_count'] ?? $placeholder),
        'locations' => $data['company_profile']['locations'] ?? ($payload['presence_regions'] ?? $placeholder),
            // Используем сформированное описание или null (будет скрыто при отображении)
            'operations' => $operationsModel,
        'unique_assets' => $data['company_profile']['unique_assets'] ?? ($payload['company_brands'] ?? $placeholder),
    ];

    $data['products'] = [
        'portfolio' => $data['products']['portfolio'] ?? ($payload['products_services'] ?? $placeholder),
        'differentiators' => $data['products']['differentiators'] ?? ($payload['additional_info'] ?? $placeholder),
        'key_clients' => $data['products']['key_clients'] ?? ($payload['main_clients'] ?? $placeholder),
        'sales_channels' => $data['products']['sales_channels'] ?? buildSalesChannelsText($payload),
    ];

        // Извлекаем финансовые данные с учетом единиц измерения для зрелых компаний
        $revenueRaw = $payload['financial']['revenue']['2024_fact'] ?? null;
        $revenueUnit = detectFinancialUnit($payload['financial']['revenue']['unit'] ?? '');
        $revenueValue = $revenueRaw !== null ? convertFinancialToMillions($revenueRaw, $revenueUnit) : null;
        $revenueText = $revenueValue !== null ? number_format($revenueValue, 0, '.', ' ') . ' млн ₽' : $placeholder;
        
        $profitRaw = $payload['financial']['sales_profit']['2024_fact'] ?? null;
        $profitUnit = detectFinancialUnit($payload['financial']['sales_profit']['unit'] ?? '');
        $profitValue = $profitRaw !== null ? convertFinancialToMillions($profitRaw, $profitUnit) : null;
        $profitText = $profitValue !== null ? number_format($profitValue, 0, '.', ' ') . ' млн ₽' : $placeholder;
        
        $capexRaw = $payload['financial']['fixed_assets_acquisition']['2024_fact'] ?? null;
        $capexUnit = detectFinancialUnit($payload['financial']['fixed_assets_acquisition']['unit'] ?? '');
        $capexValue = $capexRaw !== null ? convertFinancialToMillions($capexRaw, $capexUnit) : null;
        $capexText = $capexValue !== null ? number_format($capexValue, 0, '.', ' ') . ' млн ₽' : 'Низкая CAPEX-нагрузка.';

        $data['financials'] = [
            'revenue' => $data['financials']['revenue'] ?? $revenueText,
            'ebitda' => $data['financials']['ebitda'] ?? $profitText,
            'margins' => $data['financials']['margins'] ?? 'Маржинальность уточняется.',
            'capex' => $data['financials']['capex'] ?? $capexText,
            'notes' => $data['financials']['notes'] ?? 'Финансовые показатели подтверждены данными анкеты.',
        ];
    }

    // Блок market общий для обоих типов компаний
    $marketInsight = enrichMarketInsight($payload, $data['market'] ?? []);
    $data['market'] = [
        'trend' => $marketInsight['trend'],
        'size' => $marketInsight['size'],
        'growth' => $marketInsight['growth'],
        'sources' => normalizeArray($marketInsight['sources']),
    ];

    $data['highlights']['bullets'] = normalizeArray($data['highlights']['bullets'] ?? buildHighlightBullets($payload, $placeholder));

    // Используем финальную цену продажи, если она указана
    // Проверяем все возможные источники цены предложения продавца
    $finalPrice = null;
    
    // 1. Прямо из payload
    if (isset($payload['final_price']) && $payload['final_price'] > 0) {
        $finalPrice = (float)$payload['final_price'];
        error_log('normalizeTeaserData: Found final_price in payload: ' . $finalPrice);
    } elseif (isset($payload['final_selling_price']) && $payload['final_selling_price'] > 0) {
        $finalPrice = (float)$payload['final_selling_price'];
        error_log('normalizeTeaserData: Found final_selling_price in payload: ' . $finalPrice);
    }
    
    // 2. Из data_json в payload (если цена не найдена выше)
    if ($finalPrice === null && !empty($payload['data_json'])) {
        $formDataJson = is_string($payload['data_json']) ? json_decode($payload['data_json'], true) : $payload['data_json'];
        if (is_array($formDataJson)) {
            if (isset($formDataJson['final_price']) && $formDataJson['final_price'] > 0) {
                $finalPrice = (float)$formDataJson['final_price'];
                error_log('normalizeTeaserData: Found final_price in data_json: ' . $finalPrice);
            } elseif (isset($formDataJson['final_selling_price']) && $formDataJson['final_selling_price'] > 0) {
                $finalPrice = (float)$formDataJson['final_selling_price'];
                error_log('normalizeTeaserData: Found final_selling_price in data_json: ' . $finalPrice);
            }
        }
    }
    
    // 3. Проверяем, не была ли цена уже установлена AI в data['deal_terms']['price']
    // Если AI вернул цену, но она не совпадает с ценой продавца, приоритет у цены продавца
    if ($finalPrice === null && !empty($data['deal_terms']['price'])) {
        $aiPriceText = trim((string)$data['deal_terms']['price']);
        // Пытаемся извлечь числовое значение из текста AI (например, "Цена актива: 100 млн ₽")
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(?:млн|млн\s*₽|₽)/u', $aiPriceText, $matches)) {
            $extractedPrice = (float)str_replace(',', '.', $matches[1]);
            if ($extractedPrice > 0) {
                error_log('normalizeTeaserData: Extracted price from AI text: ' . $extractedPrice);
                // Используем цену от AI только если нет цены продавца
                // Но лучше не использовать, так как приоритет у цены продавца
            }
        }
    }
    
    if ($finalPrice === null) {
        error_log('normalizeTeaserData: No final price found in payload or data_json');
    }
    
    // Проверяем, содержит ли deal_goal только cash_out
    $isOnlyCashOut = isOnlyCashOut($payload['deal_goal'] ?? '');
    
    // Формируем цену: приоритет у цены предложения продавца, если она есть
    $priceText = null;
    if ($finalPrice !== null && $finalPrice > 0) {
        $priceText = 'Цена актива: ' . number_format($finalPrice, 0, '.', ' ') . ' млн ₽';
    } elseif (!empty($data['deal_terms']['price'])) {
        // Если цена от AI уже есть и она валидная, используем её
        $aiPrice = trim((string)$data['deal_terms']['price']);
        // Проверяем, что это не placeholder и содержит информацию о цене
        if ($aiPrice !== '' && 
            stripos($aiPrice, 'уточняется') === false && 
            stripos($aiPrice, 'обсуждается') === false &&
            (stripos($aiPrice, 'цена') !== false || stripos($aiPrice, 'млн') !== false || stripos($aiPrice, '₽') !== false)) {
            $priceText = $aiPrice;
        }
    }
    
    $data['deal_terms'] = [
        'structure' => $data['deal_terms']['structure'] ?? (($payload['deal_goal'] ?? '') ?: 'Гибкая структура сделки.'),
        'share_for_sale' => $data['deal_terms']['share_for_sale'] ?? ($payload['deal_share_range'] ?? 'Доля обсуждается.'),
        'valuation_expectation' => $data['deal_terms']['valuation_expectation'] ?? 'Ожидаемая оценка обсуждается с инвестором.',
        'price' => $priceText,
        // Не указываем направление средств на развитие, если цель сделки - только cash_out (выход продавца)
        'use_of_proceeds' => $data['deal_terms']['use_of_proceeds'] ?? ($isOnlyCashOut ? null : 'Средства будут направлены на масштабирование бизнеса.'),
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

    // Определяем, является ли это стартапом
    $companyType = $payload['company_type'] ?? null;
    $isStartup = ($companyType === 'startup');

    try {
        $prompt = buildOverviewRefinementPrompt($data['overview'], $payload, $isStartup);
        $aiText = trim(callAICompletions($prompt, $apiKey));
        $aiText = constrainToRussianNarrative(sanitizeAiArtifacts(strip_tags($aiText)));
        
        // Проверяем, не содержит ли ответ сам промпт
        $promptPhrases = [
            'Ты инвестиционный банкир',
            'На основе фактов ниже',
            'Исходная версия',
            'Факты:',
            'Напиши описание компании',
            'Формат:',
            'Используй только факты',
        ];
        $containsPrompt = false;
        foreach ($promptPhrases as $phrase) {
            if (stripos($aiText, $phrase) !== false) {
                $containsPrompt = true;
                error_log('Overview response contains prompt phrase: ' . $phrase);
                break;
            }
        }
        
        // Проверяем, не содержит ли ответ JSON структуру промпта
        if (preg_match('/\{[\s\n]*"overview"[\s\n]*:/i', $aiText) || 
            preg_match('/"summary"[\s\n]*:[\s\n]*"[^"]*Ты/i', $aiText)) {
            $containsPrompt = true;
            error_log('Overview response contains JSON prompt structure');
        }
        
        // Если ответ содержит промпт, используем fallback
        if ($containsPrompt) {
            error_log('Overview response rejected: contains prompt text, using fallback');
            $aiText = '';
        }
        
        if ($aiText !== '') {
            // Remove "M&A платформа" and similar phrases
            $aiText = preg_replace('/\bM&[Aa]mp;?[Aa]тр?[АA]?\s+платформа\b/ui', '', $aiText);
            $aiText = preg_replace('/\bM&[Aa]mp;?[Aa]тр?[АA]?\s+платформы?\b/ui', '', $aiText);
            $aiText = preg_replace('/\bплатформа\s+M&[Aa]mp;?[Aa]тр?[АA]?\b/ui', '', $aiText);
            
            // Удаляем JavaScript код и технические символы, если они попали в текст
            $aiText = preg_replace('/function\s*[a-zA-Z_$][a-zA-Z0-9_$]*\s*\([^)]*\)\s*\{[^}]*\}/s', '', $aiText);
            $aiText = preg_replace('/var\s+[a-zA-Z_$][a-zA-Z0-9_$]*\s*=/', '', $aiText);
            $aiText = preg_replace('/document\.(getElementById|querySelector|createElement|scripts)/i', '', $aiText);
            $aiText = preg_replace('/\bmi\s*=\s*[^;]+;/i', '', $aiText);
            $aiText = preg_replace('/\bmi\.[a-zA-Z]+\s*=/i', '', $aiText);
            
            // Удаляем строки, которые выглядят как код (содержат много специальных символов)
            $lines = explode("\n", $aiText);
            $filteredLines = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    $filteredLines[] = '';
                    continue;
                }
                // Пропускаем строки, которые выглядят как код
                if (preg_match('/[{}();=<>\[\]]{3,}/', $line)) {
                    continue;
                }
                // Пропускаем строки с большим количеством английских слов подряд без русских букв
                if (preg_match('/^[a-zA-Z0-9\s.,;:(){}[\]<>=\-+*\/&|!@#$%^]+$/', $line) && 
                    !preg_match('/[а-яА-ЯёЁ]/u', $line) && 
                    mb_strlen($line) > 20) {
                    continue;
                }
                $filteredLines[] = $line;
            }
            $aiText = implode("\n", $filteredLines);
            
            // Сохраняем двойные переносы строк для абзацев, заменяем только множественные пробелы и табы
            $aiText = preg_replace('/[ \t]+/', ' ', $aiText); // Заменяем только пробелы и табы, не переносы строк
            // Нормализуем переносы строк: оставляем только одиночные и двойные
            $aiText = preg_replace('/\n{3,}/', "\n\n", $aiText); // Множественные переносы -> двойные
            $aiText = trim($aiText);
            
            if ($aiText !== '') {
                $sentences = splitIntoSentences($aiText);
                $data['overview']['summary'] = buildParagraphsFromSentences(
                    $sentences,
                    buildOverviewFallbackSentences($payload),
                    3,
                    3
                );
            }
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
        $raw = callAICompletions($prompt, $apiKey);
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
    $assetDisclosure = $payload['asset_disclosure'] ?? '';
    $isNameHidden = ($assetDisclosure === 'no' || $assetDisclosure === 'нет' || $asset === 'Актив');
    
    // Для скрытых активов используем нейтральное название
    if ($isNameHidden) {
        $asset = 'компании';
    }
    
    $nameInstruction = '';
    if ($isNameHidden) {
        $nameInstruction = "\nВАЖНО: Название компании скрыто. НЕ используй слово 'Актив' в тексте вообще. Можно использовать 'Компания' или 'Фирма', но НЕ упоминай 'Актив' ни в каком виде.";
    }
    
    $json = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return <<<PROMPT
Ты маркетолог инвестиционного банка. Переведи и переформулируй на красивом русском языке описания блока "Продукты и клиенты" для {$asset}.{$nameInstruction}
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

/**
 * Определяет, нужно ли улучшать блок "Обзор возможности" через дополнительный AI-запрос.
 * 
 * Улучшение требуется, если:
 * - summary пустой
 * - содержит фразу "Информация уточняется"
 * - содержит фразу "Ключевые преимущества" (устаревший формат)
 * 
 * @param array $overview Данные блока overview
 * @return bool true, если требуется улучшение
 */
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

/**
 * Формирует промпт для улучшения блока "Обзор возможности" для стартапов.
 * 
 * @param array $overview Текущие данные блока overview
 * @param string $factsJson JSON с фактами из анкеты
 * @param string $nameInstruction Инструкция по скрытому имени
 * @param string $languageInstruction Инструкция по языку
 * @param string $fourthParagraphInstruction Инструкция для 4-го абзаца
 * @return string Промпт для AI
 */
function buildStartupOverviewRefinementPrompt(array $overview, string $factsJson, string $nameInstruction, string $languageInstruction, string $fourthParagraphInstruction): string
{
    $existingSummary = trim((string)($overview['summary'] ?? ''));
    
    return <<<PROMPT
Ты инвестиционный банкир. На основе фактов ниже напиши компактный блок "Обзор возможности" строго на русском языке.{$nameInstruction}{$languageInstruction}
- Стиль: деловой и живой тон без канцелярита.
- Сформируй несколько абзацев (2-4 абзаца), в каждом по 2-3 предложения. Делай переходы логичными: 1) кто компания и что делает, 2) ключевые показатели и достижения, 3) конкурентные преимущества и потенциал, {$fourthParagraphInstruction}
- Используй только приведённые факты, не придумывай цифры или названия.
- Внутри предложений соединяй части запятыми, избегай сухих списков.
- Каждый абзац должен быть отделён пустой строкой от предыдущего.

Исходная версия: "{$existingSummary}"

Факты:
{$factsJson}
PROMPT;
}

/**
 * Формирует промпт для улучшения блока "Обзор возможности" через AI.
 * 
 * Собирает факты из анкеты и формирует структурированный промпт для генерации
 * улучшенного текста обзора с четырьмя абзацами по одному предложению.
 * 
 * @param array $overview Текущие данные блока overview
 * @param array $payload Данные анкеты
 * @param bool $isStartup Является ли компания стартапом
 * @return string Промпт для AI
 */
function buildOverviewRefinementPrompt(array $overview, array $payload, bool $isStartup = false): string
{
    $assetName = $payload['asset_name'] ?? '';
    $assetDisclosure = $payload['asset_disclosure'] ?? '';
    $isNameHidden = ($assetDisclosure === 'no' || $assetDisclosure === 'нет' || $assetName === 'Актив');
    
    // Для скрытых активов не передаем название или используем нейтральное
    $nameFact = '';
    if (!$isNameHidden && $assetName !== '') {
        $nameFact = $assetName;
    }
    
    if ($isStartup) {
        // Факты для стартапов
    $facts = [
            'Название' => $nameFact !== '' ? $nameFact : '',
            'Продукт' => $payload['startup_product_description'] ?? '',
            'Технология' => $payload['startup_technology_description'] ?? '',
            'Стадия продукта' => $payload['startup_product_stage'] ?? '',
            'Пользователи' => $payload['startup_users_count'] ?? '',
            'MRR' => $payload['startup_mrr'] ?? '',
            'DAU/MAU' => ($payload['startup_dau'] ?? '') . '/' . ($payload['startup_mau'] ?? ''),
            'Конверсия' => $payload['startup_conversion_rate'] ?? '',
            'Retention' => $payload['startup_retention_rate'] ?? '',
            'Команда' => $payload['startup_key_employees'] ?? '',
            'Акционеры' => $payload['startup_shareholders'] ?? '',
            'Рынок' => $payload['startup_target_market'] ?? '',
            'Конкуренты' => $payload['startup_competitors'] ?? '',
            'Преимущества' => $payload['startup_competitive_advantages'] ?? '',
            'Roadmap' => $payload['startup_roadmap'] ?? '',
            'Планы масштабирования' => $payload['startup_scaling_plans'] ?? '',
            'Использование инвестиций' => $payload['startup_funding_usage'] ?? '',
            'Выручка 2023' => $payload['startup_revenue_2023'] ?? '',
            'Выручка 2024' => $payload['startup_revenue_2024'] ?? '',
            'Выручка 2025' => $payload['startup_revenue_2025'] ?? '',
            'Прогноз роста' => $payload['startup_forecast'] ?? '',
            'Оценка' => $payload['startup_valuation'] ?? '',
            'Требуемые инвестиции' => $payload['startup_investment_amount'] ?? '',
            'Цель сделки' => $payload['deal_goal'] ?? '',
            'Доля к продаже' => $payload['deal_share_range'] ?? '',
            'Источник сайта' => buildWebsiteInsightSentence($payload) ?? '',
        ];
    } else {
        // Факты для зрелых компаний
        $facts = [
        'Отрасль' => $payload['products_services'] ?? '',
        'Регионы присутствия' => $payload['presence_regions'] ?? '',
        'Клиенты' => $payload['main_clients'] ?? '',
        'Персонал' => $payload['personnel_count'] ?? '',
        'Цель сделки' => $payload['deal_goal'] ?? '',
        'Доля к продаже' => $payload['deal_share_range'] ?? '',
        'Сильные стороны' => implode(', ', buildAdvantageSentences($payload)),
        'Финансовые цели' => buildRevenueGrowthMessage($payload) ?? '',
        'Загрузка мощностей' => $payload['production_load'] ?? '',
        'Источник сайта' => buildWebsiteInsightSentence($payload) ?? '',
    ];
        
        // Добавляем название только если оно не скрыто
        if ($nameFact !== '') {
            $facts = array_merge(['Название' => $nameFact], $facts);
        }
        
        // Не передаем бренды для скрытых активов
        if (!$isNameHidden) {
            $brands = $payload['company_brands'] ?? '';
            if ($brands !== '') {
                $facts['Бренды'] = $brands;
            }
        }
    }

    $facts = array_filter($facts, fn($value) => trim((string)$value) !== '');
    $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $nameInstruction = '';
    if ($isNameHidden) {
        $nameInstruction = "\nВАЖНО: Название компании скрыто. НЕ используй слово 'Актив' в тексте вообще. Можно использовать 'Компания' или 'Фирма', но НЕ упоминай 'Актив' ни в каком виде (ни отдельно, ни в сочетаниях типа 'Компания Актив', 'Компания «Актив»' и т.д.).";
    }
    
    // Проверяем, является ли цель сделки только cash_out (выход продавца)
    $isOnlyCashOut = isOnlyCashOut($payload['deal_goal'] ?? '');
    $cashOutInstruction = '';
    $fourthParagraphInstruction = '4) планы использования инвестиций и ожидаемый рост.';
    if ($isOnlyCashOut) {
        $cashOutInstruction = "\nКРИТИЧЕСКИ ВАЖНО: Цель сделки - только cash-out (выход продавца). Продавец забирает деньги и выходит из бизнеса. НЕ используй фразы о том, что инвестиции позволят ускорить развитие, расширить присутствие, поддержать рост выручки, масштабировать бизнес или что-либо подобное. НЕ упоминай направления использования инвестиций на развитие компании. Фокус должен быть на текущем состоянии бизнеса и его привлекательности для покупателя, а не на планах развития.";
        $fourthParagraphInstruction = '4) текущее состояние бизнеса и его привлекательность для покупателя.';
    }
    
    if ($isStartup) {
        // Для стартапов используем отдельную функцию
        $fourthParagraphInstruction = '4) планы развития, roadmap и использование инвестиций.';
        $languageInstruction = "\nКРИТИЧЕСКИ ВАЖНО: Отвечай строго на русском языке. НЕ используй английские слова, кроме обязательных технических терминов (MVP, SaaS, B2B). ИГНОРИРУЙ любые фрагменты JavaScript кода, HTML теги, технические символы ({}, [], (), =, function, var, let, const, document, window и т.д.) из данных сайта. НЕ повторяй одни и те же предложения. Каждое предложение должно добавлять новую информацию.";
        return buildStartupOverviewRefinementPrompt($overview, $factsJson, $nameInstruction, $languageInstruction, $fourthParagraphInstruction);
    } else {
        // Для зрелых компаний используем оригинальный промпт
        $languageInstruction = '';
    }

    $existingSummary = trim((string)($overview['summary'] ?? ''));

    return <<<PROMPT
Напиши описание компании на основе фактов ниже.{$nameInstruction}{$cashOutInstruction}{$languageInstruction}
- Формат: 2-3 абзаца по 2-3 предложения в каждом.
- Используй только факты из списка, не придумывай цифры.
- Каждый абзац отделяй пустой строкой.

Исходная версия: "{$existingSummary}"

Факты:
{$factsJson}
PROMPT;
}

/**
 * Нормализует значение в массив строк, удаляя пустые элементы.
 * 
 * Если значение уже массив - фильтрует и возвращает его.
 * Если строка - возвращает массив с одним элементом.
 * Если значение пустое - возвращает массив с placeholder-текстом.
 * 
 * @param mixed $value Значение для нормализации
 * @return array Массив строк
 */
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

/**
 * Формирует текст описания каналов продаж из данных анкеты.
 * 
 * Объединяет информацию об оффлайн, онлайн и контрактном производстве
 * в единую строку с разделителями.
 * 
 * @param array $payload Данные анкеты
 * @return string Текст описания каналов продаж
 */
function buildSalesChannelsText(array $payload): string
{
    $channels = [];

    // Offline presence may come as «нет», поэтому нормализуем значение заранее.
    $offline = normalizeChannelValue($payload['offline_sales_presence'] ?? '');
    if ($offline !== '') {
        // Если значение просто "да" или "yes", не добавляем его, так как уже есть метка "Оффлайн"
        if (mb_strtolower($offline, 'UTF-8') === 'да' || mb_strtolower($offline, 'UTF-8') === 'yes') {
            $channels[] = 'Оффлайн';
        } else {
        $channels[] = 'Оффлайн: ' . $offline;
        }
    }

    // Online channels бывают перечислены списком — не скрываем детали.
    $online = normalizeChannelValue($payload['online_sales_channels'] ?? '');
    if ($online !== '') {
        // Если значение просто "да" или "yes", не добавляем его, так как уже есть метка "Онлайн"
        if (mb_strtolower($online, 'UTF-8') === 'да' || mb_strtolower($online, 'UTF-8') === 'yes') {
            $channels[] = 'Онлайн';
        } else {
        $channels[] = 'Онлайн: ' . $online;
        }
    }

    // Contract manufacturing часто содержит английские ответы (yes/no).
    $contract = normalizeChannelValue($payload['contract_production_usage'] ?? '');
    if ($contract !== '') {
        // Если значение просто "да" или "yes", не добавляем его, так как уже есть метка "Контрактное производство"
        if (mb_strtolower($contract, 'UTF-8') === 'да' || mb_strtolower($contract, 'UTF-8') === 'yes') {
            $channels[] = 'Контрактное производство';
        } else {
        $channels[] = 'Контрактное производство: ' . $contract;
        }
    }

    if (empty($channels)) {
        return 'Каналы продаж уточняются.';
    }

    return implode('; ', $channels);
}

/**
 * Приводит значения каналов к читабельной форме и отбрасывает ответы
 * вроде «no», «нет», «n/a». Также переводит английские значения на русский.
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

    // Переводим английские значения на русский
    $translations = [
        'yes' => 'да',
        'true' => 'да',
        '1' => 'да',
        'используется' => 'да',
        'применяется' => 'да',
    ];
    
    if (isset($translations[$plain])) {
        return $translations[$plain];
    }
    
    // Если значение содержит только английские буквы и это короткое слово (yes, no, true, false),
    // переводим его
    if (preg_match('/^[a-zA-Z]+$/', $text) && strlen($text) <= 5) {
        $shortTranslations = [
            'yes' => 'да',
            'true' => 'да',
        ];
        if (isset($shortTranslations[$plain])) {
            return $shortTranslations[$plain];
        }
    }

    return $text;
}

/**
 * Формирует описание операционной модели на основе данных анкеты
 * Анализирует поля: собственное производство, контрактное производство, каналы продаж, загрузка мощностей
 * @param array $payload Данные анкеты
 * @return string|null Описание операционной модели или null, если данных недостаточно
 */
function buildOperationsModel(array $payload): ?string
{
    $parts = [];
    
    // Проверяем наличие собственного производства
    $ownProduction = trim((string)($payload['own_production'] ?? ''));
    $ownProductionLower = mb_strtolower($ownProduction, 'UTF-8');
    if ($ownProduction !== '' && 
        !in_array($ownProductionLower, ['нет', 'no', 'н', '', '0', '-', '—', 'не указано', 'n/a']) &&
        !preg_match('/^(no|нет)(\b|[^a-zA-ZА-Яа-я0-9])/iu', $ownProduction)) {
        $parts[] = 'Собственное производство';
    }
    
    // Проверяем контрактное производство
    $contractProduction = normalizeChannelValue($payload['contract_production_usage'] ?? '');
    if ($contractProduction !== '') {
        $parts[] = 'Контрактное производство';
    }
    
    // Проверяем каналы продаж
    $offline = normalizeChannelValue($payload['offline_sales_presence'] ?? '');
    $online = normalizeChannelValue($payload['online_sales_presence'] ?? '');
    $onlineShare = trim((string)($payload['online_sales_share'] ?? ''));
    
    $salesChannels = [];
    if ($offline !== '') {
        $salesChannels[] = 'офлайн';
    }
    if ($online !== '') {
        if ($onlineShare !== '') {
            $salesChannels[] = 'онлайн (' . $onlineShare . ')';
        } else {
            $salesChannels[] = 'онлайн';
        }
    }
    
    if (!empty($salesChannels)) {
        $parts[] = 'Продажи через ' . implode(' и ', $salesChannels);
    }
    
    // Проверяем загрузку мощностей (если есть производство)
    if (!empty($parts) && $ownProduction !== '' && 
        !in_array($ownProductionLower, ['нет', 'no', 'н', '', '0', '-', '—', 'не указано', 'n/a'])) {
        $productionLoad = trim((string)($payload['production_load'] ?? ''));
        $productionLoadLower = mb_strtolower($productionLoad, 'UTF-8');
        if ($productionLoad !== '' && 
            !in_array($productionLoadLower, ['нет', 'no', 'н', '', '0', '-', '—', 'не указано', 'n/a']) &&
            !preg_match('/^(no|нет)(\b|[^a-zA-ZА-Яа-я0-9])/iu', $productionLoad)) {
            $parts[] = 'Загрузка мощностей: ' . $productionLoad;
        }
    }
    
    // Если есть хотя бы одна часть, формируем описание
    if (!empty($parts)) {
        return implode('. ', $parts) . '.';
    }
    
    // Если данных недостаточно, возвращаем null
    return null;
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
    // Remove "M&A платформа" and similar phrases
    $summary = preg_replace('/\bM&[Aa]mp;?[Aa]тр?[АA]?\s+платформа\b/ui', '', $summary);
    $summary = preg_replace('/\bM&[Aa]mp;?[Aa]тр?[АA]?\s+платформы?\b/ui', '', $summary);
    $summary = preg_replace('/\bплатформа\s+M&[Aa]mp;?[Aa]тр?[АA]?\b/ui', '', $summary);
    $summary = trim(preg_replace('/\s+/', ' ', $summary));
    if ($summary !== '' && !looksLikeStructuredDump($summary)) {
        return enrichSummaryWithAdvantages($summary, $payload);
    }

    $assetName = trim((string)($payload['asset_name'] ?? 'Компания'));
    $assetDisclosure = $payload['asset_disclosure'] ?? '';
    $isNameHidden = ($assetDisclosure === 'no' || $assetDisclosure === 'нет' || $assetName === 'Актив');
    
    // Для скрытых активов используем "Компания" или "Фирма", но не "Компания Актив"
    $displayName = $isNameHidden ? 'Компания' : $assetName;
    
    $industry = trim((string)($payload['products_services'] ?? ''));
    $regions = trim((string)($payload['presence_regions'] ?? ''));
    $brands = trim((string)($payload['company_brands'] ?? ''));
    $clients = trim((string)($payload['main_clients'] ?? ''));
    $personnel = trim((string)($payload['personnel_count'] ?? ''));

    // Проверяем, является ли цель сделки только cash_out
    $isOnlyCashOut = isOnlyCashOut($payload['deal_goal'] ?? '');

    $sentences = [];
    $descriptor = $industry !== '' ? $industry : 'устойчивый бизнес';
    if ($isOnlyCashOut) {
        $sentences[] = "{$displayName} — {$descriptor}, готовый к продаже.";
    } else {
        $sentences[] = "{$displayName} — {$descriptor}, готовый к привлечению инвестора для следующего этапа роста.";
    }

    if ($regions !== '') {
        $sentences[] = "Присутствие в регионах {$regions} обеспечивает диверсификацию выручки и доступ к новым каналам.";
    }

    // Не упоминаем бренды для скрытых активов
    if ($brands !== '' && !$isNameHidden) {
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

/**
 * Генерирует краткое описание для hero блока тизера из overview summary
 * 
 * Извлекает первое предложение или первые 2-3 предложения из overview summary
 * и форматирует их для отображения в верхнем блоке тизера
 * 
 * @param array $teaserData Данные тизера с overview summary
 * @param array $payload Данные анкеты
 * @return string Краткое описание для hero блока
 */
function buildHeroDescription(array $teaserData, array $payload): string
{
    $overviewSummary = $teaserData['overview']['summary'] ?? '';
    
    if (empty($overviewSummary)) {
        // Если summary нет, создаем краткое описание из данных анкеты
        $assetName = trim((string)($payload['asset_name'] ?? 'Компания'));
        $assetDisclosure = $payload['asset_disclosure'] ?? '';
        $isNameHidden = ($assetDisclosure === 'no' || $assetDisclosure === 'нет' || $assetName === 'Актив');
        
        // Для скрытых активов используем "Компания" или "Фирма", но не "Компания Актив"
        $displayName = $isNameHidden ? 'Компания' : $assetName;
        
        $industry = trim((string)($payload['products_services'] ?? ''));
        $descriptor = $industry !== '' ? $industry : 'устойчивый бизнес';
        return "{$displayName} — {$descriptor}, готовый к привлечению инвестора для следующего этапа роста.";
    }
    
    // Убираем HTML теги и форматирование
    $plain = strip_tags($overviewSummary);
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = preg_replace('/\s+/', ' ', $plain);
    $plain = trim($plain);
    
    // Извлекаем первое предложение или первые 2-3 предложения (до 220 символов)
    $sentences = preg_split('/([.!?]+)/u', $plain, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = '';
    $sentenceCount = 0;
    $maxLength = 220;
    
    for ($i = 0; $i < count($sentences) - 1; $i += 2) {
        $sentence = trim($sentences[$i] . ($sentences[$i + 1] ?? ''));
        if (empty($sentence)) {
            continue;
        }
        
        // Добавляем предложение, если не превышаем лимит
        $candidate = $result === '' ? $sentence : $result . ' ' . $sentence;
        if (mb_strlen($candidate) <= $maxLength) {
            $result = $candidate;
            $sentenceCount++;
            // Останавливаемся после 2-3 предложений
            if ($sentenceCount >= 2) {
                break;
            }
        } else {
            // Если добавление превысит лимит, останавливаемся
            break;
        }
    }
    
    // Если не получилось извлечь предложения, берем первые 220 символов
    if (empty($result)) {
        $result = mb_substr($plain, 0, $maxLength);
        // Обрезаем по последнему пробелу, чтобы не обрывать слово
        $lastSpace = mb_strrpos($result, ' ');
        if ($lastSpace !== false && $lastSpace > 150) {
            $result = mb_substr($result, 0, $lastSpace);
        }
        $result .= '…';
    }
    
    // Убираем фразу "M&A платформа"
    $result = preg_replace('/\bM&[Aa]mp;?[Aa]тр?[АA]?\s+платформа\b/ui', '', $result);
    $result = trim(preg_replace('/\s+/', ' ', $result));
    
    return $result;
}

/**
 * Сокращает текст сегмента до максимум 7 слов с сохранением смысла
 * Использует AI для сокращения, если доступен, иначе использует умную обрезку
 * 
 * @param string $segment Исходный текст сегмента
 * @param string|null $apiKey API ключ для вызова AI (опционально)
 * @return string Сокращенный текст до 7 слов
 */
function limitSegmentToSevenWords(string $segment, ?string $apiKey = null): string
{
    $segment = trim($segment);
    if ($segment === '') {
        return '';
    }
    
    // Подсчитываем количество слов
    $words = preg_split('/\s+/u', $segment, -1, PREG_SPLIT_NO_EMPTY);
    $wordCount = count($words);
    
    // Если уже 7 слов или меньше, возвращаем как есть
    if ($wordCount <= 7) {
        return $segment;
    }
    
    // Пытаемся использовать AI для сокращения, если доступен API ключ
    if ($apiKey !== null && function_exists('callAICompletions')) {
        try {
            $prompt = "Ты маркетолог. Сократи описание области деятельности компании до максимум 7 слов, сохраняя ключевую информацию о том, чем занимается компания.\n\nВажно:\n- Выдели основную область деятельности и специализацию компании\n- Укажи ключевые продукты, услуги или нишу рынка\n- Сохрани важные характеристики (сегмент, тип клиентов, если они важны для понимания деятельности)\n- Ответ должен быть только сокращенным текстом, без дополнительных объяснений\n- Используй деловой стиль\n\nТекст для сокращения: {$segment}";
            
            $aiResponse = trim(callAICompletions($prompt, $apiKey));
            
            // Очищаем ответ от возможных артефактов AI
            // Убираем префиксы на английском в начале строки
            $aiResponse = preg_replace('/^[^А-Яа-я]*/u', '', $aiResponse);
            // Убираем markdown код блоки, если есть
            $aiResponse = preg_replace('/```[a-z]*\s*/i', '', $aiResponse);
            $aiResponse = preg_replace('/```$/', '', $aiResponse);
            // Оставляем русские буквы, цифры, дефисы, пробелы и основные знаки препинания
            $aiResponse = preg_replace('/[^А-Яа-яЁё\s\-0-9,\.\(\)]/u', '', $aiResponse);
            $aiResponse = trim($aiResponse);
            
            // Проверяем, что ответ не пустой и содержит разумное количество слов
            $aiWords = preg_split('/\s+/u', $aiResponse, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($aiResponse) && count($aiWords) <= 7 && count($aiWords) > 0) {
                return $aiResponse;
            }
        } catch (Throwable $e) {
            // Если AI недоступен, используем fallback
            error_log('AI segment shortening failed: ' . $e->getMessage());
        }
    }
    
    // Fallback: умная обрезка до 7 слов
    // Пытаемся сохранить целостность фразы, обрезая по границам слов
    $limitedWords = array_slice($words, 0, 7);
    return implode(' ', $limitedWords);
}

/**
 * Рендерит hero блок тизера с названием компании, описанием, чипами и статистикой
 * 
 * @param string $assetName Название актива
 * @param array $teaserData Данные тизера
 * @param array $payload Данные анкеты
 * @param array|null $dcfData Данные DCF модели
 * @param string|null $apiKey API ключ для AI (опционально, для сокращения сегмента)
 * @return string HTML код hero блока
 */
function renderHeroBlock(string $assetName, array $teaserData, array $payload, ?array $dcfData = null, ?TeaserLogger $logger = null, ?string $apiKey = null): string
{
    // Получаем описание из hero_description или из overview
    $heroDescription = '';
    if (!empty($teaserData['overview']['summary'])) {
        $heroDescription = buildHeroDescription($teaserData, $payload);
    } else {
        $heroDescription = trim((string)($payload['company_description'] ?? ''));
        if (mb_strlen($heroDescription) > 220) {
            $heroDescription = mb_substr($heroDescription, 0, 220) . '…';
        }
    }
    
    // Формируем чипы (chips)
    $heroChips = [];
    
    // 1. Сегмент рынка
    $industry = trim((string)($payload['products_services'] ?? ''));
    if ($industry !== '') {
        // Ограничиваем сегмент до 7 слов с сохранением смысла
        $industry = limitSegmentToSevenWords($industry, $apiKey);
        if ($industry !== '') {
            $heroChips[] = [
                'label' => 'СЕГМЕНТ',
                'value' => $industry,
                'icon' => 'segment'
            ];
        }
    }
    
    // 2. География присутствия
    $region = trim((string)($payload['presence_regions'] ?? ''));
    if ($region !== '') {
        $heroChips[] = [
            'label' => 'РЫНКИ',
            'value' => $region,
            'icon' => 'location'
        ];
    }
    
    // 3. Персонал
    $personnelCount = trim((string)($payload['personnel_count'] ?? ''));
    if ($personnelCount !== '' && $personnelCount !== '0') {
        $heroChips[] = [
            'label' => 'ПЕРСОНАЛ',
            'value' => $personnelCount . ' чел.',
            'icon' => 'people'
        ];
    }
    
    // 4. Онлайн продажи
    $onlineShare = trim((string)($payload['online_sales_share'] ?? ''));
    if ($onlineShare !== '' && $onlineShare !== '0') {
        $onlineShare = rtrim($onlineShare, '%');
        $heroChips[] = [
            'label' => 'ОНЛАЙН',
            'value' => $onlineShare . '%',
            'icon' => 'online'
        ];
    }
    
    // Ограничиваем до 4 элементов
    $heroChips = array_slice($heroChips, 0, 4);
    
    // Формируем статистику
    $heroStats = [];
    
    if (is_array($dcfData)) {
        // Получаем выручку и прибыль P1 из DCF данных (P1 теперь соответствует 2026П)
        $p1Revenue = null;
        $p1Profit = null;
        if (!empty($dcfData['rows']) && is_array($dcfData['rows'])) {
            foreach ($dcfData['rows'] as $row) {
                if (!isset($row['label']) || !isset($row['values']) || !is_array($row['values'])) {
                    continue;
                }
                if ($row['label'] === 'Выручка' && array_key_exists('P1', $row['values'])) {
                    $val = $row['values']['P1'];
                    if ($val !== null && $val !== '') {
                        $p1Revenue = (float)$val;
                    }
                }
                if ($row['label'] === 'Прибыль от продаж' && array_key_exists('P1', $row['values'])) {
                    $val = $row['values']['P1'];
                    if ($val !== null && $val !== '') {
                        $p1Profit = (float)$val;
                    }
                }
            }
        }
        
        // Выручка 2026П (из P1)
        if ($p1Revenue !== null) {
            $heroStats[] = [
                'label' => 'ВЫРУЧКА 2026П',
                'value' => number_format($p1Revenue, 0, '.', ' ') . ' млн Р',
                'caption' => 'прогноз на 2026',
            ];
        }
        
        // Маржинальность (из P1)
        if ($p1Profit !== null && $p1Revenue !== null && $p1Revenue != 0) {
            $marginPercent = ($p1Profit / $p1Revenue) * 100;
            $heroStats[] = [
                'label' => 'МАРЖИНАЛЬНОСТЬ',
                'value' => number_format($marginPercent, 1, '.', ' ') . '%',
                'caption' => '2026П (Прибыль/Выручка)',
            ];
        }
        
        // Темп роста (сравниваем 2025 факт с P1 = 2026П)
        $fact2025Revenue = null;
        $p1RevenueForGrowth = null;
        if (!empty($dcfData['rows']) && is_array($dcfData['rows'])) {
            foreach ($dcfData['rows'] as $row) {
                if (isset($row['label']) && $row['label'] === 'Выручка') {
                    // Ищем факт 2025 года
                    if (isset($row['values']['2025']) && $row['values']['2025'] !== null) {
                        $fact2025Revenue = (float)$row['values']['2025'];
                    }
                    // Или ищем в исторических данных
                    if ($fact2025Revenue === null && isset($row['values']['2025_fact']) && $row['values']['2025_fact'] !== null) {
                        $fact2025Revenue = (float)$row['values']['2025_fact'];
                    }
                    // P1 теперь соответствует 2026П
                    if (isset($row['values']['P1']) && $row['values']['P1'] !== null) {
                        $p1RevenueForGrowth = (float)$row['values']['P1'];
                    }
                    break;
                }
            }
        }
        if ($fact2025Revenue !== null && $p1RevenueForGrowth !== null && $fact2025Revenue != 0) {
            $currentYearGrowth = (($p1RevenueForGrowth - $fact2025Revenue) / $fact2025Revenue) * 100;
            $heroStats[] = [
                'label' => 'ТЕМП РОСТА',
                'value' => number_format($currentYearGrowth, 1, '.', ' ') . '%',
                'caption' => '2026П к 2025',
            ];
        }
        
        // Цена - приоритет у цены предложения продавца
        $finalPrice = null;
        
        // Проверяем все возможные источники цены предложения продавца
        // 1. Прямо из payload (верхний уровень)
        if (isset($payload['final_price']) && $payload['final_price'] > 0) {
            $finalPrice = (float)$payload['final_price'];
        } elseif (isset($payload['final_selling_price']) && $payload['final_selling_price'] > 0) {
            $finalPrice = (float)$payload['final_selling_price'];
        }
        
        // 2. Из _original_data_json (сохраненный data_json из формы)
        if ($finalPrice === null && !empty($payload['_original_data_json'])) {
            $formDataJson = is_string($payload['_original_data_json']) ? json_decode($payload['_original_data_json'], true) : $payload['_original_data_json'];
            if (is_array($formDataJson)) {
                if (isset($formDataJson['final_price']) && $formDataJson['final_price'] > 0) {
                    $finalPrice = (float)$formDataJson['final_price'];
                } elseif (isset($formDataJson['final_selling_price']) && $formDataJson['final_selling_price'] > 0) {
                    $finalPrice = (float)$formDataJson['final_selling_price'];
                }
            }
        }
        
        // 3. Из data_json (для обратной совместимости)
        if ($finalPrice === null && !empty($payload['data_json'])) {
            $formDataJson = is_string($payload['data_json']) ? json_decode($payload['data_json'], true) : $payload['data_json'];
            if (is_array($formDataJson)) {
                if (isset($formDataJson['final_price']) && $formDataJson['final_price'] > 0) {
                    $finalPrice = (float)$formDataJson['final_price'];
                } elseif (isset($formDataJson['final_selling_price']) && $formDataJson['final_selling_price'] > 0) {
                    $finalPrice = (float)$formDataJson['final_selling_price'];
                }
            }
        }
        
        // Показываем цену предложения продавца, если она есть
        if ($finalPrice !== null && $finalPrice > 0) {
            if ($logger) {
                $logger->log("renderHeroBlock: Found final_price=" . $finalPrice . ", adding to hero stats", 'INFO');
            }
            error_log('renderHeroBlock: Found final_price=' . $finalPrice . ', adding to hero stats');
            $heroStats[] = [
                'label' => 'ЦЕНА',
                'value' => number_format($finalPrice, 0, '.', ' ') . ' млн Р',
                'caption' => 'Цена предложения Продавца',
            ];
        } else {
            $warningMsg = 'renderHeroBlock: WARNING - final_price not found or invalid. payload top-level keys: ' . implode(', ', array_slice(array_keys($payload), 0, 20));
            if ($logger) {
                $logger->log($warningMsg, 'WARNING');
            }
            error_log($warningMsg);
            
            if (isset($payload['_original_data_json'])) {
                $testJson = is_string($payload['_original_data_json']) ? json_decode($payload['_original_data_json'], true) : $payload['_original_data_json'];
                if (is_array($testJson)) {
                    $jsonKeysMsg = 'renderHeroBlock: _original_data_json keys: ' . implode(', ', array_slice(array_keys($testJson), 0, 20));
                    $finalPriceMsg = 'renderHeroBlock: _original_data_json final_price=' . ($testJson['final_price'] ?? 'NOT SET');
                    if ($logger) {
                        $logger->log($jsonKeysMsg, 'WARNING');
                        $logger->log($finalPriceMsg, 'WARNING');
                    }
                    error_log($jsonKeysMsg);
                    error_log($finalPriceMsg);
                } else {
                    $typeMsg = 'renderHeroBlock: _original_data_json is not an array: ' . gettype($testJson);
                    if ($logger) {
                        $logger->log($typeMsg, 'WARNING');
                    }
                    error_log($typeMsg);
                }
            } else {
                $noJsonMsg = 'renderHeroBlock: _original_data_json not present in payload';
                if ($logger) {
                    $logger->log($noJsonMsg, 'WARNING');
                }
                error_log($noJsonMsg);
            }
        }
        // Enterprise Value НЕ показываем, если есть цена предложения продавца
        // (убрали elseif, чтобы не показывать EV, если нет цены предложения продавца)
    }
    
    // Ограничиваем до 4 элементов
    $heroStats = array_slice(array_filter($heroStats, function($item) {
        return isset($item['value']) && $item['value'] !== '' && $item['value'] !== null;
    }), 0, 4);
    
    // Дата обновления
    $updateDate = date('d.m.Y H:i');
    
    // Рендерим HTML
    $html = '<div class="teaser-hero">';
    $html .= '<div class="teaser-hero__content">';
    // Не показываем заголовок, если имя скрыто (assetName пустой или "Актив")
    if (!empty($assetName) && $assetName !== 'Актив') {
        $html .= '<h3>' . escapeHtml($assetName) . '</h3>';
    }
    $html .= '<p class="teaser-hero__description">' . escapeHtml($heroDescription) . '</p>';
    
    if (!empty($heroChips)) {
        $html .= '<div class="teaser-hero__tags">';
        foreach ($heroChips as $chip) {
            $html .= '<span class="teaser-chip" data-icon="' . escapeHtml($chip['icon'] ?? '') . '">';
            $html .= '<span class="teaser-chip__icon">' . getTeaserChipIconSvg($chip['icon'] ?? 'default') . '</span>';
            $html .= '<span class="teaser-chip__content">';
            $html .= '<span class="teaser-chip__label">' . escapeHtml($chip['label']) . '</span>';
            $html .= '<strong class="teaser-chip__value">' . escapeHtml($chip['value']) . '</strong>';
            $html .= '</span></span>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    if (!empty($heroStats)) {
        $html .= '<div class="teaser-hero__stats">';
        foreach ($heroStats as $stat) {
            $html .= '<div class="teaser-stat">';
            $html .= '<span>' . escapeHtml($stat['label']) . '</span>';
            $html .= '<strong>' . escapeHtml($stat['value']) . '</strong>';
            if (!empty($stat['caption'])) {
                $html .= '<small>' . escapeHtml($stat['caption']) . '</small>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    
    $html .= '<div class="teaser-hero__status">';
    $html .= '<div class="teaser-status">Тизер обновлён: ' . escapeHtml($updateDate) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

/**
 * Возвращает SVG иконку для чипа hero блока тизера
 * 
 * Функция возвращает SVG код иконки в зависимости от типа чипа.
 * Поддерживаемые типы: 'segment', 'location', 'people', 'online', 'brand', 'share', 'goal'.
 * Если тип не найден, возвращается иконка по умолчанию.
 * 
 * @param string $iconType Тип иконки ('segment', 'location', 'people', 'online', и т.д.)
 * @return string SVG код иконки
 */
function getTeaserChipIconSvg(string $iconType): string
{
    $icons = [
        'segment' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'location' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 13C13.6569 13 15 11.6569 15 10C15 8.34315 13.6569 7 12 7C10.3431 7 9 8.34315 9 10C9 11.6569 10.3431 13 12 13Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'people' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 21V19C17 17.9391 16.5786 16.9217 15.8284 16.1716C15.0783 15.4214 14.0609 15 13 15H5C3.93913 15 2.92172 15.4214 2.17157 16.1716C1.42143 16.9217 1 17.9391 1 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 7C9 9.20914 7.20914 11 5 11C2.79086 11 1 9.20914 1 7C1 4.79086 2.79086 3 5 3C7.20914 3 9 4.79086 9 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 21V19C22.9993 18.1137 22.7044 17.2528 22.1614 16.5523C21.6184 15.8519 20.8581 15.3516 20 15.13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3.13C16.8604 3.35031 17.623 3.85071 18.1676 4.55232C18.7122 5.25392 19.0078 6.11683 19.0078 7.005C19.0078 7.89318 18.7122 8.75608 18.1676 9.45769C17.623 10.1593 16.8604 10.6597 16 10.88" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'online' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8V12L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'default' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    ];
    
    return $icons[$iconType] ?? $icons['default'];
}

/**
 * Генерирует краткое описание для hero блока через ИИ на основе данных анкеты
 * 
 * Эта функция вызывается сразу после расчета DCF, до генерации полного тизера
 * 
 * @param array $form Данные формы из БД
 * @param string $apiKey API ключ для Together.ai
 * @return string|null Сгенерированное описание или null при ошибке
 */
function generateHeroDescription(array $form, string $apiKey): ?string
{
    try {
        $payload = buildTeaserPayload($form);
        
        // Используем маскированные данные для генерации описания
        $maskedPayload = buildMaskedTeaserPayload($payload);
        
        // Создаем промпт для генерации краткого описания
        $assetName = $maskedPayload['asset_name'] ?? 'Компания';
        // Используем маскированные данные для фактов
        $facts = [
            'Название' => $assetName,
            'Отрасль' => $maskedPayload['products_services'] ?? '',
            'Регионы' => $maskedPayload['presence_regions'] ?? '',
            'Бренды' => $maskedPayload['company_brands'] ?? '',
            'Клиенты' => $maskedPayload['main_clients'] ?? '',
            'Персонал' => $maskedPayload['personnel_count'] ?? '',
            'Цель сделки' => $maskedPayload['deal_goal'] ?? '',
            'Описание' => $maskedPayload['company_description'] ?? '',
        ];
        
        $facts = array_filter($facts, fn($value) => trim((string)$value) !== '');
        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $prompt = <<<PROMPT
Ты инвестиционный банкир. Напиши краткое описание компании "{$assetName}" для инвестора строго на русском языке.

Требования:
- Максимум 2-3 предложения (до 220 символов)
- Деловой и живой тон без канцелярита
- Укажи: кто компания, что делает, ключевые преимущества
- Используй только приведённые факты, не придумывай цифры

Факты:
{$factsJson}

Верни только текст описания, без дополнительных комментариев.
PROMPT;
        
        $rawResponse = callAICompletions($prompt, $apiKey);
        
        // Очищаем ответ от артефактов ИИ
        $description = constrainToRussianNarrative(sanitizeAiArtifacts($rawResponse));
        $description = preg_replace('/\bM&[Aa]mp;?[Aa]тр?[АA]?\s+платформа\b/ui', '', $description);
        $description = trim(preg_replace('/\s+/', ' ', $description));
        
        // Ограничиваем длину до 220 символов
        if (mb_strlen($description) > 220) {
            $description = mb_substr($description, 0, 220);
            $lastSpace = mb_strrpos($description, ' ');
            if ($lastSpace !== false && $lastSpace > 150) {
                $description = mb_substr($description, 0, $lastSpace);
            }
            $description .= '…';
        }
        
        // Сохраняем в snapshot
        if (!empty($description)) {
            saveHeroDescriptionToSnapshot($form, $description);
        }
        
        return $description ?: null;
    } catch (Throwable $e) {
        error_log('Hero description generation failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Сохраняет hero_description в snapshot формы
 * 
 * @param array $form Данные формы из БД
 * @param string $description Сгенерированное описание
 */
function saveHeroDescriptionToSnapshot(array $form, string $description): void
{
    try {
        $pdo = getDBConnection();
        
        // Получаем текущий data_json
        $currentJson = $form['data_json'] ?? '{}';
        $data = json_decode($currentJson, true);
        if (!is_array($data)) {
            $data = [];
        }
        
        // Обновляем или создаем teaser_snapshot
        if (!isset($data['teaser_snapshot']) || !is_array($data['teaser_snapshot'])) {
            $data['teaser_snapshot'] = [];
        }
        
        $data['teaser_snapshot']['hero_description'] = $description;
        $data['teaser_snapshot']['hero_description_generated_at'] = date('c');
        
        // Сохраняем обратно в БД
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $stmt = $pdo->prepare("UPDATE seller_forms SET data_json = ? WHERE id = ?");
            $stmt->execute([$json, $form['id']]);
        }
    } catch (PDOException $e) {
        error_log('Failed to save hero description: ' . $e->getMessage());
    }
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

/**
 * Проверяет, содержит ли deal_goal только cash_out (выход продавца)
 * @param mixed $dealGoal Может быть массивом, JSON-строкой или обычной строкой
 * @return bool true если только cash_out, false иначе
 */
function isOnlyCashOut($dealGoal): bool
{
    if (empty($dealGoal)) {
        return false;
    }
    
    // Если это массив
    if (is_array($dealGoal)) {
        $normalized = array_map('trim', $dealGoal);
        return count($normalized) === 1 && in_array('cash_out', $normalized, true);
    }
    
    // Если это строка, пытаемся декодировать как JSON
    if (is_string($dealGoal)) {
        $trimmed = trim($dealGoal);
        
        // Если это JSON-строка массива (начинается с [)
        if (preg_match('/^\[.*\]$/', $trimmed)) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $normalized = array_map('trim', $decoded);
                return count($normalized) === 1 && in_array('cash_out', $normalized, true);
            }
        }
        
        // Пытаемся декодировать как JSON (может быть без скобок)
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $normalized = array_map('trim', $decoded);
            return count($normalized) === 1 && in_array('cash_out', $normalized, true);
        }
        
        // Если это обычная строка, проверяем на точное совпадение
        return $trimmed === 'cash_out' || $trimmed === '["cash_out"]';
    }
    
    return false;
}

function buildInvestorProspectSentence(array $payload): ?string
{
    $segments = [];
    // Не добавляем информацию о направлении инвестиций, если цель сделки - только cash_out (выход продавца)
    if (!empty($payload['deal_goal']) && !isOnlyCashOut($payload['deal_goal'])) {
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
    // Определяем единицы измерения
    $unitStr = $financial['unit'] ?? '';
    $unit = detectFinancialUnit($unitStr);
    
    // Используем факт 2024 или 2025 года как базовый год
    $factRaw = $financial['2024_fact'] ?? $financial['2025_fact'] ?? null;
    $fact = $factRaw !== null ? convertFinancialToMillions($factRaw, $unit) : null;
    
    // Используем прогноз на 2026П (P1) из 2026_budget или из DCF данных
    $budgetRaw = $financial['2026_budget'] ?? null;
    $budget = $budgetRaw !== null ? convertFinancialToMillions($budgetRaw, $unit) : null;
    
    if ($fact === null || $budget === null || $budget <= 0 || $fact <= 0 || $budget <= $fact) {
        return null;
    }
    $growthPercent = (($budget - $fact) / $fact) * 100;
    $factText = number_format($fact, 0, ',', ' ');
    $budgetText = number_format($budget, 0, ',', ' ');
    $growthText = number_format($growthPercent, 1, ',', ' ');
    // Обновлено: прогноз теперь на 2026П (P1)
    return "Прогноз 2026П показывает рост выручки с {$factText} до {$budgetText} млн ₽ (+{$growthText}%).";
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
    
    // Очищаем snapshot от JavaScript кода и странных символов
    $clean = cleanWebsiteSnapshot($snapshot);
    if ($clean === '') {
        return null;
    }
    
    $sentences = preg_split('/(?<=[\.\!\?])\s+/u', $clean);
    $excerpt = '';
    foreach ($sentences as $sentence) {
        $sentence = trim($sentence);
        if ($sentence === '') {
            continue;
        }
        // Пропускаем предложения, которые выглядят как код
        if (preg_match('/[{}();=<>\[\]]{3,}/', $sentence) || 
            (preg_match('/^[a-zA-Z0-9\s.,;:(){}[\]<>=\-+*\/&|!@#$%^]+$/', $sentence) && 
             !preg_match('/[а-яА-ЯёЁ]/u', $sentence) && 
             mb_strlen($sentence) > 20)) {
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
    $assetDisclosure = $payload['asset_disclosure'] ?? '';
    $isNameHidden = ($assetDisclosure === 'no' || $assetDisclosure === 'нет' || $assetName === 'Актив');
    
    // Для скрытых активов используем "Компания" или "Фирма", но не "Компания Актив"
    $displayName = $isNameHidden ? 'Компания' : $assetName;
    
    $regions = trim((string)($payload['presence_regions'] ?? ''));
    $clients = trim((string)($payload['main_clients'] ?? ''));
    $dealGoal = trim((string)($payload['deal_goal'] ?? ''));
    $growth = buildRevenueGrowthMessage($payload);
    $advantages = buildAdvantageSentences($payload);
    $website = buildWebsiteInsightSentence($payload);

    $sentences = [];
    $sentences[] = $displayName !== '' ? "{$displayName} готова к диалогу с инвестором на платформе SmartBizSell." : 'Команда актива готова к диалогу с инвестором на платформе SmartBizSell.';
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
    // Если требуется переменное количество предложений (2-3), используем специальную логику
    $useVariableSentences = ($sentencesPerParagraph === 3 && $requiredParagraphs === 3);
    
    if ($useVariableSentences) {
        // Для 3 абзацев по 2-3 предложения: используем паттерн 3, 2, 3 или 2, 3, 2
        $totalNeeded = 8; // 3+2+3 или 2+3+2
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

        // Создаем 3 абзаца: первый и третий по 3 предложения, второй по 2
        $paragraphs = [];
        $paragraphs[] = implode(' ', array_slice($normalized, 0, 3));
        $paragraphs[] = implode(' ', array_slice($normalized, 3, 2));
        $paragraphs[] = implode(' ', array_slice($normalized, 5, 3));

        return implode("\n\n", $paragraphs);
    }
    
    // Старая логика для фиксированного количества предложений
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
 * Форматирует текст обзора возможности с правильным разделением на абзацы
 * 
 * Преобразует двойные переносы строк (\n\n) в закрывающие/открывающие теги <p>
 * 
 * @param string $text Текст для форматирования
 * @return string Отформатированный HTML
 */
function formatOverviewTextWithParagraphs(string $text): string
{
    if (empty($text)) {
        return '';
    }
    
    // Экранируем HTML
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    
    // Разбиваем текст на абзацы по двойным переносам строк
    $paragraphs = preg_split('/\n\n+/', $text);
    
    // Убираем пустые абзацы и оборачиваем каждый в <p> тег
    $paragraphs = array_filter(array_map('trim', $paragraphs), function($p) {
        return $p !== '';
    });
    
    // Если абзацев нет, возвращаем пустую строку
    if (empty($paragraphs)) {
        return '';
    }
    
    // Оборачиваем каждый абзац в <p> тег и заменяем одиночные переносы на <br>
    $formatted = array_map(function($paragraph) {
        return '<p>' . nl2br($paragraph) . '</p>';
    }, $paragraphs);
    
    return implode('', $formatted);
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

/**
 * Сохраняет snapshot тизера в БД в поле data_json формы.
 * 
 * Snapshot содержит HTML тизера, hero_description, дату генерации и другие метаданные.
 * Данные сохраняются в JSON формате в поле data_json таблицы seller_forms.
 * 
 * @param array $form Данные формы из БД
 * @param array $payload Данные анкеты (будут объединены с snapshot)
 * @param array $snapshot Данные snapshot для сохранения:
 *                       - 'html': HTML код тизера
 *                       - 'hero_description': описание для hero блока
 *                       - 'generated_at': дата генерации
 *                       - 'model': модель AI, использованная для генерации
 * @return array Возвращает переданный snapshot
 */
function persistTeaserSnapshot(array $form, array $payload, array $snapshot, ?TeaserLogger $logger = null): array
{
    try {
        $pdo = getDBConnection();
        
        // Сначала читаем текущий data_json из БД
        $stmt = $pdo->prepare("SELECT data_json FROM seller_forms WHERE id = ?");
        $stmt->execute([$form['id']]);
        $row = $stmt->fetch();
        
        // Декодируем текущий data_json
        $currentDataJson = [];
        if (!empty($row['data_json'])) {
            $decoded = json_decode($row['data_json'], true);
            if (is_array($decoded)) {
                $currentDataJson = $decoded;
            }
        }
        
        // Логируем сохранение final_price для диагностики
        if (isset($currentDataJson['final_price'])) {
            if ($logger) {
                $logger->log("persistTeaserSnapshot: Preserving final_price=" . $currentDataJson['final_price'] . " for form_id=" . $form['id'], 'INFO');
            }
            error_log('persistTeaserSnapshot: Preserving final_price=' . $currentDataJson['final_price'] . ' for form_id=' . $form['id']);
        } else {
            if ($logger) {
                $logger->log("persistTeaserSnapshot: WARNING - final_price not found in currentDataJson for form_id=" . $form['id'], 'WARNING');
            }
            error_log('persistTeaserSnapshot: WARNING - final_price not found in currentDataJson for form_id=' . $form['id']);
        }
        
        // Обновляем только teaser_snapshot, сохраняя все остальные поля
        // Это важно, чтобы не потерять final_price, multiplier_valuation и другие поля
        $currentDataJson['teaser_snapshot'] = $snapshot;
        
        // Сохраняем обновленный data_json обратно
        $json = json_encode($currentDataJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
            error_log('Failed to encode data_json in persistTeaserSnapshot');
        return $snapshot;
    }

        $stmt = $pdo->prepare("UPDATE seller_forms SET data_json = ? WHERE id = ?");
        $stmt->execute([$json, $form['id']]);
    } catch (PDOException $e) {
        error_log('Failed to persist teaser snapshot: ' . $e->getMessage());
    }

    return $snapshot;
}

/**
 * Создает или обновляет запись в таблице published_teasers для модерации
 * 
 * Эта функция автоматически вызывается после генерации тизера и создает
 * запись в таблице published_teasers со статусом 'pending' для последующей
 * модерации. Если запись уже существует, она обновляется, сбрасывая статус
 * на 'pending', что позволяет повторно отправить тизер на модерацию.
 * 
 * Логика работы:
 * - Если запись существует: обновляет moderated_html и сбрасывает статус на 'pending'
 * - Если записи нет: создает новую запись со статусом 'pending'
 * - При обновлении очищает moderation_notes, moderated_at и published_at
 * 
 * @param array $form Данные анкеты из БД (должен содержать поле 'id')
 * @param string $html HTML тизера для модерации (полный HTML с hero блоком)
 * @return bool true при успехе, false при ошибке
 */
function createPublishedTeaserRecord(array $form, string $html): bool
{
    try {
        // Убеждаемся, что таблица существует
        ensurePublishedTeasersTable();
        $pdo = getDBConnection();
        
        // Проверяем, существует ли уже запись для этой анкеты
        $stmt = $pdo->prepare("SELECT id FROM published_teasers WHERE seller_form_id = ?");
        $stmt->execute([$form['id']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Обновляем существующую запись, сбрасывая статус на pending
            // Это позволяет обновить тизер даже если он был опубликован
            // При повторной отправке на модерацию старый тизер будет заменен новым
            $stmt = $pdo->prepare("
                UPDATE published_teasers 
                SET 
                    moderated_html = ?,
                    moderation_status = 'pending',
                    moderation_notes = NULL,
                    moderated_at = NULL,
                    published_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$html, $existing['id']]);
        } else {
            // Создаем новую запись со статусом 'pending' для модерации
            // moderated_html будет заполнен позже при отправке на модерацию
            $stmt = $pdo->prepare("
                INSERT INTO published_teasers 
                (seller_form_id, moderated_html, moderation_status, created_at, updated_at)
                VALUES (?, ?, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$form['id'], $html]);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating published_teaser record: " . $e->getMessage());
        return false;
    }
}

/**
 * Сохраняет snapshot списка инвесторов в БД в поле data_json формы.
 * 
 * Snapshot содержит HTML списка инвесторов и дату генерации.
 * Данные сохраняются в JSON формате в поле data_json таблицы seller_forms.
 * 
 * @param array $form Данные формы из БД
 * @param array $payload Данные анкеты (будут объединены с snapshot)
 * @param array $snapshot Данные snapshot для сохранения:
 *                       - 'html': HTML код списка инвесторов
 *                       - 'generated_at': дата генерации
 * @return array Возвращает переданный snapshot
 */
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
