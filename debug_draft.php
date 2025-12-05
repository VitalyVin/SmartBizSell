<?php
/**
 * Скрипт для отладки сохранения черновиков
 * 
 * Функциональность:
 * - Отображение последнего сохраненного черновика текущего пользователя
 * - Проверка валидности JSON в поле data_json
 * - Проверка наличия ключевых полей (production, financial, balance)
 * - Тестирование восстановления данных из черновика в $_POST
 * - Отображение списка последних 5 черновиков с метаданными
 * 
 * Использование:
 * Откройте этот файл в браузере после сохранения черновика для диагностики проблем
 * Полезно при отладке проблем с сохранением/восстановлением данных формы
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверка авторизации - доступ только для авторизованных пользователей
// Это защищает от несанкционированного доступа к данным других пользователей
if (!isLoggedIn()) {
    die('Нужно войти в систему');
}

$pdo = getDBConnection();

// Получаем последний черновик текущего пользователя
// Черновики имеют статус 'draft' и сортируются по дате обновления (новые первыми)
// LIMIT 1 гарантирует получение только самого свежего черновика
$stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE user_id = ? AND status = 'draft' ORDER BY updated_at DESC LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$form = $stmt->fetch();

// Если черновик не найден, выводим сообщение и прекращаем выполнение
if (!$form) {
    die('Черновики не найдены');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отладка черновика</title>
    <style>
        /* Стили для отладочной страницы */
        body {
            font-family: monospace;
            padding: 20px;
            background: #f5f5f5;
        }
        .section {
            background: white;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            border: 1px solid #dee2e6;
        }
        /* Цветовые классы для статусов */
        .success { color: #28a745; }  /* Зеленый - успех */
        .error { color: #dc3545; }    /* Красный - ошибка */
        .info { color: #17a2b8; }     /* Синий - информация */
        .warning { color: #ffc107; }  /* Желтый - предупреждение */
    </style>
</head>
<body>
    <h1>🔍 Отладка черновика #<?php echo $form['id']; ?></h1>

    <div class="section">
        <h2>📋 Основная информация</h2>
        <p><strong>ID формы:</strong> <?php echo $form['id']; ?></p>
        <p><strong>Статус:</strong> <?php echo $form['status']; ?></p>
        <p><strong>Название актива:</strong> <?php echo htmlspecialchars($form['asset_name'] ?? 'не указано'); ?></p>
        <p><strong>Создано:</strong> <?php echo $form['created_at']; ?></p>
        <p><strong>Обновлено:</strong> <?php echo $form['updated_at']; ?></p>
    </div>

    <!-- Секция: Проверка data_json -->
    <div class="section">
        <h2>💾 data_json (размер: <?php echo strlen($form['data_json'] ?? ''); ?> байт)</h2>
        <?php if (empty($form['data_json'])): ?>
            <!-- Если data_json пустой, это критическая ошибка -->
            <p class="error">❌ data_json ПУСТОЙ!</p>
            <p class="warning">⚠️ Это означает, что данные не были сохранены в новом формате.</p>
        <?php else: ?>
            <p class="success">✅ data_json существует</p>
            <?php
            // Пытаемся декодировать JSON
            $decoded = json_decode($form['data_json'], true);
            if (json_last_error() !== JSON_ERROR_NONE): ?>
                <!-- Если JSON невалидный, выводим ошибку -->
                <p class="error">❌ Ошибка декодирования JSON: <?php echo json_last_error_msg(); ?></p>
            <?php else: ?>
                <p class="success">✅ JSON валидный</p>
                
                <!-- Отображаем список всех ключей в data_json -->
                <h3>Ключи в data_json:</h3>
                <pre><?php echo implode(', ', array_keys($decoded)); ?></pre>
                
                <!-- Проверка наличия данных production (объемы производства) -->
                <h3>Данные production:</h3>
                <?php if (isset($decoded['production'])): ?>
                    <p class="success">✅ production существует (<?php echo count($decoded['production']); ?> элементов)</p>
                    <pre><?php print_r($decoded['production']); ?></pre>
                <?php else: ?>
                    <p class="error">❌ production НЕ найден в data_json</p>
                    <p class="warning">⚠️ Это может означать, что таблица объемов производства не была сохранена.</p>
                <?php endif; ?>

                <!-- Проверка наличия данных financial (финансовые показатели) -->
                <h3>Данные financial:</h3>
                <?php if (isset($decoded['financial'])): ?>
                    <p class="success">✅ financial существует</p>
                    <pre><?php print_r($decoded['financial']); ?></pre>
                <?php else: ?>
                    <p class="warning">⚠️ financial НЕ найден в data_json</p>
                    <p class="info">ℹ️ Это не критично, если форма еще не заполнена полностью.</p>
                <?php endif; ?>

                <!-- Проверка наличия данных balance (балансовые показатели) -->
                <h3>Данные balance:</h3>
                <?php if (isset($decoded['balance'])): ?>
                    <p class="success">✅ balance существует</p>
                    <pre><?php print_r($decoded['balance']); ?></pre>
                <?php else: ?>
                    <p class="warning">⚠️ balance НЕ найден в data_json</p>
                    <p class="info">ℹ️ Это не критично, если форма еще не заполнена полностью.</p>
                <?php endif; ?>

                <!-- Отображаем первые 2000 символов data_json для визуальной проверки -->
                <h3>Полный data_json (первые 2000 символов):</h3>
                <pre><?php echo htmlspecialchars(substr($form['data_json'], 0, 2000)); ?><?php if (strlen($form['data_json']) > 2000) echo '... (обрезано)'; ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Секция: Тестирование восстановления данных -->
    <div class="section">
        <h2>🔧 Тестирование восстановления</h2>
        <p class="info">ℹ️ Эта секция проверяет, можно ли восстановить данные из data_json в $_POST</p>
        <?php
        // Очищаем $_POST для чистого теста
        $_POST = [];
        
        // Пытаемся восстановить данные из data_json
        if (!empty($form['data_json'])) {
            $decoded = json_decode($form['data_json'], true);
            if (is_array($decoded)) {
                $_POST = $decoded;
            }
        }
        ?>
        <p><strong>asset_name после восстановления:</strong> <?php echo htmlspecialchars($_POST['asset_name'] ?? 'НЕ ВОССТАНОВЛЕНО'); ?></p>
        <p><strong>production после восстановления:</strong> 
            <?php if (isset($_POST['production'])): ?>
                <span class="success">✅ Восстановлено (<?php echo count($_POST['production']); ?> элементов)</span>
            <?php else: ?>
                <span class="error">❌ НЕ восстановлено</span>
            <?php endif; ?>
        </p>
    </div>

    <!-- Секция: Список последних черновиков -->
    <div class="section">
        <h2>📊 Последние 5 черновиков</h2>
        <p class="info">ℹ️ Список последних черновиков для сравнения и анализа</p>
        <?php
        // Получаем последние 5 черновиков с информацией о размере JSON
        // LENGTH(data_json) возвращает размер поля в байтах
        $stmt = $pdo->prepare("SELECT id, asset_name, status, LENGTH(data_json) as json_size, updated_at FROM seller_forms WHERE user_id = ? ORDER BY updated_at DESC LIMIT 5");
        $stmt->execute([$_SESSION['user_id']]);
        $forms = $stmt->fetchAll();
        ?>
        <table border="1" cellpadding="10" style="width: 100%; border-collapse: collapse;">
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Статус</th>
                <th>Размер JSON</th>
                <th>Обновлено</th>
            </tr>
            <?php foreach ($forms as $f): ?>
            <tr>
                <td><?php echo $f['id']; ?></td>
                <td><?php echo htmlspecialchars($f['asset_name'] ?? ''); ?></td>
                <td><?php echo $f['status']; ?></td>
                <td><?php echo $f['json_size']; ?> байт</td>
                <td><?php echo $f['updated_at']; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Секция: Быстрые действия -->
    <div class="section">
        <h2>🔗 Действия</h2>
        <p><a href="seller_form.php?form_id=<?php echo $form['id']; ?>">Открыть форму для редактирования</a></p>
        <p><a href="dashboard.php">Вернуться в кабинет</a></p>
    </div>
</body>
</html>

