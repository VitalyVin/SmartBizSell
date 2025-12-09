<?php
/**
 * API для удаления документов активов
 * 
 * Обрабатывает удаление документов, привязанных к активам.
 * Проверяет права доступа и удаляет файл с сервера и запись из БД.
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
    ensureAssetDocumentsTable();
    $pdo = getDBConnection();
    
    // Получаем document_id из запроса
    $requestData = json_decode(file_get_contents('php://input'), true);
    $documentId = isset($requestData['document_id']) ? (int)$requestData['document_id'] : 
                  (isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0);
    
    if ($documentId <= 0) {
        throw new Exception('Не указан ID документа.');
    }
    
    // Получаем информацию о документе
    $stmt = $pdo->prepare("
        SELECT ad.*, sf.user_id as form_user_id
        FROM asset_documents ad
        INNER JOIN seller_forms sf ON ad.seller_form_id = sf.id
        WHERE ad.id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();
    
    if (!$document) {
        throw new Exception('Документ не найден.');
    }
    
    // Проверяем права доступа (только владелец актива может удалять документы)
    if ($document['form_user_id'] != $user['id']) {
        throw new Exception('У вас нет прав для удаления этого документа.');
    }
    
    // Удаляем файл с сервера
    $filePath = __DIR__ . '/' . $document['file_path'];
    if (file_exists($filePath)) {
        if (!unlink($filePath)) {
            error_log("Warning: Failed to delete file: " . $filePath);
        }
    }
    
    // Удаляем запись из БД
    $stmt = $pdo->prepare("DELETE FROM asset_documents WHERE id = ?");
    $stmt->execute([$documentId]);
    
    // Получаем обновленную статистику
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(file_size), 0) as total_size, COUNT(*) as count
        FROM asset_documents
        WHERE seller_form_id = ?
    ");
    $stmt->execute([$document['seller_form_id']]);
    $stats = $stmt->fetch();
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Документ успешно удален.',
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
    error_log('Fatal error in delete_asset_document: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Критическая ошибка при удалении документа.'
    ]);
    ob_end_flush();
}

