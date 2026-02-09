<?php
/**
 * API для увеличения счётчика просмотров карточки тизера
 * 
 * Принимает teaser_id и увеличивает views на 1 для опубликованного тизера.
 * Возвращает новое значение views.
 * 
 * Доступ: публичный (без авторизации), так как каталог доступен всем.
 */

require_once '../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Получаем teaser_id из запроса
    $teaserId = isset($_GET['teaser_id']) ? (int)$_GET['teaser_id'] : 
                (isset($_POST['teaser_id']) ? (int)$_POST['teaser_id'] : 0);
    
    if ($teaserId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Не указан teaser_id.']);
        exit;
    }
    
    $pdo = getDBConnection();
    
    // Проверяем, что тизер существует и опубликован
    $stmt = $pdo->prepare("
        SELECT id, moderation_status, views 
        FROM published_teasers 
        WHERE id = ?
    ");
    $stmt->execute([$teaserId]);
    $teaser = $stmt->fetch();
    
    if (!$teaser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Тизер не найден.']);
        exit;
    }
    
    if ($teaser['moderation_status'] !== 'published') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Можно увеличивать просмотры только для опубликованных тизеров.']);
        exit;
    }
    
    // Увеличиваем счётчик просмотров
    $stmt = $pdo->prepare("
        UPDATE published_teasers 
        SET views = views + 1 
        WHERE id = ?
    ");
    $stmt->execute([$teaserId]);
    
    // Получаем новое значение views
    $stmt = $pdo->prepare("SELECT views FROM published_teasers WHERE id = ?");
    $stmt->execute([$teaserId]);
    $result = $stmt->fetch();
    $newViews = $result ? (int)$result['views'] : 0;
    
    echo json_encode([
        'success' => true,
        'views' => $newViews
    ]);
    
} catch (Exception $e) {
    error_log('Increment teaser views error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении счётчика просмотров.']);
}
