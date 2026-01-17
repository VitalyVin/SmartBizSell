<?php
/**
 * Endpoint для удаления черновика анкеты продавца
 * 
 * Проверяет:
 * - Авторизацию пользователя
 * - Принадлежность формы пользователю
 * - Статус формы (должен быть 'draft')
 * 
 * @package SmartBizSell
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Требуется авторизация'
    ]);
    exit;
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не разрешен'
    ]);
    exit;
}

// Получение данных из запроса
$input = json_decode(file_get_contents('php://input'), true);
$formId = isset($input['form_id']) ? (int)$input['form_id'] : null;

if (!$formId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Не указан ID формы'
    ]);
    exit;
}

$pdo = getDBConnection();
$effectiveUserId = getEffectiveUserId();

try {
    // Проверяем существование формы и её принадлежность пользователю
    $stmt = $pdo->prepare("SELECT id, status, user_id FROM seller_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$formId, $effectiveUserId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$form) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Форма не найдена или у вас нет прав на её удаление'
        ]);
        exit;
    }
    
    // Проверяем, что форма является черновиком
    if ($form['status'] !== 'draft') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Можно удалять только черновики. Отправленные формы нельзя удалить.'
        ]);
        exit;
    }
    
    // Удаляем форму
    $stmt = $pdo->prepare("DELETE FROM seller_forms WHERE id = ? AND user_id = ? AND status = 'draft'");
    $stmt->execute([$formId, $effectiveUserId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Черновик успешно удален'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось удалить черновик'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error deleting draft: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сервера при удалении черновика'
    ]);
}
