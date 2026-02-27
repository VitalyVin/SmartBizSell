<?php
/**
 * Базовый класс для запуска тестов
 * 
 * Предоставляет методы для выполнения тестов, проверки результатов
 * и формирования отчетов.
 */
class TestRunner
{
    private $results = [];
    private $currentTest = null;
    private $startTime = null;
    private $pdo = null;
    private $transactionStarted = false;
    private $useTransactions = true;
    
    /**
     * Конструктор
     */
    public function __construct()
    {
        // Проверяем, включены ли транзакции (по умолчанию включены)
        $this->useTransactions = defined('TEST_USE_TRANSACTIONS') ? TEST_USE_TRANSACTIONS : true;
    }
    
    /**
     * Запускает отдельный тест
     * 
     * @param string $name Название теста
     * @param callable $callback Функция для выполнения теста
     * @return bool true если тест прошел, false если провалился
     */
    public function runTest(string $name, callable $callback): bool
    {
        $this->currentTest = [
            'name' => $name,
            'status' => 'running',
            'start_time' => microtime(true),
            'assertions' => [],
            'errors' => [],
            'warnings' => []
        ];
        
        $this->startTime = microtime(true);
        
        // Начинаем транзакцию перед тестом (если включены)
        if ($this->useTransactions) {
            $this->beginTransaction();
        }
        
        try {
            $callback($this);
            $this->currentTest['status'] = 'passed';
            $this->currentTest['end_time'] = microtime(true);
            $this->currentTest['duration'] = round($this->currentTest['end_time'] - $this->currentTest['start_time'], 3);
        } catch (Throwable $e) {
            $this->currentTest['status'] = 'failed';
            $this->currentTest['end_time'] = microtime(true);
            $this->currentTest['duration'] = round($this->currentTest['end_time'] - $this->currentTest['start_time'], 3);
            $this->currentTest['errors'][] = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ];
        } finally {
            // Всегда откатываем транзакцию после теста (если была начата)
            if ($this->useTransactions && $this->transactionStarted) {
                $this->rollbackTransaction();
            }
        }
        
