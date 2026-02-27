<?php
/**
 * Страница отладки тестового модуля
 */

// Включаем буферизацию вывода в самом начале
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>Отладка тестового модуля</h1>";

echo "<h2>1. Проверка PHP</h2>";
echo "Версия PHP: " . phpversion() . "<br>";
echo "Ошибки включены: " . (ini_get('display_errors') ? 'Да' : 'Нет') . "<br>";

echo "<h2>2. Проверка файлов</h2>";
$files = [
    'config.php' => __DIR__ . '/config.php',
    'TestRunner.php' => __DIR__ . '/TestRunner.php',
    'index.php' => __DIR__ . '/index.php',
    'assets/test.css' => __DIR__ . '/assets/test.css',
    'assets/test.js' => __DIR__ . '/assets/test.js',
];

foreach ($files as $name => $path) {
    $exists = file_exists($path);
    echo $name . ": " . ($exists ? "✓ Существует" : "✗ Не найден") . "<br>";
    if ($exists) {
        echo "&nbsp;&nbsp;Размер: " . filesize($path) . " байт<br>";
    }
}

echo "<h2>3. Проверка директорий</h2>";
$dirs = [
    'classes' => __DIR__ . '/classes',
    'assets' => __DIR__ . '/assets',
];

foreach ($dirs as $name => $path) {
    $exists = is_dir($path);
    echo $name . ": " . ($exists ? "✓ Существует" : "✗ Не найдена") . "<br>";
    if ($exists) {
        $files = scandir($path);
        echo "&nbsp;&nbsp;Файлов: " . (count($files) - 2) . "<br>";
    }
}

echo "<h2>4. Проверка конфигурации</h2>";
try {
    require_once __DIR__ . '/config.php';
    echo "✓ config.php загружен<br>";
    
    if (defined('TEST_BASE_URL')) {
        echo "✓ TEST_BASE_URL: " . TEST_BASE_URL . "<br>";
    } else {
        echo "✗ TEST_BASE_URL не определен<br>";
    }
    
    if (function_exists('getTestDBConnection')) {
        echo "✓ getTestDBConnection существует<br>";
        try {
            $pdo = getTestDBConnection();
            echo "✓ Подключение к БД успешно<br>";
        } catch (Exception $e) {
            echo "✗ Ошибка подключения к БД: " . htmlspecialchars($e->getMessage()) . "<br>";
        }
    } else {
        echo "✗ getTestDBConnection не существует<br>";
    }
} catch (Exception $e) {
    echo "✗ Ошибка загрузки config.php: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "Трассировка: <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>5. Проверка TestRunner</h2>";
try {
    require_once __DIR__ . '/TestRunner.php';
    echo "✓ TestRunner.php загружен<br>";
    
    if (class_exists('TestRunner')) {
        echo "✓ Класс TestRunner существует<br>";
        $runner = new TestRunner();
        echo "✓ TestRunner создан<br>";
    } else {
        echo "✗ Класс TestRunner не существует<br>";
    }
} catch (Exception $e) {
    echo "✗ Ошибка загрузки TestRunner: " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "Трассировка: <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>6. Проверка тестовых классов</h2>";
$testClasses = [
    'AuthTest',
    'FormTest',
    'TeaserTest',
    'ModerationTest',
    'DCFTest',
    'TermSheetTest',
    'DashboardTest',
    'CatalogTest',
    'DocumentTest',
    'PasswordTest',
];

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/classes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

foreach ($testClasses as $className) {
    $file = __DIR__ . '/classes/' . $className . '.php';
    $fileExists = file_exists($file);
    echo $className . ": ";
    
    if ($fileExists) {
        echo "✓ Файл существует";
        try {
            if (class_exists($className)) {
                echo " ✓ Класс загружен";
            } else {
                echo " ✗ Класс не загружен";
            }
        } catch (Exception $e) {
            echo " ✗ Ошибка: " . htmlspecialchars($e->getMessage());
        }
    } else {
        echo "✗ Файл не найден";
    }
    echo "<br>";
}

echo "<h2>7. Проверка сессии</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "Статус сессии: " . (session_status() === PHP_SESSION_ACTIVE ? "Активна" : "Не активна") . "<br>";

echo "<h2>8. Ссылки</h2>";
echo '<a href="index.php">Перейти к тестовому модулю</a><br>';

// Очищаем буфер и выводим содержимое
ob_end_flush();
