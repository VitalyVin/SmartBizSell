<?php
/**
 * Endpoint для выхода из режима impersonation
 * 
 * Очищает impersonate_user_id из сессии и редиректит модератора
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверка авторизации и прав модератора
if (!isLoggedIn()) {
    redirectToLogin();
}

if (!isModerator()) {
    http_response_code(403);
    die('Доступ запрещен.');
}

// Очищаем impersonation
clearImpersonation();

// Редирект на страницу модерации или дашборд
$redirectTo = $_GET['redirect'] ?? 'moderation.php';
if (!in_array($redirectTo, ['moderation.php', 'dashboard.php', 'users_list.php'])) {
    $redirectTo = 'moderation.php';
}

header('Location: ' . $redirectTo);
exit;
