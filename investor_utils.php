<?php
/**
 * Набор вспомогательных функций для подбора и отображения инвесторов
 * 
 * Функциональность:
 * - Загрузка каталога инвесторов из Excel-файла (RAG - Retrieval Augmented Generation)
 * - Ранжирование инвесторов по релевантности на основе данных анкеты продавца
 * - Генерация рекомендаций инвесторов через ИИ (Together.ai)
 * - Объединение рекомендаций из каталога и ИИ в единый пул
 * - Отображение инвесторов в виде карточек на странице тизера
 * 
 * Архитектура:
 * - Использует гибридный подход: каталог инвесторов + AI-рекомендации
 * - Каталог загружается из Excel-файла rag_investors.xlsx
 * - Ранжирование основано на совпадении ключевых слов из анкеты с профилем инвестора
 * - AI-рекомендации дополняют каталог новыми инвесторами, которых нет в базе
 * 
 * @package SmartBizSell
 * @version 1.0
 */

/**
 * Рендерит секцию с инвесторами для отображения на странице тизера
 * 
 * Создает HTML-разметку секции с заголовком, описанием и сеткой карточек инвесторов.
 * Отображает количество инвесторов (например, "5 из 10").
 * 
 * @param array $investors Массив инвесторов для отображения
 * @return string HTML-код секции с инвесторами
 */
function renderInvestorSection(array $investors): string
{
    $cards = array_map('renderInvestorCard', $investors);
    $headline = '<div class="investor-section__intro">'
        . '<div>'
        . '<h3>Возможные инвесторы</h3>'
        . '<p>Комбинация релевантных контактов из базы SmartBizSell и свежих рекомендаций AI.</p>'
        . '</div>'
        . '<span class="investor-section__count">' . count($investors) . ' из 10</span>'
        . '</div>';

    return '<section class="investor-section">' . $headline . '<div class="investor-grid">' . implode('', $cards) . '</div></section>';
}

/**
 * Рендерит карточку одного инвестора
 * 
 * Создает HTML-карточку с информацией об инвесторе:
 * - Название инвестора
 * - Область интересов (focus)
 * - Целевой чек (check) - диапазон инвестиций
 * - Причина релевантности (reason)
 * - Бейдж "AI рекомендация" для инвесторов, предложенных ИИ
 * - Кнопка "Отправить тизер"
 * 
 * @param array $investor Массив с данными инвестора:
 *   - name: название инвестора
 *   - focus: область интересов
 *   - check: целевой чек (диапазон инвестиций)
 *   - reason: причина релевантности
 *   - source: источник ('catalog' или 'ai')
 * @return string HTML-код карточки инвестора
 */
function renderInvestorCard(array $investor): string
{
    // Экранируем все данные для защиты от XSS
    $name = escapeHtml($investor['name'] ?? 'Инвестор');
    $focus = escapeHtml($investor['focus'] ?? 'Область интересов уточняется');
    $check = escapeHtml($investor['check'] ?? '');
    $reason = escapeHtml($investor['reason'] ?? '');
    $source = $investor['source'] ?? 'catalog';
    
    // Добавляем бейдж для AI-рекомендаций
    $badge = $source === 'ai' ? '<span class="investor-card__badge">AI рекомендация</span>' : '';
    
    // Формируем HTML для целевого чека (если указан)
    $checkHtml = $check !== '' ? '<p class="investor-card__check">Целевой чек: ' . $check . '</p>' : '';
    
    // Формируем HTML для причины релевантности (если указана)
    $reasonHtml = $reason !== '' ? '<p class="investor-card__reason">' . $reason . '</p>' : '';

    // Кнопка для отправки тизера инвестору
    $button = '<button type="button" class="btn btn-investor-send" data-investor="' . $name . '">Отправить тизер</button>';

    return <<<HTML
<div class="investor-card" data-source="{$source}">
    <div class="investor-card__head">
        <div>
            <h4>{$name}</h4>
            {$badge}
        </div>
    </div>
    <p class="investor-card__focus">{$focus}</p>
    {$checkHtml}
    {$reasonHtml}
    <div class="investor-card__actions">
        {$button}
    </div>
</div>
HTML;
}

