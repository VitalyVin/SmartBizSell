<?php
/**
 * API endpoint для установки выбранного провайдера AI
 * 
 * Сохраняет выбор провайдера в сессию пользователя
 * Доступно только для модераторов
 */

require_once 'config.php';

// Проверяем авторизацию и права модератора
if (!isLoggedIn() || !isModerator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не разрешен']);
    exit;
}

// Получаем данные из запроса
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['provider'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан провайдер']);
    exit;
}

$provider = trim($input['provider']);

// Валидация провайдера
if (!in_array($provider, ['together', 'alibaba'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Неверный провайдер. Допустимые значения: together, alibaba']);
    exit;
}

// Устанавливаем провайдера в сессию
if (setAIProvider($provider)) {
    echo json_encode([
        'success' => true,
        'message' => 'Провайдер успешно установлен',
        'provider' => $provider,
        'provider_name' => $provider === 'alibaba' ? 'Alibaba Cloud Qwen 3 Max' : 'Together.ai'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка при установке провайдера']);
}

