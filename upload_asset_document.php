<?php
/**
 * API для загрузки документов активов
 * 
 * Обрабатывает загрузку файлов, привязанных к конкретному активу (seller_form).
 * Проверяет права доступа, валидирует файлы и сохраняет их на сервер.
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Отключаем вывод ошибок PHP в ответ, чтобы не ломать JSON
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Включаем буферизацию вывода
ob_start();

// Проверка авторизации
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

try {
    error_log('Upload request received. User ID: ' . $user['id']);
    
    // Создаем таблицу, если её нет
    ensureAssetDocumentsTable();
    
    $pdo = getDBConnection();
    
    // Получаем seller_form_id из запроса
    $sellerFormId = isset($_POST['seller_form_id']) ? (int)$_POST['seller_form_id'] : 0;
    error_log('Seller form ID: ' . $sellerFormId);
    
    if ($sellerFormId <= 0) {
        throw new Exception('Не указан ID актива.');
    }
    
    // Проверяем, что анкета принадлежит пользователю
    $stmt = $pdo->prepare("SELECT id, user_id FROM seller_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$sellerFormId, $user['id']]);
    $form = $stmt->fetch();
    
    if (!$form) {
        throw new Exception('Актив не найден или не принадлежит вам.');
    }
    
    // Проверяем наличие загруженного файла
    if (!isset($_FILES['file'])) {
        error_log('$_FILES["file"] is not set');
        throw new Exception('Файл не был загружен.');
    }
    
    $file = $_FILES['file'];
    error_log('File info: name=' . $file['name'] . ', size=' . $file['size'] . ', error=' . $file['error']);
    
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        throw new Exception('Файл не был загружен.');
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, разрешенный сервером.',
            UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме.',
            UPLOAD_ERR_PARTIAL => 'Файл был загружен частично.',
            UPLOAD_ERR_NO_FILE => 'Файл не был загружен.',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка.',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск.',
            UPLOAD_ERR_EXTENSION => 'Загрузка файла была остановлена расширением PHP.',
        ];
        $errorMsg = $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки файла (код: ' . $file['error'] . ')';
        error_log('Upload error: ' . $errorMsg);
        throw new Exception($errorMsg);
    }
    
    // Валидация файла
    error_log('Validating file...');
    $validation = validateUploadedFile($file, $sellerFormId);
    if (!$validation['valid']) {
        error_log('Validation failed: ' . $validation['error']);
        throw new Exception($validation['error']);
    }
    error_log('File validation passed');
    
    // Определяем MIME тип
    if (!function_exists('finfo_open')) {
        error_log('Warning: finfo_open function not available, using mime_content_type');
        $mimeType = mime_content_type($file['tmp_name']);
        if (!$mimeType) {
            // Fallback на определение по расширению
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
            ];
            $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
        }
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
    error_log('MIME type: ' . $mimeType);
    
    // Санитизируем имя файла
    $originalFileName = $file['name'];
    $safeFileName = sanitizeFileName($originalFileName);
    
    // Создаем уникальное имя файла с timestamp
    $timestamp = time();
    $fileExtension = pathinfo($safeFileName, PATHINFO_EXTENSION);
    $fileNameWithoutExt = pathinfo($safeFileName, PATHINFO_FILENAME);
    $uniqueFileName = $timestamp . '_' . $fileNameWithoutExt . '.' . $fileExtension;
    
    // Создаем папку для документов актива, если её нет
    $assetFolder = ensureAssetDocumentsFolder($sellerFormId);
    error_log('Asset folder: ' . $assetFolder);
    
    if (!is_dir($assetFolder)) {
        error_log('Creating asset folder: ' . $assetFolder);
        if (!mkdir($assetFolder, 0755, true)) {
            throw new Exception('Не удалось создать папку для документов.');
        }
    }
    
    if (!is_writable($assetFolder)) {
        error_log('Asset folder is not writable: ' . $assetFolder);
        throw new Exception('Папка для документов недоступна для записи. Обратитесь к администратору.');
    }
    
    $filePath = $assetFolder . '/' . $uniqueFileName;
    error_log('Target file path: ' . $filePath);
    
    // Перемещаем загруженный файл
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        error_log('Failed to move uploaded file. tmp_name: ' . $file['tmp_name'] . ', target: ' . $filePath);
        throw new Exception('Не удалось сохранить файл на сервер. Проверьте права доступа к папке uploads/asset_documents/.');
    }
    error_log('File moved successfully');
    
    // Сохраняем информацию о файле в БД
    $stmt = $pdo->prepare("
        INSERT INTO asset_documents 
        (seller_form_id, user_id, file_name, file_path, file_size, file_type, uploaded_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    // Сохраняем относительный путь от корня проекта
    $relativePath = 'uploads/asset_documents/' . $sellerFormId . '/' . $uniqueFileName;
    
    $stmt->execute([
        $sellerFormId,
        $user['id'],
        $originalFileName,
        $relativePath,
        $file['size'],
        $mimeType
    ]);
    
    $documentId = $pdo->lastInsertId();
    
    // Получаем общий размер документов актива
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(file_size), 0) as total_size, COUNT(*) as count
        FROM asset_documents
        WHERE seller_form_id = ?
    ");
    $stmt->execute([$sellerFormId]);
    $stats = $stmt->fetch();
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Документ успешно загружен.',
        'document' => [
            'id' => $documentId,
            'file_name' => $originalFileName,
            'file_size' => $file['size'],
            'file_type' => $mimeType,
            'uploaded_at' => date('Y-m-d H:i:s')
        ],
        'stats' => [
            'total_size' => (int)$stats['total_size'],
            'total_size_mb' => round($stats['total_size'] / 1024 / 1024, 2),
            'max_size_mb' => round(MAX_DOCUMENTS_SIZE_PER_ASSET / 1024 / 1024, 2),
            'count' => (int)$stats['count']
        ]
    ]);
    ob_end_flush();
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    ob_end_flush();
} catch (Throwable $e) {
    error_log('Fatal error in upload_asset_document: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Критическая ошибка при загрузке документа.'
    ]);
    ob_end_flush();
}