/**
 * Формирует пул инвесторов для отображения на странице тизера
 * 
 * Алгоритм:
 * 1. Загружает каталог инвесторов из Excel-файла
 * 2. Ранжирует инвесторов из каталога по релевантности
 * 3. Выбирает топ-6 инвесторов из каталога
 * 4. Запрашивает 4 AI-рекомендации (новых инвесторов, которых нет в каталоге)
 * 5. Объединяет рекомендации, удаляя дубликаты
 * 6. Возвращает до 10 уникальных инвесторов
 * 
 * @param array $payload Данные анкеты продавца для анализа релевантности
 * @param string $apiKey API-ключ для Together.ai (для AI-рекомендаций)
 * @return array Массив инвесторов (до 10 элементов) с полями: name, focus, check, reason, source
 */
function buildInvestorPool(array $payload, string $apiKey): array
{
    // Загружаем каталог инвесторов из Excel-файла
    $catalogPath = __DIR__ . '/rag_investors.xlsx';
    $catalog = loadRagInvestors($catalogPath);
    if (empty($catalog)) {
        return [];
    }

    // Ранжируем инвесторов из каталога по релевантности
    // Ранжирование основано на совпадении ключевых слов из анкеты с профилем инвестора
    $ranked = rankInvestorsByRelevance($catalog, $payload);
    
    // Выбираем топ-6 инвесторов из каталога
    $selected = array_slice($ranked, 0, 6);
    
    // Запрашиваем AI-рекомендации (новых инвесторов, которых нет в каталоге)
    $aiSuggestions = requestAiInvestorSuggestions($payload, $catalog, $apiKey, 4);

    // Объединяем рекомендации из каталога и AI
    $combined = array_merge($selected, $aiSuggestions);
    
    // Удаляем дубликаты по названию (без учета регистра)
    $unique = [];
    $seen = [];
    foreach ($combined as $row) {
        $name = mb_strtolower(trim($row['name'] ?? ''));
        if ($name === '' || isset($seen[$name])) {
            continue;  // Пропускаем пустые названия и дубликаты
        }
        $seen[$name] = true;
        unset($row['score']);  // Удаляем служебное поле score
        $unique[] = $row;
        if (count($unique) >= 10) {
            break;  // Ограничиваем пул 10 инвесторами
        }
    }

    return $unique;
}

/**
 * Ранжирует инвесторов по релевантности на основе данных анкеты продавца
 * 
 * Алгоритм ранжирования:
 * 1. Извлекает ключевые слова из анкеты (название актива, продукция, описание и т.д.)
 * 2. Для каждого инвестора проверяет совпадение ключевых слов с его профилем
 * 3. Начисляет баллы:
 *    - +3 балла за каждое совпадение ключевого слова
 *    - +1 балл, если указана область интересов (focus)
 *    - +0.5 балла, если указан целевой чек (check)
 *    - +2 балла за совпадение отрасли (products_services) с областью интересов
 * 4. Сортирует инвесторов по убыванию баллов
 * 
 * @param array $investors Массив инвесторов из каталога
 * @param array $payload Данные анкеты продавца
 * @return array Отсортированный массив инвесторов с полем 'score' (балл релевантности)
 */
function rankInvestorsByRelevance(array $investors, array $payload): array
{
    // Извлекаем ключевые слова из анкеты для поиска совпадений
    $keywords = buildAssetKeywords($payload);
    $results = [];

    foreach ($investors as $item) {
        $name = trim($item['name'] ?? '');
        if ($name === '') {
            continue;  // Пропускаем инвесторов без названия
        }
        $focus = trim($item['focus'] ?? '');
        $check = trim($item['check'] ?? '');

        // Объединяем название, область интересов и чек в одну строку для поиска
        $haystack = mb_strtolower($name . ' ' . $focus . ' ' . $check);
        $score = 0;
        $matched = [];

        // Проверяем совпадение ключевых слов
        foreach ($keywords as $keyword) {
            if ($keyword === '' || mb_strlen($keyword) < 3) {
                continue;  // Пропускаем слишком короткие ключевые слова
            }
            if (str_contains($haystack, $keyword)) {
                $score += 3;  // +3 балла за каждое совпадение
                $matched[] = $keyword;
            }
        }

        // Бонусные баллы за наличие данных
        if ($focus !== '') {
            $score += 1;  // +1 балл за указанную область интересов
        }
        if ($check !== '') {
            $score += 0.5;  // +0.5 балла за указанный целевой чек
        }

        // Дополнительные баллы за совпадение отрасли
        $industry = mb_strtolower(trim((string)($payload['products_services'] ?? '')));
        if ($industry !== '' && str_contains(mb_strtolower($focus), $industry)) {
            $score += 2;  // +2 балла за совпадение отрасли
        }

        // Сохраняем результат с баллом релевантности
        $results[] = [
            'source' => 'catalog',
            'name' => $name,
            'focus' => $focus,
            'check' => $check,
            'reason' => formatInvestorReason($focus, $check, $matched, $payload),
            'score' => $score,
        ];
    }

    // Сортируем по убыванию баллов, при равных баллах - по алфавиту
    usort($results, static function ($a, $b) {
        return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']);
    });

    return $results;
}

