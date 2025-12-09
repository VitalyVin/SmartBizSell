<?php
/**
 * API для получения списка документов актива
 * 
 * Возвращает список документов, привязанных к конкретному активу.
 * Для продавца - все документы его активов.
 * Для покупателя - только документы опубликованных активов.
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

try {
    ensureAssetDocumentsTable();
    $pdo = getDBConnection();
    
    // Получаем seller_form_id из запроса
    $sellerFormId = isset($_GET['seller_form_id']) ? (int)$_GET['seller_form_id'] : 0;
    
    if ($sellerFormId <= 0) {
        throw new Exception('Не указан ID актива.');
    }
    
    // Проверяем, существует ли актив
    $stmt = $pdo->prepare("SELECT id, user_id, status FROM seller_forms WHERE id = ?");
    $stmt->execute([$sellerFormId]);
    $form = $stmt->fetch();
    
    if (!$form) {
        throw new Exception('Актив не найден.');
    }
    
    // Проверяем права доступа
    $isOwner = false;
    $isPublished = false;
    
    if (isLoggedIn()) {
        $user = getCurrentUser();
        if ($user && $form['user_id'] == $user['id']) {
            // Владелец актива - может видеть все документы
            $isOwner = true;
        } else {
            // Проверяем, опубликован ли актив
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM published_teasers
                WHERE seller_form_id = ? AND moderation_status = 'published'
            ");
            $stmt->execute([$sellerFormId]);
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
        $stmt->execute([$sellerFormId]);
        $result = $stmt->fetch();
        $isPublished = ($result['count'] > 0);
    }
    
    if (!$isOwner && !$isPublished) {
        throw new Exception('Документы недоступны для этого актива.');
    }
    
    // Получаем список документов
    $stmt = $pdo->prepare("
        SELECT 
            id,
            file_name,
            file_size,
            file_type,
            uploaded_at
        FROM asset_documents
        WHERE seller_form_id = ?
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$sellerFormId]);
    $documents = $stmt->fetchAll();
    
    // Форматируем данные для ответа
    $formattedDocuments = [];
    foreach ($documents as $doc) {
        $formattedDocuments[] = [
            'id' => (int)$doc['id'],
            'file_name' => $doc['file_name'],
            'file_size' => (int)$doc['file_size'],
            'file_size_mb' => round($doc['file_size'] / 1024 / 1024, 2),
            'file_type' => $doc['file_type'],
            'uploaded_at' => $doc['uploaded_at'],
            'uploaded_at_formatted' => date('d.m.Y H:i', strtotime($doc['uploaded_at']))
        ];
    }
    
    // Получаем статистику
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
        'documents' => $formattedDocuments,
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
    error_log('Fatal error in get_asset_documents: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Критическая ошибка при получении списка документов.'
    ]);
    ob_end_flush();
}

