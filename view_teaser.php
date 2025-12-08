<?php
/**
 * Просмотр полного HTML тизера
 * 
 * Используется для загрузки полного HTML тизера в модальное окно на главной странице
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

$teaserId = isset($_GET['teaser_id']) ? (int)$_GET['teaser_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

if ($teaserId <= 0) {
    http_response_code(400);
    die('Не указан ID тизера.');
}

try {
    ensurePublishedTeasersTable();
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            pt.moderated_html,
            pt.moderation_status,
            sf.data_json
        FROM published_teasers pt
        INNER JOIN seller_forms sf ON pt.seller_form_id = sf.id
        WHERE pt.id = ? AND pt.moderation_status = 'published'
    ");
    $stmt->execute([$teaserId]);
    $teaser = $stmt->fetch();
    
    if (!$teaser) {
        http_response_code(404);
        die('Тизер не найден или не опубликован.');
    }
    
    /**
     * Приоритет отображения HTML тизера:
     * 1. moderated_html - отредактированная версия модератором (если есть)
     * 2. teaser_snapshot.html из data_json - оригинальная версия из генерации
     * 
     * Это позволяет показывать отредактированную версию тизера, если модератор
     * внес изменения, иначе показывается оригинальная версия.
     */
    $html = $teaser['moderated_html'];
    
    if (empty($html)) {
        // Извлекаем оригинальный HTML из data_json (fallback)
        $formData = json_decode($teaser['data_json'], true);
        if (is_array($formData) && !empty($formData['teaser_snapshot']['html'])) {
            $html = $formData['teaser_snapshot']['html'];
        }
    }
    
    if (empty($html)) {
        http_response_code(404);
        die('HTML тизера не найден.');
    }
    
    // Выводим HTML тизера (используется для загрузки в модальное окно на главной странице)
    echo $html;
    
} catch (PDOException $e) {
    error_log("Error loading teaser: " . $e->getMessage());
    http_response_code(500);
    die('Ошибка загрузки тизера.');
}