/**
 * Извлекает ключевые слова из данных анкеты продавца
 * 
 * Процесс:
 * 1. Собирает текстовые поля из анкеты (название актива, продукция, описание и т.д.)
 * 2. Разбивает текст на слова (разделители: пробелы, знаки препинания)
 * 3. Фильтрует стоп-слова (общие слова, не несущие смысловой нагрузки)
 * 4. Удаляет слишком короткие слова (менее 3 символов)
 * 5. Удаляет дубликаты
 * 
 * @param array $payload Данные анкеты продавца
 * @return array Массив уникальных ключевых слов для поиска совпадений
 */
function buildAssetKeywords(array $payload): array
{
    // Список полей анкеты, из которых извлекаются ключевые слова
    $fields = [
        $payload['asset_name'] ?? '',
        $payload['products_services'] ?? '',
        $payload['company_description'] ?? '',
        $payload['presence_regions'] ?? '',
        $payload['additional_info'] ?? '',
        $payload['deal_goal'] ?? '',
        $payload['industry'] ?? '',
    ];

    // Список стоп-слов, которые не учитываются при поиске
    // Это общие слова, не несущие смысловой нагрузки для определения релевантности
    $stopWords = [
        'компания','бизнес','продажа','рост','рынок','сектор','сегмент','команда','клиент',
        'инвестиции','инвестор','группа','россия','rf','поддержка','услуги','продукт','решение',
        'сделка','капитал','логистика','работа','новый','текущий','развитие','масштабирование'
    ];
    
    $keywords = [];
    foreach ($fields as $field) {
        // Разбиваем текст на слова (разделители: любые не-буквенно-цифровые символы)
        $words = preg_split('/[^а-яa-z0-9]+/iu', mb_strtolower((string)$field));
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || mb_strlen($word) < 3) {
                continue;  // Пропускаем пустые и слишком короткие слова
            }
            if (in_array($word, $stopWords, true)) {
                continue;  // Пропускаем стоп-слова
            }
            $keywords[] = $word;
        }
    }

    // Удаляем дубликаты и возвращаем массив
    return array_values(array_unique($keywords));
}

/**
 * Запрашивает рекомендации инвесторов через ИИ (Together.ai)
 * 
 * Процесс:
 * 1. Формирует промпт с описанием компании и каталогом существующих инвесторов
 * 2. Запрашивает у ИИ новых инвесторов, которых нет в каталоге
 * 3. Парсит JSON-ответ от ИИ
 * 4. Фильтрует дубликаты (исключает инвесторов, уже присутствующих в каталоге)
 * 5. Возвращает массив новых инвесторов с пометкой source='ai'
 * 
 * @param array $payload Данные анкеты продавца
 * @param array $catalog Каталог существующих инвесторов (для исключения дубликатов)
 * @param string $apiKey API-ключ для Together.ai
 * @param int $limit Максимальное количество рекомендаций (по умолчанию 3, максимум 5)
 * @return array Массив AI-рекомендаций инвесторов
 */
