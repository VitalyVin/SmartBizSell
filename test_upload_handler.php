<?php
/**
 * Тестовый обработчик загрузки файлов
 * Используется для тестирования функциональности загрузки документов
 */

header('Content-Type: application/json; charset=utf-8');

// Разрешаем CORS для тестирования
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Обрабатываем только POST запросы
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
$maxFileSize = 20 * 1024 * 1024; // 20 МБ

// Проверяем размер файла
if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Размер файла превышает максимальный допустимый размер (20 МБ).'
    ]);
    exit;
}

// Создаем директорию uploads, если её нет
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

// Генерируем безопасное имя файла
$originalName = $file['name'];
$extension = pathinfo($originalName, PATHINFO_EXTENSION);
$safeName = preg_replace('/[^a-zA-Z0-9\.\-_]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
$safeName = mb_substr($safeName, 0, 150);
$fileName = $safeName . '_' . time() . '.' . $extension;
$filePath = $uploadDir . '/' . $fileName;

// Перемещаем загруженный файл
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось сохранить файл на сервере.'
    ]);
    exit;
}

// Возвращаем успешный ответ
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

