<?php
/**
 * API для скачивания документов активов
 * 
 * Обрабатывает скачивание документов покупателями.
 * Доступны только документы опубликованных активов.
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Отключаем вывод ошибок PHP
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

try {
    ensureAssetDocumentsTable();
    $pdo = getDBConnection();
    
    // Получаем document_id из запроса
    $documentId = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;
    
    if ($documentId <= 0) {
        http_response_code(400);
        die('Не указан ID документа.');
    }
    
    // Получаем информацию о документе
    $stmt = $pdo->prepare("
        SELECT 
            ad.*,
            sf.user_id as form_user_id,
            sf.status as form_status
        FROM asset_documents ad
        INNER JOIN seller_forms sf ON ad.seller_form_id = sf.id
        WHERE ad.id = ?
    ");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();
    
    if (!$document) {
        http_response_code(404);
        die('Документ не найден.');
    }
    
    // Проверяем права доступа
    $isOwner = false;
    $isPublished = false;
    
    if (isLoggedIn()) {
        $user = getCurrentUser();
        if ($user && $document['form_user_id'] == $user['id']) {
            // Владелец актива - может скачивать все документы
            $isOwner = true;
        } else {
            // Проверяем, опубликован ли актив
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM published_teasers
                WHERE seller_form_id = ? AND moderation_status = 'published'
            ");
            $stmt->execute([$document['seller_form_id']]);
            $result = $stmt->fetch();
            $isPublished = ($result['count'] > 0);
        }
    } else {
        // Неавторизованный пользователь - только опубликованные активы
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM published_teasers
            WHERE seller_form_id = ? AND moderation_status = 'published'
        ");
        $stmt->execute([$document['seller_form_id']]);
        $result = $stmt->fetch();
        $isPublished = ($result['count'] > 0);
    }
    
    if (!$isOwner && !$isPublished) {
        http_response_code(403);
        die('Доступ к документу запрещен.');
    }
    
    // Проверяем существование файла
    $filePath = __DIR__ . '/' . $document['file_path'];
    if (!file_exists($filePath) || !is_file($filePath)) {
        http_response_code(404);
        die('Файл не найден на сервере.');
    }
    
    // Отправляем файл
    header('Content-Type: ' . $document['file_type']);
    header('Content-Disposition: attachment; filename="' . addslashes($document['file_name']) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Отключаем буферизацию для больших файлов
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($filePath);
    exit;
    
} catch (Exception $e) {
    error_log('Error in download_asset_document: ' . $e->getMessage());
    http_response_code(500);
    die('Ошибка при скачивании документа.');
} catch (Throwable $e) {
    error_log('Fatal error in download_asset_document: ' . $e->getMessage());
    http_response_code(500);
    die('Критическая ошибка при скачивании документа.');
}