function requestAiInvestorSuggestions(array $payload, array $catalog, string $apiKey, int $limit = 3): array
{
    if (empty($apiKey)) {
        return [];  // Если нет API-ключа, возвращаем пустой массив
    }
    
    // Ограничиваем лимит от 1 до 5
    $limit = max(1, min($limit, 5));
    
    // Формируем краткое описание компании для промпта
    $assetSummary = buildAssetSummaryForInvestors($payload);
    
    // Формируем выдержку из каталога для контекста (первые 120 инвесторов)
    $catalogExcerpt = buildInvestorCatalogExcerpt($catalog, 120);
    
    // Список всех названий инвесторов из каталога (для исключения дубликатов)
    $namesList = implode(', ', array_map(static fn ($row) => $row['name'] ?? '', $catalog));

    $prompt = <<<PROMPT
Ты — инвестиционный банкир SmartBizSell. На основании анкеты продавца и каталога инвесторов предложи до {$limit} новых стратегических покупателей, КОТОРЫХ НЕТ в каталоге. Ориентируйся на отрасль, масштаб и стратегию компании.

Каталог инвесторов (фрагмент, уже учтён в системе — повторять имена нельзя):
{$catalogExcerpt}

Названия всех существующих инвесторов (для исключения дублей):
{$namesList}

Профиль компании:
{$assetSummary}

Требования:
- предлагай только реальных инвесторов (корпорации, фонды, private equity) с понятной мотивацией;
- каждая рекомендация должна содержать название, фокус интересов и короткую причину релевантности;
- не придумывай инвесторов из каталога и не используй абстрактные формулировки вроде «частный инвестор».

Ответ в формате JSON-массива:
[
  {"name": "...", "focus": "...", "rationale": "..."},
  ...
]
PROMPT;

    try {
        // Вызываем ИИ через Together.ai API
        $raw = callAICompletions($prompt, $apiKey);
    } catch (Throwable $e) {
        error_log('AI investor suggestion failed: ' . $e->getMessage());
        return [];  // При ошибке возвращаем пустой массив
    }

    // Очищаем ответ от технических артефактов (markdown, служебные фразы)
    $clean = trim(sanitizeAiArtifacts($raw));
    
    // Парсим JSON-ответ
    $json = json_decode($clean, true);
    if (!is_array($json)) {
        return [];  // Если не удалось распарсить JSON, возвращаем пустой массив
    }

    // Создаем карту существующих инвесторов для быстрой проверки дубликатов
    $knownNames = array_map(static fn ($name) => mb_strtolower(trim($name)), array_column($catalog, 'name'));
    $knownMap = array_flip($knownNames);
    
    $suggestions = [];

    // Обрабатываем каждую рекомендацию от ИИ
    foreach ($json as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $focus = trim((string)($row['focus'] ?? ''));
        $reason = trim((string)($row['rationale'] ?? ''));
        
        if ($name === '') {
            continue;  // Пропускаем рекомендации без названия
        }
        
        // Проверяем, нет ли этого инвестора уже в каталоге
        if (isset($knownMap[mb_strtolower($name)])) {
            continue;  // Пропускаем дубликаты
        }
        
        // Добавляем рекомендацию с пометкой source='ai'
        $suggestions[] = [
            'source' => 'ai',
            'name' => $name,
            'focus' => $focus !== '' ? $focus : 'Сферы уточняются',
            'check' => '',
            'reason' => $reason !== '' ? $reason : 'AI-рекомендация на основе профиля компании.',
        ];
        
        if (count($suggestions) >= $limit) {
            break;  // Останавливаемся при достижении лимита
        }
    }

    return $suggestions;
}

/**
 * Формирует выдержку из каталога инвесторов для промпта ИИ
 * 
 * Создает текстовое представление каталога в формате:
 * "Название — Область интересов (чек: диапазон)"
 * 
 * Используется для предоставления контекста ИИ о существующих инвесторах,
 * чтобы ИИ не предлагал дубликаты.
 * 
 * @param array $catalog Каталог инвесторов
 * @param int $limit Максимальное количество инвесторов в выдержке (по умолчанию 120)
 * @return string Текстовая выдержка из каталога
 */
