<?php
/**
 * Главная страница тестового модуля
 * 
 * Веб-интерфейс для запуска и просмотра результатов тестов
 */

// Включаем буферизацию вывода в самом начале
ob_start();

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

try {
    require_once __DIR__ . '/config.php';
} catch (Exception $e) {
    ob_end_clean();
    die('Ошибка загрузки конфигурации: ' . htmlspecialchars($e->getMessage()));
}

try {
    require_once __DIR__ . '/TestRunner.php';
} catch (Exception $e) {
    ob_end_clean();
    die('Ошибка загрузки TestRunner: ' . htmlspecialchars($e->getMessage()));
}

// Инициализация сессии для тестов
// config.php уже должен был инициализировать сессию, но проверим
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Автозагрузка тестовых классов
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$action = $_GET['action'] ?? 'list';
$testClass = $_GET['class'] ?? '';
$testName = $_GET['test'] ?? '';

// Список всех тестовых классов
$testClasses = [
    'AuthTest' => 'Тесты авторизации и регистрации',
    'FormTest' => 'Тесты анкет продавца',
    'TeaserTest' => 'Тесты генерации тизеров',
    'ModerationTest' => 'Тесты модерации тизеров',
    'DCFTest' => 'Тесты DCF модели и мультипликаторов',
    'TermSheetTest' => 'Тесты генерации Term Sheet',
    'DashboardTest' => 'Тесты личного кабинета',
    'CatalogTest' => 'Тесты каталога бизнесов',
    'DocumentTest' => 'Тесты работы с документами',
    'PasswordTest' => 'Тесты восстановления пароля',
];

// Обработка AJAX запросов
if ($action === 'run' && !empty($testClass)) {
    // Очищаем буфер перед отправкой JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $runner = new TestRunner();
        $results = [];
        
        if (!empty($testName)) {
            // Запуск одного теста
            if (class_exists($testClass)) {
                $testInstance = new $testClass($runner);
                if (method_exists($testInstance, $testName)) {
                    $runner->runTest($testClass . '::' . $testName, function($r) use ($testInstance, $testName) {
                        $testInstance->$testName();
                    });
                } else {
                    throw new Exception("Метод $testName не найден в классе $testClass");
                }
            } else {
                throw new Exception("Класс $testClass не найден");
            }
        } else {
            // Запуск всех тестов класса
            if (class_exists($testClass)) {
                $testInstance = new $testClass($runner);
                $methods = get_class_methods($testInstance);
                $found = false;
                foreach ($methods as $method) {
                    if (strpos($method, 'test') === 0) {
                        $found = true;
                        $runner->runTest($testClass . '::' . $method, function($r) use ($testInstance, $method) {
                            $testInstance->$method();
                        });
                    }
                }
                if (!$found) {
                    throw new Exception("В классе $testClass не найдено методов, начинающихся с 'test'");
                }
            } else {
                throw new Exception("Класс $testClass не найден");
            }
        }
        
        $response = [
            'success' => true,
            'results' => $runner->getResults(),
            'stats' => $runner->getStats()
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        $response = [
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

if ($action === 'run_all') {
    // Очищаем буфер перед отправкой JSON
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $runner = new TestRunner();
        
        foreach ($testClasses as $className => $description) {
            if (class_exists($className)) {
                try {
                    $testInstance = new $className($runner);
                    $methods = get_class_methods($testInstance);
                    foreach ($methods as $method) {
                        if (strpos($method, 'test') === 0) {
                            $runner->runTest($className . '::' . $method, function($r) use ($testInstance, $method) {
                                $testInstance->$method();
                            });
                        }
                    }
                } catch (Throwable $e) {
                    $runner->runTest($className . '::__construct', function($r) use ($e) {
                        throw $e;
                    });
                }
            }
        }
        
        $response = [
            'success' => true,
            'results' => $runner->getResults(),
            'stats' => $runner->getStats()
        ];
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        $response = [
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// Если это не AJAX запрос, выводим HTML
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тестовый модуль SmartBizSell</title>
    <link rel="stylesheet" href="assets/test.css">
    <style>
        /* Базовые стили на случай, если CSS не загрузится */
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .error-box { background: #fee; border: 2px solid #f00; padding: 15px; margin: 20px 0; }
    </style>
</head>
<body>
    <?php if (isset($_GET['debug'])): ?>
        <div class="error-box">
            <strong>Режим отладки</strong><br>
            PHP версия: <?php echo phpversion(); ?><br>
            Ошибки: <?php echo ini_get('display_errors') ? 'Включены' : 'Выключены'; ?><br>
            <a href="debug.php">Полная отладка</a>
        </div>
    <?php endif; ?>
    <div class="test-container">
        <header class="test-header">
            <h1>🧪 Тестовый модуль SmartBizSell</h1>
            <div class="test-actions">
                <button id="run-all-btn" class="btn btn-primary">Запустить все тесты</button>
                <button id="clear-results-btn" class="btn btn-secondary">Очистить результаты</button>
            </div>
        </header>
        
        <div class="test-layout">
            <!-- Левая панель: список тестов -->
            <aside class="test-sidebar">
                <h2>Тестовые классы</h2>
                <ul class="test-class-list">
                    <?php foreach ($testClasses as $className => $description): ?>
                        <li>
                            <a href="#" class="test-class-link" data-class="<?php echo htmlspecialchars($className); ?>">
                                <?php echo htmlspecialchars($description); ?>
                            </a>
                            <button class="btn-run-class" data-class="<?php echo htmlspecialchars($className); ?>" title="Запустить все тесты класса">
                                ▶
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </aside>
            
            <!-- Центральная панель: результаты -->
            <main class="test-main">
                <div class="test-stats" id="test-stats">
                    <div class="stat-item">
                        <span class="stat-label">Всего:</span>
                        <span class="stat-value" id="stat-total">0</span>
                    </div>
                    <div class="stat-item stat-passed">
                        <span class="stat-label">Пройдено:</span>
                        <span class="stat-value" id="stat-passed">0</span>
                    </div>
                    <div class="stat-item stat-failed">
                        <span class="stat-label">Провалено:</span>
                        <span class="stat-value" id="stat-failed">0</span>
                    </div>
                </div>
                
                <div class="test-results" id="test-results">
                    <p class="test-placeholder">Выберите тестовый класс для запуска тестов</p>
                </div>
            </main>
            
            <!-- Правая панель: детали теста -->
            <aside class="test-details" id="test-details">
                <h3>Детали теста</h3>
                <div class="test-details-content">
                    <p class="test-placeholder">Выберите тест для просмотра деталей</p>
                </div>
            </aside>
        </div>
    </div>
    
    <script src="assets/test.js"></script>
    <script>
        // Проверка загрузки JavaScript
        window.addEventListener('load', function() {
            console.log('Страница загружена');
            var runAllBtn = document.getElementById('run-all-btn');
            if (!runAllBtn) {
                console.error('Кнопка run-all-btn не найдена');
                document.body.innerHTML = '<div class="error-box"><strong>Ошибка:</strong> Элементы интерфейса не найдены. Проверьте консоль браузера.</div>' + document.body.innerHTML;
            } else {
                console.log('Интерфейс готов');
            }
        });
    </script>
</body>
</html>
<?php
// Выводим буфер для обычных запросов (AJAX запросы уже сделали exit)
if ($action === 'list') {
    ob_end_flush();
}
?>