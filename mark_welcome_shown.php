<?php
/**
 * API endpoint для сохранения флага показа приветственного окна
 * 
 * Устанавливает welcome_shown = 1 для текущего пользователя
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

header('Content-Type: application/json');

// Проверка авторизации
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// Проверяем и добавляем поле welcome_shown, если его нет
ensureUsersWelcomeField();

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE users SET welcome_shown = 1 WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Error marking welcome_shown: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

