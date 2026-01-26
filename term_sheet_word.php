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
    $filename = sanitizeFileName($assetName) . '_Term_Sheet_' . date('Y-m-d') . '.docx';
    
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
    // (заголовки, параграфы, списки)
    $text = convertHtmlToText($content);
    
    // Разбиваем на параграфы по двойным переносам строк
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
 * Добавляет структуру DOCX файла в ZIP архив.
 * 
 * DOCX файл состоит из нескольких XML файлов, упакованных в ZIP:
 * 1. [Content_Types].xml - описывает типы всех файлов в архиве
 * 2. _rels/.rels - связи между основными частями документа
 * 3. word/document.xml - основной контент документа (текст, параграфы, заголовки)
 * 4. word/styles.xml - стили документа (заголовки, обычный текст, списки)
 * 5. word/numbering.xml - определение нумерации для списков
 * 6. word/_rels/document.xml.rels - связи документа (со стилями и нумерацией)
 * 
 * @param ZipArchive $zip ZIP архив, в который добавляются файлы
 * @param array $paragraphs Массив параграфов текста для включения в document.xml
 */
function addDocxStructure(ZipArchive $zip, array $paragraphs): void
{
    // [Content_Types].xml - обязательный файл, описывающий типы содержимого
    // Этот файл определяет MIME-типы всех файлов в DOCX архиве
    // Указывает, что document.xml - это основной документ Word,
    // styles.xml - стили, numbering.xml - нумерация
    // Без этого файла Word не сможет правильно открыть документ
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
    <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
    <Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>
</Types>';
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    
    // _rels/.rels - корневые связи документа
    // Определяет связь между корнем архива и основным документом Word
    // Указывает, что word/document.xml является основным документом
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);
    
    // word/_rels/document.xml.rels - связи документа Word
    // Определяет связи между document.xml и другими частями документа:
    // - styles.xml (стили форматирования)
    // - numbering.xml (определения нумерации для списков)
    $wordRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>
</Relationships>';
    $zip->addFromString('word/_rels/document.xml.rels', $wordRels);
    
    // word/document.xml - основной контент документа
    // Содержит весь текст, параграфы, заголовки, таблицы в формате WordprocessingML
    // Генерируется функцией generateDocumentXml на основе массива параграфов
    $document = generateDocumentXml($paragraphs);
    $zip->addFromString('word/document.xml', $document);
    
    // word/numbering.xml - определение нумерации для списков
    // abstractNumId="0" - абстрактное определение нумерации (шаблон)
    // numId="1" - конкретная нумерация, использующая шаблон 0
    // Используется для маркированных списков (bullet) с символом "•"
    // Связано с document.xml через word/_rels/document.xml.rels
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
 * Генерирует XML содержимое основного документа Word (document.xml) в виде таблицы.
 * 
 * Форматирует документ как таблицу с двумя колонками:
 * - Левая колонка (25% ширины) - название пункта
 * - Правая колонка (75% ширины) - содержание пункта
 * 
 * Преобразует массив параграфов в XML структуру Word с:
 * - Заголовком документа (Term Sheet)
 * - Таблицей с разделами и их содержанием
 * - Элементами списков с правильной нумерацией
 * - Футером с датой создания
 * 
 * Автоматически определяет тип контента:
 * - H1: строки из заглавных букв длиной до 100 символов (название пункта)
 * - H2: строки, заканчивающиеся на ":" длиной до 80 символов (подзаголовок)
 * - Списки: строки, начинающиеся с "•", "-" или "*"
 * 
 * @param array $paragraphs Массив параграфов текста
 * @return string XML содержимое для word/document.xml
 */