function buildInvestorCatalogExcerpt(array $catalog, int $limit = 120): string
{
    $lines = [];
    foreach (array_slice($catalog, 0, $limit) as $row) {
        $name = trim($row['name'] ?? '');
        $focus = trim($row['focus'] ?? '');
        $check = trim($row['check'] ?? '');
        if ($name === '') {
            continue;
        }
        $line = "{$name} — {$focus}";
        if ($check !== '') {
            $line .= " (чек: {$check})";
        }
        $lines[] = $line;
    }
    if (count($catalog) > $limit) {
        $lines[] = '... (список сокращён для промпта)';
    }
    return implode("\n", $lines);
}

/**
 * Формирует краткое описание компании для промпта ИИ
 * 
 * Собирает ключевую информацию из анкеты:
 * - Название актива и отрасль
 * - Региональное присутствие
 * - Ориентир по выручке
 * - Цель сделки
 * - Основные клиенты
 * 
 * Используется для предоставления контекста ИИ при генерации рекомендаций инвесторов.
 * 
 * @param array $payload Данные анкеты продавца
 * @return string Краткое текстовое описание компании
 */
function buildAssetSummaryForInvestors(array $payload): string
{
    $parts = [];
    $asset = trim((string)($payload['asset_name'] ?? 'Компания'));
    $industry = trim((string)($payload['products_services'] ?? ''));
    $regions = trim((string)($payload['presence_regions'] ?? ''));
    
    // Извлекаем выручку за 2024 год (если доступна)
    $revenue = '';
    if (!empty($payload['financial']['revenue']['2024_fact'])) {
        $revenue = (string)$payload['financial']['revenue']['2024_fact'];
    }

    // Формируем описание по частям
    $parts[] = "{$asset} — {$industry} (если поле пустое, отрасль уточняется).";
    if ($regions !== '') {
        $parts[] = "Региональное присутствие: {$regions}.";
    }
    if ($revenue !== '') {
        $parts[] = "Ориентир по выручке: {$revenue}.";
    }
    if (!empty($payload['deal_goal'])) {
        $parts[] = "Цель сделки: {$payload['deal_goal']}.";
    }
    if (!empty($payload['main_clients'])) {
        $parts[] = "Клиенты: {$payload['main_clients']}.";
    }

    return implode(' ', $parts);
}

/**
 * Форматирует причину релевантности инвестора для отображения
 * 
 * Создает читаемое объяснение, почему инвестор релевантен для данной компании.
 * Использует совпадающие ключевые слова, область интересов и целевой чек.
 * 
 * @param string $focus Область интересов инвестора
 * @param string $check Целевой чек (диапазон инвестиций)
 * @param array $keywords Совпадающие ключевые слова из анкеты
 * @param array $payload Данные анкеты продавца
 * @return string Отформатированная причина релевантности
 */
