<?php
/**
 * term_sheet_txt.php
 * 
 * Endpoint для скачивания Term Sheet в формате TXT.
 * 
 * Функциональность:
 * - Загружает сгенерированный Term Sheet из базы данных
 * - Конвертирует HTML в чистый текст
 * - Отдает файл для скачивания в формате TXT
 * 
 * @package SmartBizSell
 */

require_once 'config.php';

/**
 * Проверка авторизации пользователя
 */
if (!isLoggedIn()) {
    http_response_code(401);
    die('Необходима авторизация.');
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    http_response_code(401);
    die('Необходима авторизация.');
}

/**
 * Получение данных Term Sheet из базы данных
 */
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT data_json, asset_name, created_at
        FROM term_sheet_forms 
        WHERE user_id = ? 
          AND status IN ('submitted','review','approved')
          AND data_json IS NOT NULL
          AND JSON_EXTRACT(data_json, '$.generated_document.content') IS NOT NULL
        ORDER BY submitted_at DESC, updated_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $form = $stmt->fetch();

    if (!$form) {
        http_response_code(404);
        die('Term Sheet не найден. Пожалуйста, создайте Term Sheet в личном кабинете.');
    }

    $termSheetData = json_decode($form['data_json'], true);
    if (empty($termSheetData['generated_document']['content'])) {
        http_response_code(404);
        die('Term Sheet не найден. Пожалуйста, создайте Term Sheet в личном кабинете.');
    }

    // Используем content (чистый текст) или html (если content отсутствует)
    $content = $termSheetData['generated_document']['content'] ?? $termSheetData['generated_document']['html'] ?? '';
    $assetName = $form['asset_name'] ?: 'Term_Sheet';
    
    // Конвертируем HTML в TXT, если контент в HTML формате
    $txtContent = convertHtmlToTxt($content);
    
    // Формируем имя файла
    $filename = sanitizeFilename($assetName) . '_Term_Sheet_' . date('Y-m-d') . '.txt';
    
    // Устанавливаем заголовки для скачивания
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . mb_strlen($txtContent, 'UTF-8'));
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Выводим содержимое
    echo $txtContent;
    exit;

} catch (PDOException $e) {
    error_log("Error fetching term sheet: " . $e->getMessage());
    http_response_code(500);
    die('Ошибка загрузки Term Sheet.');
}

/**
 * Конвертирует HTML в чистый текст
 * 
 * @param string $html HTML контент или чистый текст
 * @return string Текстовый контент
 */
function convertHtmlToTxt(string $html): string
{
    // Если это уже чистый текст (без HTML тегов), форматируем и возвращаем
    $stripped = strip_tags($html);
    if ($stripped === $html || empty(trim($html)) || !preg_match('/<[^>]+>/', $html)) {
        return formatPlainText($html);
    }
    
    // Удаляем HTML теги, но сохраняем структуру
    $text = $html;
    
    // Заменяем заголовки на текстовые эквиваленты
    $text = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n\n" . str_repeat('=', 60) . "\n$1\n" . str_repeat('=', 60) . "\n", $text);
    $text = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n\n" . str_repeat('-', 60) . "\n$1\n" . str_repeat('-', 60) . "\n", $text);
    $text = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n\n$1\n" . str_repeat('.', 40) . "\n", $text);
    
    // Заменяем параграфы на двойные переносы строк
    $text = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "\n\n$1", $text);
    
    // Заменяем <br> и <br/> на переносы строк
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    
    // Заменяем списки
    $text = preg_replace('/<ul[^>]*>/i', "\n", $text);
    $text = preg_replace('/<\/ul>/i', "\n", $text);
    $text = preg_replace('/<ol[^>]*>/i', "\n", $text);
    $text = preg_replace('/<\/ol>/i', "\n", $text);
    $text = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "  • $1\n", $text);
    
    // Удаляем все остальные HTML теги
    $text = strip_tags($text);
    
    // Декодируем HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Нормализуем пробелы и переносы строк
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);
    
    return formatPlainText($text);
}

/**
 * Форматирует чистый текст для TXT файла
 * 
 * @param string $text Исходный текст
 * @return string Отформатированный текст
 */
function formatPlainText(string $text): string
{
    // Добавляем заголовок документа
    $output = str_repeat('=', 70) . "\n";
    $output .= "TERM SHEET\n";
    $output .= "Лист условий сделки\n";
    $output .= str_repeat('=', 70) . "\n\n";
    
    // Добавляем основной контент
    $output .= $text;
    
    // Добавляем футер
    $output .= "\n\n" . str_repeat('-', 70) . "\n";
    $output .= "Документ создан автоматически на основе данных анкеты\n";
    $output .= "Дата создания: " . date('d.m.Y H:i') . "\n";
    $output .= str_repeat('-', 70) . "\n";
    
    return $output;
}

/**
 * Очищает имя файла от недопустимых символов
 * 
 * @param string $filename Имя файла
 * @return string Очищенное имя файла
 */
function sanitizeFilename(string $filename): string
{
    // Удаляем недопустимые символы
    $filename = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ_\-\s]/u', '', $filename);
    // Заменяем пробелы на подчеркивания
    $filename = preg_replace('/\s+/', '_', $filename);
    // Ограничиваем длину
    if (mb_strlen($filename, 'UTF-8') > 50) {
        $filename = mb_substr($filename, 0, 50, 'UTF-8');
    }
    return $filename;
}

