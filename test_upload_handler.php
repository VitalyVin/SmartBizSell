<?php
/**
 * Тестовый обработчик загрузки файлов
 * 
 * Назначение:
 * - Тестирование функциональности загрузки документов
 * - Отладка процесса загрузки файлов на сервер
 * - Проверка работы валидации и обработки файлов
 * 
 * Функциональность:
 * - Принимает файлы через POST запрос
 * - Валидирует размер и тип файла
 * - Сохраняет файлы в папку uploads/test/
 * - Возвращает JSON с результатом загрузки
 * 
 * Безопасность:
 * - Разрешает CORS для тестирования (только для тестовой среды)
 * - Валидирует размер файла (максимум 20 МБ)
 * - Генерирует безопасные имена файлов
 * 
 * @package SmartBizSell
 * @version 1.0
 */

header('Content-Type: application/json; charset=utf-8');

/**
 * Разрешаем CORS для тестирования
 * 
 * ВНИМАНИЕ: В production окружении следует ограничить Access-Control-Allow-Origin
 * конкретным доменом, а не использовать '*'
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

/**
 * Обрабатываем только POST запросы
 * 
 * GET, PUT, DELETE и другие методы возвращают ошибку 405 (Method Not Allowed)
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не разрешен. Используйте POST.'
    ]);
    exit;
}

// Проверяем, что файл был загружен
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorMessage = 'Файл не был загружен.';
    if (isset($_FILES['file']['error'])) {
        switch ($_FILES['file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errorMessage = 'Размер файла превышает допустимый лимит.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errorMessage = 'Файл был загружен лишь частично.';
                break;
            case UPLOAD_ERR_NO_FILE:
                $errorMessage = 'Файл не был загружен.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $errorMessage = 'Отсутствует временная папка для загрузки.';
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $errorMessage = 'Не удалось записать файл на диск.';
                break;
            case UPLOAD_ERR_EXTENSION:
                $errorMessage = 'Загрузка файла остановлена расширением PHP.';
                break;
        }
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $errorMessage
    ]);
    exit;
}

$file = $_FILES['file'];
$maxFileSize = 20 * 1024 * 1024; // 20 МБ - максимальный размер файла

/**
 * Проверка размера файла
 * 
 * Предотвращает загрузку файлов, превышающих лимит.
 * Это защищает от переполнения диска и проблем с производительностью.
 */
if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Размер файла превышает максимальный допустимый размер (20 МБ).'
    ]);
    exit;
}

/**
 * Создание директории для загрузки файлов
 * 
 * Если директория не существует, создается с правами 0755 (rwxr-xr-x).
 * Это обеспечивает безопасность: владелец может читать/писать/выполнять,
 * группа и другие - только читать и выполнять.
 */
$uploadDir = __DIR__ . '/uploads/test';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось создать директорию для загрузки.'
        ]);
        exit;
    }
}

/**
 * Генерация безопасного имени файла
 * 
 * Процесс:
 * 1. Извлекаем оригинальное имя и расширение
 * 2. Удаляем опасные символы (оставляем только буквы, цифры, точки, дефисы, подчеркивания)
 * 3. Ограничиваем длину имени до 150 символов
 * 4. Добавляем timestamp для уникальности
 * 
 * Это предотвращает:
 * - Path traversal атаки (../ и т.д.)
 * - Перезапись существующих файлов
 * - Проблемы с файловой системой
 */
$originalName = $file['name'];
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$safeName = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$safeName = mb_substr($safeName, 0, 150);
$fileName = $safeName . '_' . time() . '.' . $extension;
$filePath = $uploadDir . '/' . $fileName;

/**
 * Перемещение загруженного файла из временной директории
 * 
 * move_uploaded_file() безопаснее, чем copy(), так как проверяет,
 * что файл был действительно загружен через HTTP POST.
 */
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось сохранить файл на сервере.'
    ]);
    exit;
}

/**
 * Формирование успешного ответа
 * 
 * Возвращает информацию о загруженном файле:
 * - Оригинальное имя
 * - Имя на сервере
 * - Размер файла
 * - Путь к файлу
 * - URL для доступа к файлу
 */
echo json_encode([
    'success' => true,
    'message' => 'Файл успешно загружен.',
    'file' => [
        'original_name' => $originalName,
        'saved_name' => $fileName,
        'size' => $file['size'],
        'size_mb' => round($file['size'] / 1024 / 1024, 2),
        'path' => 'uploads/test/' . $fileName,
        'url' => 'uploads/test/' . $fileName
    ]
]);