        $this->results[] = $this->currentTest;
        return $this->currentTest['status'] === 'passed';
    }
    
    /**
     * Проверяет, что условие истинно
     * 
     * @param bool $condition Условие для проверки
     * @param string $message Сообщение при провале
     * @throws Exception Если условие ложно
     */
    public function assertTrue(bool $condition, string $message = ''): void
    {
        $this->addAssertion('assertTrue', $condition, true, $message);
        if (!$condition) {
            throw new Exception($message ?: 'Assertion failed: expected true, got false');
        }
    }
    
    /**
     * Проверяет, что условие ложно
     * 
     * @param bool $condition Условие для проверки
     * @param string $message Сообщение при провале
     * @throws Exception Если условие истинно
     */
    public function assertFalse(bool $condition, string $message = ''): void
    {
        $this->addAssertion('assertFalse', !$condition, false, $message);
        if ($condition) {
            throw new Exception($message ?: 'Assertion failed: expected false, got true');
        }
    }
    
    /**
     * Проверяет равенство значений
     * 
     * @param mixed $expected Ожидаемое значение
     * @param mixed $actual Фактическое значение
     * @param string $message Сообщение при провале
     * @throws Exception Если значения не равны
     */
    public function assertEquals($expected, $actual, string $message = ''): void
    {
        $passed = $expected === $actual;
        $this->addAssertion('assertEquals', $passed, $expected === $actual, $message ?: "Expected: " . $this->formatValue($expected) . ", Got: " . $this->formatValue($actual));
        if (!$passed) {
            throw new Exception($message ?: "Assertion failed: expected " . $this->formatValue($expected) . ", got " . $this->formatValue($actual));
        }
    }
    
    /**
     * Проверяет, что строка содержит подстроку
     * 
     * @param string $needle Подстрока для поиска
     * @param string $haystack Строка для поиска
     * @param string $message Сообщение при провале
     * @throws Exception Если подстрока не найдена
     */
    public function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $passed = strpos($haystack, $needle) !== false;
        $this->addAssertion('assertContains', $passed, true, $message ?: "String does not contain: " . $needle);
        if (!$passed) {
            throw new Exception($message ?: "Assertion failed: string does not contain '" . $needle . "'");
        }
    }
    
    /**
     * Проверяет, что массив содержит значение
     * 
     * @param mixed $needle Значение для поиска
     * @param array $haystack Массив для поиска
     * @param string $message Сообщение при провале
     * @throws Exception Если значение не найдено
     */
    public function assertInArray($needle, array $haystack, string $message = ''): void
    {
        $passed = in_array($needle, $haystack, true);
        $this->addAssertion('assertInArray', $passed, true, $message ?: "Value not found in array: " . $this->formatValue($needle));
        if (!$passed) {
            throw new Exception($message ?: "Assertion failed: value not found in array");
        }
    }
    
    /**
     * Проверяет, что значение не пустое
     * 
     * @param mixed $value Значение для проверки
     * @param string $message Сообщение при провале
     * @throws Exception Если значение пустое
     */
    public function assertNotEmpty($value, string $message = ''): void
    {
        $passed = !empty($value);
        $this->addAssertion('assertNotEmpty', $passed, true, $message ?: "Value is empty");
        if (!$passed) {
            throw new Exception($message ?: "Assertion failed: value is empty");
        }
    }
    
    /**
     * Проверяет, что значение является массивом
     * 
     * @param mixed $value Значение для проверки
     * @param string $message Сообщение при провале
     * @throws Exception Если значение не массив
     */
    public function assertIsArray($value, string $message = ''): void
    {
        $passed = is_array($value);
        $this->addAssertion('assertIsArray', $passed, true, $message ?: "Value is not an array");
        if (!$passed) {
            throw new Exception($message ?: "Assertion failed: value is not an array");
        }
    }
    
    /**
     * Выполняет HTTP-запрос
     * 
     * @param string $url URL для запроса
     * @param string $method HTTP метод (GET, POST, etc.)
     * @param array $data Данные для отправки
     * @param array $headers Дополнительные заголовки
     * @return array Результат запроса ['status' => int, 'body' => string, 'headers' => array]
     */
    public function makeRequest(string $url, string $method = 'GET', array $data = [], array $headers = []): array
    {
        $ch = curl_init();
        
        $defaultHeaders = [
            'Content-Type: application/x-www-form-urlencoded',
        ];
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $allHeaders,
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: " . $error);
        }
        
        return [
            'status' => $statusCode,
            'body' => $response,
            'headers' => []
        ];
    }
    
    /**
     * Выполняет запрос к локальному PHP файлу через include
     * 
     * @param string $file Путь к PHP файлу
     * @param array $params Параметры для передачи в $_GET/$_POST
     * @param string $method HTTP метод
     * @return array Результат выполнения
     */
    public function executeLocalFile(string $file, array $params = [], string $method = 'GET'): array
    {
        // Сохраняем текущие суперглобальные переменные
        $oldGet = $_GET;
        $oldPost = $_POST;
        $oldRequest = $_REQUEST;
        $oldSession = $_SESSION ?? [];
        
        // Инициализируем сессию, если она еще не инициализирована
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Устанавливаем новые значения
        if ($method === 'GET') {
            $_GET = $params;
            $_REQUEST = array_merge($_REQUEST, $params);
        } else {
            $_POST = $params;
            $_REQUEST = array_merge($_REQUEST, $params);
        }
        
        // Захватываем вывод
        ob_start();
        try {
            $result = include $file;
            $output = ob_get_clean();
        } catch (Exception $e) {
            ob_end_clean();
            // Восстанавливаем старые значения
            $_GET = $oldGet;
            $_POST = $oldPost;
            $_REQUEST = $oldRequest;
            throw $e;
        }
        
        // Восстанавливаем старые значения
        $_GET = $oldGet;
        $_POST = $oldPost;
        $_REQUEST = $oldRequest;
        $_SESSION = $oldSession;
        
        return [
            'output' => $output,
            'result' => $result,
            'status' => 200
        ];
    }
    
    /**
     * Добавляет предупреждение
     * 
     * @param string $message Текст предупреждения
     */
    public function addWarning(string $message): void
    {
        if ($this->currentTest) {
            $this->currentTest['warnings'][] = $message;
        }
    }
    
    /**
     * Получает результаты всех тестов
     * 
     * @return array Массив результатов тестов
     */
    public function getResults(): array
    {
        return $this->results;
    }
    
    /**
     * Получает статистику тестов
     * 
     * @return array Статистика ['total' => int, 'passed' => int, 'failed' => int]
     */
    public function getStats(): array
    {
        $total = count($this->results);
        $passed = count(array_filter($this->results, fn($r) => $r['status'] === 'passed'));
        $failed = $total - $passed;
        
        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed
        ];
    }
    
    /**
     * Очищает результаты тестов
     */
    public function clearResults(): void
    {
        $this->results = [];
        $this->currentTest = null;
    }
    
    /**
     * Добавляет assertion в текущий тест
     */
    private function addAssertion(string $type, bool $passed, bool $expected, string $message): void
    {
        if ($this->currentTest) {
            $this->currentTest['assertions'][] = [
                'type' => $type,
                'passed' => $passed,
                'message' => $message,
                'time' => microtime(true)
            ];
        }
    }
    
    /**
     * Начинает транзакцию для изоляции теста
     * 
     * @return bool true если транзакция начата, false в противном случае
     */
    public function beginTransaction(): bool
    {
        if ($this->transactionStarted) {
            return true; // Транзакция уже начата
        }
        
        try {
            if (!$this->pdo) {
                $this->pdo = getTestDBConnection();
            }
            
            if ($this->pdo && $this->pdo->beginTransaction()) {
                $this->transactionStarted = true;
                return true;
            }
        } catch (Exception $e) {
            $this->addWarning('Не удалось начать транзакцию: ' . $e->getMessage());
        }
        
        return false;
    }
    
    /**
     * Откатывает транзакцию (отменяет все изменения)
     * 
     * @return bool true если транзакция откачена, false в противном случае
     */
    public function rollbackTransaction(): bool
    {
        if (!$this->transactionStarted) {
            return true; // Транзакция не была начата
        }
        
        try {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $result = $this->pdo->rollBack();
                $this->transactionStarted = false;
                return $result;
            }
        } catch (Exception $e) {
            $this->addWarning('Не удалось откатить транзакцию: ' . $e->getMessage());
        }
        
        $this->transactionStarted = false;
        return false;
    }
    
    /**
     * Коммитит транзакцию (сохраняет изменения)
     * Используется только в особых случаях, когда тест должен сохранить данные
     * 
     * @return bool true если транзакция закоммичена, false в противном случае
     */
    public function commitTransaction(): bool
    {
        if (!$this->transactionStarted) {
            return false; // Транзакция не была начата
        }
        
        try {
            if ($this->pdo && $this->pdo->inTransaction()) {
                $result = $this->pdo->commit();
                $this->transactionStarted = false;
                return $result;
            }
        } catch (Exception $e) {
            $this->addWarning('Не удалось закоммитить транзакцию: ' . $e->getMessage());
        }
        
        $this->transactionStarted = false;
        return false;
    }
    
    /**
     * Получает PDO соединение для теста
     * 
     * @return PDO|null
     */
    public function getPDO()
    {
        if (!$this->pdo) {
            $this->pdo = getTestDBConnection();
        }
        return $this->pdo;
    }
    
    /**
     * Проверяет, включены ли транзакции
     * 
     * @return bool
     */
    public function isTransactionsEnabled(): bool
    {
        return $this->useTransactions;
    }
    
    /**
     * Форматирует значение для вывода
     */
    private function formatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_array($value)) {
            return 'Array(' . count($value) . ')';
        }
        if (is_object($value)) {
            return get_class($value) . ' object';
        }
        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 100) . '...';
        }
        return (string)$value;
    }
}
