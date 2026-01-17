<?php
/**
 * API для сохранения отредактированного HTML тизера
 * 
 * Используется продавцами для сохранения изменений в тизере после редактирования
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

$user = getCurrentUser();
$pdo = getDBConnection();

// Получаем данные из запроса
$requestData = json_decode(file_get_contents('php://input'), true);
$formId = isset($requestData['form_id']) ? (int)$requestData['form_id'] : null;
$editedHtml = isset($requestData['html']) ? (string)$requestData['html'] : null;

if (!$formId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Не указан ID анкеты.']);
    exit;
}

if (!$editedHtml || trim($editedHtml) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'HTML тизера не может быть пустым.']);
    exit;
}

try {
    // Проверяем, что анкета принадлежит текущему пользователю
    $effectiveUserId = getEffectiveUserId();
    $stmt = $pdo->prepare("SELECT id, data_json FROM seller_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$formId, $effectiveUserId]);
    $form = $stmt->fetch();
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Анкета не найдена.']);
        exit;
    }
    
    // Получаем текущий data_json
    $formData = json_decode($form['data_json'], true);
    if (!is_array($formData)) {
        $formData = [];
    }
    
    // Проверяем, что тизер был сгенерирован
    if (empty($formData['teaser_snapshot'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Тизер не сгенерирован. Сначала создайте тизер.']);
        exit;
    }
    
    // Санитизация HTML
    $sanitizedHtml = sanitizeTeaserHtml($editedHtml);
    
    // Обновляем HTML в snapshot
    $formData['teaser_snapshot']['html'] = $sanitizedHtml;
    $formData['teaser_snapshot']['edited_at'] = date('c');
    $formData['teaser_snapshot']['edited_by_user'] = true;
    
    // Сохраняем обратно в БД
    $json = json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new Exception('Ошибка при кодировании данных в JSON.');
    }
    
    $stmt = $pdo->prepare("UPDATE seller_forms SET data_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$json, $formId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Изменения сохранены успешно.'
    ]);
    
} catch (PDOException $e) {
    error_log("Error saving teaser edits: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при сохранении изменений.'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Санитизирует HTML тизера, удаляя опасные теги и атрибуты
 * 
 * Разрешает только безопасные HTML теги, используемые в тизере:
 * - Структурные: div, p, h3, ul, li, span, strong, em, br
 * - Стили: классы и data-атрибуты для работы графиков
 * 
 * @param string $html Исходный HTML
 * @return string Санитизированный HTML
 */
function sanitizeTeaserHtml(string $html): string
{
    // Используем DOMDocument для парсинга и очистки HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    
    // Добавляем UTF-8 мета-тег для правильной обработки кириллицы
    $html = '<?xml encoding="UTF-8">' . $html;
    
    // Загружаем HTML
    @$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    
    // Очищаем ошибки парсинга
    libxml_clear_errors();
    
    // Разрешенные теги
    $allowedTags = [
        'div', 'p', 'h3', 'ul', 'li', 'span', 'strong', 'em', 'br',
        'small', 'a' // Добавляем a для ссылок, если они есть
    ];
    
    // Разрешенные атрибуты
    $allowedAttributes = [
        'class', 'id', 'data-chart', 'data-chart-id', 'data-chart-ready',
        'data-variant', 'aria-hidden', 'href', 'target', 'style' // style для графиков ApexCharts
    ];
    
    // Функция для рекурсивной очистки элементов
    $cleanNode = function($node) use (&$cleanNode, $allowedTags, $allowedAttributes) {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tagName = strtolower($node->nodeName);
            
            // Специальная обработка для контейнеров графиков - сохраняем их полностью
            $isChartContainer = false;
            if ($node->hasAttribute('data-chart') || 
                ($node->hasAttribute('class') && stripos($node->getAttribute('class'), 'teaser-chart') !== false)) {
                $isChartContainer = true;
            }
            
            // Если это контейнер графика, сохраняем его как есть (но проверяем дочерние элементы)
            if ($isChartContainer && $tagName === 'div') {
                // Обрабатываем дочерние элементы, но не удаляем сам контейнер
                $children = [];
                foreach ($node->childNodes as $child) {
                    $children[] = $child;
                }
                foreach ($children as $child) {
                    $cleanNode($child);
                }
                return;
            }
            
            // Если тег не разрешен, заменяем его содержимым
            if (!in_array($tagName, $allowedTags)) {
                // Создаем фрагмент с содержимым
                $fragment = $node->ownerDocument->createDocumentFragment();
                while ($node->firstChild) {
                    $fragment->appendChild($node->firstChild);
                }
                if ($node->parentNode) {
                    $node->parentNode->replaceChild($fragment, $node);
                }
                return;
            }
            
            // Удаляем неразрешенные атрибуты (кроме важных для графиков)
            $attributesToRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->nodeName);
                // Сохраняем все data-* атрибуты для графиков
                if (strpos($attrName, 'data-') === 0) {
                    continue; // Пропускаем все data-* атрибуты
                }
                if (!in_array($attrName, $allowedAttributes)) {
                    $attributesToRemove[] = $attr;
                }
            }
            foreach ($attributesToRemove as $attr) {
                $node->removeAttributeNode($attr);
            }
            
            // Обрабатываем дочерние элементы
            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                $cleanNode($child);
            }
        } elseif ($node->nodeType === XML_TEXT_NODE) {
            // Текстовые узлы оставляем как есть
            return;
        }
    };
    
    // Очищаем body (DOMDocument автоматически добавляет html и body)
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body) {
        $children = [];
        foreach ($body->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            $cleanNode($child);
        }
    }
    
    // Извлекаем очищенный HTML
    if ($body) {
        $cleanedHtml = '';
        foreach ($body->childNodes as $node) {
            $cleanedHtml .= $dom->saveHTML($node);
        }
    } else {
        $cleanedHtml = $html; // Fallback, если не удалось распарсить
    }
    
    // Удаляем лишние пробелы и переносы строк (опционально)
    $cleanedHtml = preg_replace('/\s+/', ' ', $cleanedHtml);
    $cleanedHtml = preg_replace('/>\s+</', '><', $cleanedHtml);
    
    return trim($cleanedHtml);
}
