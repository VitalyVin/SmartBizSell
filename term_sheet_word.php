<?php
/**
 * term_sheet_word.php
 * 
 * Endpoint для скачивания Term Sheet в формате Word (DOCX).
 * 
 * Функциональность:
 * - Загружает сгенерированный Term Sheet из базы данных
 * - Конвертирует контент в формат Word (DOCX)
 * - Отдает файл для скачивания в формате DOCX
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
          AND (
              JSON_EXTRACT(data_json, '$.generated_document.content') IS NOT NULL
              OR JSON_EXTRACT(data_json, '$.generated_document.html') IS NOT NULL
          )
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
    $content = $termSheetData['generated_document']['content'] ?? $termSheetData['generated_document']['html'] ?? '';
    
    if (empty($content)) {
        http_response_code(404);
        die('Term Sheet не найден. Пожалуйста, создайте Term Sheet в личном кабинете.');
    }

    $assetName = $form['asset_name'] ?: 'Term_Sheet';
    
    // Генерируем DOCX файл
    $docxContent = generateDocx($content);
    
    // Формируем имя файла
    $filename = sanitizeFilename($assetName) . '_Term_Sheet_' . date('Y-m-d') . '.docx';
    
    // Устанавливаем заголовки для скачивания
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($docxContent));
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    
    // Выводим содержимое
    echo $docxContent;
    exit;

} catch (PDOException $e) {
    error_log("Error fetching term sheet: " . $e->getMessage());
    http_response_code(500);
    die('Ошибка загрузки Term Sheet.');
} catch (Exception $e) {
    error_log("Error generating DOCX: " . $e->getMessage());
    http_response_code(500);
    die('Ошибка генерации Word документа.');
}

/**
 * Генерирует DOCX файл из текстового контента
 * 
 * @param string $content Текстовый или HTML контент
 * @return string Бинарное содержимое DOCX файла
 */
function generateDocx(string $content): string
{
    // Конвертируем HTML в чистый текст с сохранением структуры
    $text = convertHtmlToText($content);
    
    // Разбиваем на параграфы
    $paragraphs = explode("\n\n", $text);
    
    // Создаем временный файл для ZIP архива
    $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
    $zip = new ZipArchive();
    
    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        throw new Exception('Не удалось создать DOCX файл');
    }
    
    // Добавляем необходимые файлы структуры DOCX
    addDocxStructure($zip, $paragraphs);
    
    $zip->close();
    
    // Читаем содержимое файла
    $docxContent = file_get_contents($tempFile);
    
    // Удаляем временный файл
    unlink($tempFile);
    
    return $docxContent;
}

/**
 * Добавляет структуру DOCX файла в ZIP архив
 * 
 * @param ZipArchive $zip ZIP архив
 * @param array $paragraphs Массив параграфов
 */
function addDocxStructure(ZipArchive $zip, array $paragraphs): void
{
    // [Content_Types].xml
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
    <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    
    // _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);
    
    // word/_rels/document.xml.rels
    $wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $wordRels);
    
    // word/document.xml
    $document = generateDocumentXml($paragraphs);
    $zip->addFromString('word/document.xml', $document);
    
    // word/numbering.xml
    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:abstractNum w:abstractNumId="0">
        <w:multiLevelType w:val="singleLevel"/>
        <w:lvl w:ilvl="0">
            <w:start w:val="1"/>
            <w:numFmt w:val="bullet"/>
            <w:lvlText w:val="•"/>
            <w:lvlJc w:val="left"/>
            <w:pPr>
                <w:ind w:left="720" w:hanging="360"/>
            </w:pPr>
            <w:rPr>
                <w:rFonts w:ascii="Symbol" w:hAnsi="Symbol" w:eastAsia="Symbol" w:cs="Symbol" w:hint="default"/>
            </w:rPr>
        </w:lvl>
    </w:abstractNum>
    <w:num w:numId="1">
        <w:abstractNumId w:val="0"/>
    </w:num>
</w:numbering>';
    $zip->addFromString('word/numbering.xml', $numbering);
    
    // word/styles.xml
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
    <w:style w:type="paragraph" w:styleId="Title">
        <w:name w:val="Title"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
            <w:spacing w:after="240" w:line="276" w:lineRule="auto"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>
            <w:b/>
            <w:sz w:val="32"/>
            <w:szCs w:val="32"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading1">
        <w:name w:val="heading 1"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:spacing w:before="240" w:after="120"/>
            <w:outlineLvl w:val="0"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>
            <w:b/>
            <w:sz w:val="28"/>
            <w:szCs w:val="28"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Heading2">
        <w:name w:val="heading 2"/>
        <w:basedOn w:val="Normal"/>
        <w:next w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
            <w:keepNext/>
            <w:spacing w:before="240" w:after="120"/>
            <w:outlineLvl w:val="1"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>
            <w:b/>
            <w:sz w:val="24"/>
            <w:szCs w:val="24"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="Normal">
        <w:name w:val="Normal"/>
        <w:qFormat/>
        <w:pPr>
            <w:spacing w:after="200" w:line="276" w:lineRule="auto"/>
        </w:pPr>
        <w:rPr>
            <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:eastAsia="Calibri" w:cs="Calibri"/>
            <w:sz w:val="22"/>
            <w:szCs w:val="22"/>
        </w:rPr>
    </w:style>
    <w:style w:type="paragraph" w:styleId="ListParagraph">
        <w:name w:val="List Paragraph"/>
        <w:basedOn w:val="Normal"/>
        <w:uiPriority w:val="34"/>
        <w:qFormat/>
        <w:rsid w:val="00A97F34"/>
        <w:pPr>
            <w:ind w:left="720" w:hanging="360"/>
        </w:pPr>
    </w:style>
