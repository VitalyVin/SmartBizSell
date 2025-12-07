<?php
/**
 * API для отправки тизера на модерацию
 * 
 * Используется продавцами для отправки сгенерированного тизера на модерацию
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    exit;
}

// Создаем таблицу, если её нет
ensurePublishedTeasersTable();

$user = getCurrentUser();
$pdo = getDBConnection();

// Получаем form_id из запроса
$requestData = json_decode(file_get_contents('php://input'), true);
$formId = isset($requestData['form_id']) ? (int)$requestData['form_id'] : null;

if (!$formId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID анкеты.']);
    exit;
}

try {
    // Проверяем, что анкета принадлежит текущему пользователю
    $stmt = $pdo->prepare("SELECT id, data_json, status FROM seller_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$formId, $user['id']]);
    $form = $stmt->fetch();
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Анкета не найдена.']);
        exit;
    }
    
    // Проверяем, что тизер сгенерирован
    $formData = json_decode($form['data_json'], true);
    if (empty($formData['teaser_snapshot']['html'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Тизер не сгенерирован. Сначала создайте тизер.']);
        exit;
    }
    
    // Получаем HTML тизера из snapshot
    $teaserHtml = $formData['teaser_snapshot']['html'] ?? '';
    $heroDescription = $formData['teaser_snapshot']['hero_description'] ?? '';
    
    // Проверяем, существует ли уже запись в published_teasers
    $stmt = $pdo->prepare("SELECT id, moderation_status FROM published_teasers WHERE seller_form_id = ?");
    $stmt->execute([$formId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Обновляем существующую запись, сбрасывая статус на pending
        // Это позволяет повторно отправить тизер на модерацию, даже если он был опубликован
        $stmt = $pdo->prepare("
            UPDATE published_teasers 
            SET 
                moderated_html = ?,
                moderation_status = 'pending',
                moderation_notes = NULL,
                moderated_at = NULL,
                published_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$teaserHtml, $existing['id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Тизер отправлен на модерацию повторно. Старый тизер будет заменен после публикации.',
            'teaser_id' => $existing['id']
        ]);
    } else {
        // Создаем новую запись
        $stmt = $pdo->prepare("
            INSERT INTO published_teasers 
            (seller_form_id, moderated_html, moderation_status, created_at, updated_at)
            VALUES (?, ?, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$formId, $teaserHtml]);
        $teaserId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Тизер отправлен на модерацию.',
            'teaser_id' => $teaserId
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error submitting teaser for moderation: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при отправке тизера на модерацию.'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