function generateDocumentXml(array $paragraphs): string
{
    // Начало XML документа с пространствами имен Word
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <w:body>';
    
    // Заголовок документа (Term Sheet) с центрированием
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
    
    // Начинаем таблицу с двумя колонками для форматирования Term Sheet
    // Ширина таблицы оптимизирована для A4: 10000 twips (около 17.6 см) с отступом справа
    // Левая колонка: 2500 twips (25%) - название пункта
    // Правая колонка: 7500 twips (75%) - содержание пункта
    // Twips - единица измерения в Word (1 twip = 1/20 точки = 1/1440 дюйма)
    $xml .= '
        <w:tbl>
            <w:tblPr>
                <w:tblW w:w="10000" w:type="dxa"/>
                <w:tblBorders>
                    <w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>
                    <w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>
                    <w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>
                    <w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>
                    <w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>
                    <w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>
                </w:tblBorders>
            </w:tblPr>
            <w:tblGrid>
                <w:gridCol w:w="2500"/>
                <w:gridCol w:w="7500"/>
            </w:tblGrid>';
    
    // Переменные для накопления данных текущего раздела
    // $currentSectionTitle - название раздела (будет в левой колонке таблицы)
    // $currentSectionContent - массив XML-элементов с содержимым раздела (будет в правой колонке)
    $currentSectionTitle = '';
    $currentSectionContent = [];
    
    // Обрабатываем параграфы из контента и группируем их по разделам
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if (empty($para)) {
            continue; // Пропускаем пустые параграфы
        }
        
        // Определяем тип параграфа по паттернам:
        // H1: строка из заглавных русских букв, цифр, пробелов и точек (до 100 символов)
        // Такие заголовки становятся названиями пунктов в левой колонке таблицы
        $isHeading1 = preg_match('/^[А-ЯЁ][А-ЯЁ\s\d\.]+$/u', $para) && mb_strlen($para) < 100;
        
        if ($isHeading1) {
            // Встретили новый заголовок раздела
            // Если у нас уже есть накопленное содержимое предыдущего раздела, выводим его в таблицу
            if (!empty($currentSectionTitle) && !empty($currentSectionContent)) {
                $xml .= generateTableRow($currentSectionTitle, implode('', $currentSectionContent));
            }
            
            // Начинаем новый раздел: сохраняем заголовок и очищаем содержимое
            $currentSectionTitle = $para;
            $currentSectionContent = [];
        } else {
            // Обычный параграф или подзаголовок - это содержимое текущего раздела
            // Разбиваем на строки для обработки различных типов контента (подзаголовки, списки, обычный текст)
            $lines = explode("\n", $para);
            $inList = false; // Флаг для отслеживания состояния списка (для правильного форматирования)
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    if ($inList) {
                        // Завершаем список при пустой строке
                        $inList = false;
                    }
                    continue;
                }
                
                // Проверяем, является ли это подзаголовком второго уровня (H2)
                // Паттерн: строка, начинающаяся с заглавной буквы и заканчивающаяся на ":" (до 80 символов)
                $isHeading2 = preg_match('/^[А-ЯЁ][а-яё\s\d\.]+:$/u', $line) && mb_strlen($line) < 80;
                
                if ($isHeading2) {
                    // Подзаголовок второго уровня - форматируем как Heading2
                    $currentSectionContent[] = '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t xml:space="preserve">' . escapeXml($line) . '</w:t></w:r></w:p>';
                } elseif (preg_match('/^[•\-\*]\s*(.+)$/u', $line, $matches)) {
                    // Элемент списка - форматируем с использованием нумерации Word
                    // Используем стиль ListParagraph с нумерацией (numId="1" из numbering.xml)
                    $currentSectionContent[] = '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t xml:space="preserve">' . escapeXml($matches[1]) . '</w:t></w:r></w:p>';
                    $inList = true;
                } else {
                    if ($inList) {
                        // Завершаем список перед обычным параграфом
                        $inList = false;
                    }
                    // Обычный параграф - форматируем как Normal
                    $currentSectionContent[] = '<w:p><w:pPr><w:pStyle w:val="Normal"/></w:pPr><w:r><w:t xml:space="preserve">' . escapeXml($line) . '</w:t></w:r></w:p>';
                }
            }
        }
    }
    
    // Выводим последний раздел, если он есть
    // Это необходимо, так как последний раздел не будет обработан циклом (нет следующего заголовка)
    if (!empty($currentSectionTitle) && !empty($currentSectionContent)) {
        $xml .= generateTableRow($currentSectionTitle, implode('', $currentSectionContent));
    }
    
    // Закрываем таблицу
    $xml .= '
        </w:tbl>';
    
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
 * Генерирует строку таблицы Word с двумя колонками.
 * 
 * @param string $title Название пункта (левая колонка, 25%)
 * @param string $content Содержание пункта (правая колонка, 75%)
 * @return string XML код строки таблицы
 */
function generateTableRow(string $title, string $content): string
{
    // Ширина колонок соответствует tblGrid: левая 2500 twips (25%), правая 7500 twips (75%)
    return '
            <w:tr>
                <w:tc>
                    <w:tcPr>
                        <w:tcW w:w="2500" w:type="dxa"/>
                        <w:vAlign w:val="top"/>
                    </w:tcPr>
                    <w:p>
                        <w:pPr>
                            <w:pStyle w:val="Normal"/>
                        </w:pPr>
                        <w:r>
                            <w:rPr>
                                <w:b/>
                            </w:rPr>
                            <w:t xml:space="preserve">' . escapeXml($title) . '</w:t>
                        </w:r>
                    </w:p>
                </w:tc>
                <w:tc>
                    <w:tcPr>
                        <w:tcW w:w="7500" w:type="dxa"/>
                        <w:vAlign w:val="top"/>
                    </w:tcPr>
                    ' . $content . '
                </w:tc>
            </w:tr>';
}

/**
 * Конвертирует HTML в текст с сохранением структуры.
 * 
 * Преобразует HTML теги в текстовые эквиваленты:
 * - <h1>, <h2>, <h3> -> двойные переносы строк
 * - <p> -> двойные переносы строк
 * - <br> -> одинарные переносы строк
 * - <li> -> маркеры списка "•"
 * 
 * @param string $html HTML контент или чистый текст
 * @return string Текстовый контент с сохраненной структурой
 */
function convertHtmlToText(string $html): string
{
    // Если это уже чистый текст (без HTML тегов), возвращаем как есть
    $stripped = strip_tags($html);
    if ($stripped === $html || empty(trim($html)) || !preg_match('/<[^>]+>/', $html)) {
        return $html;
    }
    
    // Удаляем HTML теги, но сохраняем структуру через замену тегов на переносы строк
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
 * Экранирует XML специальные символы для безопасной вставки в XML.
 * 
 * Заменяет специальные символы XML (&, <, >, ", ') на их XML-сущности,
 * чтобы избежать ошибок парсинга XML.
 * 
 * @param string $text Текст для экранирования
 * @return string Экранированный текст, безопасный для использования в XML
 */
function escapeXml(string $text): string
{
    $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return $text;
}