</w:styles>';
    $zip->addFromString('word/styles.xml', $styles);
}

/**
 * Генерирует XML содержимое документа Word
 * 
 * @param array $paragraphs Массив параграфов
 * @return string XML содержимое
 */
function generateDocumentXml(array $paragraphs): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <w:body>';
    
    // Заголовок документа
    $xml .= '
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Title"/>
                <w:jc w:val="center"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">TERM SHEET</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Normal"/>
                <w:jc w:val="center"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">Лист условий сделки</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Normal"/>
            </w:pPr>
        </w:p>';
    
    // Добавляем параграфы
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if (empty($para)) {
            continue;
        }
        
        // Определяем, является ли параграф заголовком
        $isHeading1 = preg_match('/^[А-ЯЁ][А-ЯЁ\s\d\.]+$/u', $para) && mb_strlen($para) < 100;
        $isHeading2 = preg_match('/^[А-ЯЁ][а-яё\s\d\.]+:$/u', $para) && mb_strlen($para) < 80;
        
        if ($isHeading1) {
            $xml .= '
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Heading1"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">' . escapeXml($para) . '</w:t>
            </w:r>
        </w:p>';
        } elseif ($isHeading2) {
            $xml .= '
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Heading2"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">' . escapeXml($para) . '</w:t>
            </w:r>
        </w:p>';
        } else {
            // Обычный параграф
            $lines = explode("\n", $para);
            $inList = false;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    if ($inList) {
                        // Завершаем список пустой строкой
                        $inList = false;
                    }
                    continue;
                }
                
                // Проверяем, является ли строка элементом списка
                if (preg_match('/^[•\-\*]\s*(.+)$/u', $line, $matches)) {
                    $xml .= '
        <w:p>
            <w:pPr>
                <w:numPr>
                    <w:ilvl w:val="0"/>
                    <w:numId w:val="1"/>
                </w:numPr>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">' . escapeXml($matches[1]) . '</w:t>
            </w:r>
        </w:p>';
                    $inList = true;
                } else {
                    if ($inList) {
                        // Завершаем список перед обычным параграфом
                        $inList = false;
                    }
                    $xml .= '
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Normal"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">' . escapeXml($line) . '</w:t>
            </w:r>
        </w:p>';
                }
            }
        }
    }
    
    // Футер
    $xml .= '
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Normal"/>
            </w:pPr>
        </w:p>
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Normal"/>
                <w:jc w:val="center"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">Документ создан автоматически на основе данных анкеты</w:t>
            </w:r>
        </w:p>
        <w:p>
            <w:pPr>
                <w:pStyle w:val="Normal"/>
                <w:jc w:val="center"/>
            </w:pPr>
            <w:r>
                <w:t xml:space="preserve">Дата создания: ' . date('d.m.Y H:i') . '</w:t>
            </w:r>
        </w:p>';
    
    $xml .= '
    </w:body>
</w:document>';
    
    return $xml;
}

/**
 * Конвертирует HTML в текст с сохранением структуры
 * 
 * @param string $html HTML контент или чистый текст
 * @return string Текстовый контент
 */
function convertHtmlToText(string $html): string
{
    // Если это уже чистый текст (без HTML тегов), возвращаем как есть
    $stripped = strip_tags($html);
    if ($stripped === $html || empty(trim($html)) || !preg_match('/<[^>]+>/', $html)) {
        return $html;
    }
    
    // Удаляем HTML теги, но сохраняем структуру
    $text = $html;
    
    // Заменяем заголовки
    $text = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "\n\n$1\n\n", $text);
    $text = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "\n\n$1\n\n", $text);
    $text = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "\n\n$1\n\n", $text);
    
    // Заменяем параграфы на двойные переносы строк
    $text = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "\n\n$1", $text);
    
    // Заменяем <br> и <br/> на переносы строк
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    
    // Заменяем списки
    $text = preg_replace('/<ul[^>]*>/i', "\n", $text);
    $text = preg_replace('/<\/ul>/i', "\n", $text);
    $text = preg_replace('/<ol[^>]*>/i', "\n", $text);
    $text = preg_replace('/<\/ol>/i', "\n", $text);
    $text = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "• $1\n", $text);
    
    // Удаляем все остальные HTML теги
    $text = strip_tags($text);
    
    // Декодируем HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Нормализуем пробелы и переносы строк
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);
    
    return $text;
}

/**
 * Экранирует XML специальные символы
 * 
 * @param string $text Текст для экранирования
 * @return string Экранированный текст
 */
function escapeXml(string $text): string
{
    $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return $text;
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