function formatInvestorReason(string $focus, string $check, array $keywords, array $payload): string
{
    // Если есть совпадающие ключевые слова, используем их
    if (!empty($keywords)) {
        $keywords = array_unique(array_map(static fn ($word) => mb_strtolower($word), $keywords));
        // Берем первые 3 ключевых слова и форматируем с заглавной буквы
        $phrases = array_map(static fn ($word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'), array_slice($keywords, 0, 3));
        return 'Совпадает с фокусом: ' . implode(', ', $phrases) . '.';
    }
    
    // Если есть и область интересов, и целевой чек
    if ($focus !== '' && $check !== '') {
        return "Интересуется сегментом «{$focus}», диапазон сделок {$check}.";
    }
    
    // Если есть только область интересов
    if ($focus !== '') {
        return "Работает в сегментах: {$focus}.";
    }
    
    // Общая формулировка по умолчанию
    return 'Инвестор из каталога SmartBizSell с подходящим профилем сделок.';
}

/**
 * Загружает каталог инвесторов из Excel-файла (формат .xlsx)
 * 
 * Процесс:
 * 1. Открывает Excel-файл как ZIP-архив (формат .xlsx - это ZIP с XML)
 * 2. Извлекает sharedStrings.xml (общие строки) и sheet1.xml (данные листа)
 * 3. Парсит XML для извлечения данных
 * 4. Формирует массив инвесторов с полями: name, focus, check
 * 
 * Кэширование:
 * Использует статическую переменную для кэширования результата,
 * чтобы не загружать файл повторно в рамках одного запроса.
 * 
 * @param string $path Путь к Excel-файлу с каталогом инвесторов
 * @return array Массив инвесторов, каждый элемент содержит:
 *   - name: название инвестора
 *   - focus: область интересов
 *   - check: целевой чек (диапазон инвестиций)
 */
function loadRagInvestors(string $path): array
{
    // Статическое кэширование для избежания повторной загрузки
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    
    // Проверяем существование файла
    if (!is_file($path)) {
        return $cache = [];
    }
    
    // Открываем Excel-файл как ZIP-архив
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return $cache = [];
    }
    
    // Извлекаем необходимые XML-файлы из архива
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');  // Общие строки
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');  // Данные первого листа
    $zip->close();
    
    if ($sharedXml === false || $sheetXml === false) {
        return $cache = [];
    }

    // Парсим XML-документы
    $sharedDom = new DOMDocument();
    $sheetDom = new DOMDocument();
    if (@$sharedDom->loadXML($sharedXml) === false || @$sheetDom->loadXML($sheetXml) === false) {
        return $cache = [];
    }
    
    // Namespace для Excel XML
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    // Извлекаем общие строки (shared strings)
    // В Excel общие строки хранятся отдельно для экономии места
    $sharedStrings = [];
    $siNodes = $sharedDom->getElementsByTagNameNS($ns, 'si');
    foreach ($siNodes as $index => $siNode) {
        $sharedStrings[$index] = trim(getSharedStringDomText($siNode));
    }

    // Извлекаем данные из листа
    $rows = [];
    $rowNodes = $sheetDom->getElementsByTagNameNS($ns, 'row');
    foreach ($rowNodes as $rowNode) {
        /** @var DOMElement $rowNode */
        $rowIndex = (int)$rowNode->getAttribute('r');
        if ($rowIndex <= 1) {
            continue;  // Пропускаем заголовок (первая строка)
        }
        
        $record = ['name' => '', 'focus' => '', 'check' => ''];
        
        // Обрабатываем каждую ячейку в строке
        foreach ($rowNode->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'c') {
                continue;
            }
            
            // Определяем колонку по ссылке (например, A1, B1, C1)
            $ref = $child->getAttribute('r');
            if (!preg_match('/^([A-Z]+)/', $ref, $match)) {
                continue;
            }
            $column = $match[1];
            
            // Определяем тип ячейки
            $type = $child->getAttribute('t');
            $value = '';
            
            // Извлекаем значение из ячейки
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof DOMElement && $grandChild->localName === 'v') {
                    $value = $grandChild->textContent;
                    break;
                }
            }
            
            // Если тип 's' (string), значение - это индекс в sharedStrings
            if ($type === 's') {
                $idx = (int)$value;
                $value = $sharedStrings[$idx] ?? '';
            }
            
            $value = trim($value);
            
            // Распределяем значения по полям в зависимости от колонки
            if ($column === 'A') {
                $record['name'] = $value;      // Колонка A: название инвестора
            } elseif ($column === 'B') {
                $record['focus'] = $value;     // Колонка B: область интересов
            } elseif ($column === 'C') {
                $record['check'] = $value;     // Колонка C: целевой чек
            }
        }
        
        // Добавляем запись, если есть название инвестора
        if ($record['name'] !== '') {
            $rows[] = $record;
        }
    }

    return $cache = $rows;
}

/**
 * Извлекает текст из элемента shared string в Excel XML
 * 
 * Excel хранит общие строки в специальном формате XML.
 * Текст может быть в простом элементе <t> или в элементах <r> (rich text runs).
 * 
 * @param DOMElement $si Элемент <si> (shared string item) из Excel XML
 * @return string Извлеченный текст
 */
function getSharedStringDomText(DOMElement $si): string
{
    $text = '';
    foreach ($si->childNodes as $child) {
        if ($child instanceof DOMElement) {
            if ($child->localName === 't') {
                // Простой текст
                $text .= $child->textContent;
            } elseif ($child->localName === 'r') {
                // Rich text run - текст с форматированием
                foreach ($child->childNodes as $runChild) {
                    if ($runChild instanceof DOMElement && $runChild->localName === 't') {
                        $text .= $runChild->textContent;
                    }
                }
            }
        } elseif ($child instanceof DOMText) {
            // Прямой текстовый узел
            $text .= $child->nodeValue;
        }
    }
    return $text;
}