<?php
/**
 * run_migration_blog.php
 * 
 * Скрипт для выполнения миграции блога
 * Создает таблицу blog_posts в базе данных
 * 
 * Использование:
 * 1. Через браузер: https://smartbizsell.ru/run_migration_blog.php
 * 2. Через командную строку: php run_migration_blog.php
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверка, что скрипт запущен из командной строки или авторизованным пользователем
$isCLI = php_sapi_name() === 'cli';
$isAuthorized = false;

if (!$isCLI) {
    // В браузере - проверяем авторизацию
    if (isLoggedIn() && isModerator()) {
        $isAuthorized = true;
    } else {
        header('HTTP/1.0 403 Forbidden');
        die('Доступ запрещен. Только модераторы могут выполнять миграции.');
    }
} else {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    die('Доступ запрещен.');
}

echo "=== Выполнение миграции блога ===\n\n";

try {
    $pdo = getDBConnection();
    
    // Читаем SQL файл миграции
    $migrationFile = __DIR__ . '/db/migration_blog.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Файл миграции не найден: $migrationFile");
    }
    
    $sql = file_get_contents($migrationFile);
    
    if (empty($sql)) {
        throw new Exception("Файл миграции пуст");
    }
    
    echo "Файл миграции найден: $migrationFile\n";
    echo "Размер файла: " . filesize($migrationFile) . " байт\n\n";
    
    // Разбиваем SQL на отдельные запросы (по точке с запятой)
    // Убираем комментарии и пустые строки
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($query) {
            $query = trim($query);
            // Пропускаем пустые строки и комментарии
            return !empty($query) && !preg_match('/^--/', $query);
        }
    );
    
    echo "Найдено SQL запросов: " . count($queries) . "\n\n";
    
    // Выполняем каждый запрос
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($queries as $index => $query) {
        // Убираем комментарии из запроса
        $query = preg_replace('/--.*$/m', '', $query);
        $query = trim($query);
        
        if (empty($query)) {
            continue;
        }
        
        $queryNumber = $index + 1;
        echo "[$queryNumber] Выполнение запроса...\n";
        
        try {
            $pdo->exec($query);
            echo "    ✓ Успешно\n";
            $successCount++;
        } catch (PDOException $e) {
            // Игнорируем ошибки "таблица уже существует" и "индекс уже существует"
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, 'already exists') !== false || 
                strpos($errorMessage, 'Duplicate key') !== false ||
                $errorCode == 1050 || // Table already exists
                $errorCode == 1061) { // Duplicate key name
                echo "    ⚠ Пропущено (уже существует): " . substr($errorMessage, 0, 100) . "\n";
                $successCount++;
            } else {
                echo "    ✗ Ошибка: " . $errorMessage . "\n";
                $errorCount++;
            }
        }
    }
    
    echo "\n=== Результаты ===\n";
    echo "Успешно: $successCount\n";
    echo "Ошибок: $errorCount\n\n";
    
    // Проверяем, что таблица создана
    $stmt = $pdo->query("SHOW TABLES LIKE 'blog_posts'");
    if ($stmt->rowCount() > 0) {
        echo "✓ Таблица blog_posts успешно создана!\n\n";
        
        // Показываем структуру таблицы
        $stmt = $pdo->query("DESCRIBE blog_posts");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Структура таблицы blog_posts:\n";
        echo str_repeat("-", 80) . "\n";
        printf("%-20s %-15s %-10s %-10s %-10s\n", "Поле", "Тип", "Null", "Ключ", "По умолчанию");
        echo str_repeat("-", 80) . "\n";
        
        foreach ($columns as $column) {
            printf("%-20s %-15s %-10s %-10s %-10s\n",
                $column['Field'],
                $column['Type'],
                $column['Null'],
                $column['Key'],
                $column['Default'] ?? 'NULL'
            );
        }
        echo str_repeat("-", 80) . "\n";
        
        // Проверяем индексы
        $stmt = $pdo->query("SHOW INDEXES FROM blog_posts");
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($indexes)) {
            echo "\nИндексы:\n";
            $uniqueIndexes = [];
            foreach ($indexes as $index) {
                $keyName = $index['Key_name'];
                if (!isset($uniqueIndexes[$keyName])) {
                    $uniqueIndexes[$keyName] = $index;
                    echo "  - {$keyName} (на поле: {$index['Column_name']})\n";
                }
            }
        }
        
        echo "\n✓ Миграция завершена успешно!\n";
    } else {
        echo "✗ Ошибка: Таблица blog_posts не была создана.\n";
    }
    
} catch (Exception $e) {
    echo "\n✗ Критическая ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . "\n";
    echo "Строка: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n";

