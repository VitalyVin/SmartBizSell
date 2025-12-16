<?php
/**
 * API для получения контактных данных продавца
 * 
 * Возвращает email и телефон продавца для опубликованных активов.
 * Доступно только для опубликованных активов (модерация пройдена).
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
    $pdo = getDBConnection();
    
    // Получаем seller_form_id из запроса
    $sellerFormId = isset($_GET['seller_form_id']) ? (int)$_GET['seller_form_id'] : 0;
    
    if ($sellerFormId <= 0) {
        throw new Exception('Не указан ID актива.');
    }
    
    // Проверяем, что актив опубликован (прошел модерацию)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM published_teasers
        WHERE seller_form_id = ? AND moderation_status = 'published'
    ");
    $stmt->execute([$sellerFormId]);
    $result = $stmt->fetch();
    
    if (!$result || $result['count'] == 0) {
        throw new Exception('Актив не опубликован или не найден.');
    }
    
    // Получаем контактные данные продавца
    $stmt = $pdo->prepare("
        SELECT 
            u.email,
            u.phone,
            u.full_name,
            sf.asset_name
        FROM seller_forms sf
        INNER JOIN users u ON sf.user_id = u.id
        WHERE sf.id = ?
    ");
    $stmt->execute([$sellerFormId]);
    $seller = $stmt->fetch();
    
    if (!$seller) {
        throw new Exception('Данные продавца не найдены.');
    }
    
    // Формируем ответ
    ob_clean();
    echo json_encode([
        'success' => true,
        'seller' => [
            'email' => $seller['email'],
            'phone' => $seller['phone'] ?: null,
            'full_name' => $seller['full_name'] ?: null,
            'asset_name' => $seller['asset_name'] ?: null
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
    error_log('Fatal error in get_seller_contacts: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Критическая ошибка при получении контактов продавца.'
    ]);
    ob_end_flush();
}

