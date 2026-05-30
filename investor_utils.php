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
function renderInvestorSection(array $investors, int $targetCount = 10, array $options = []): string
{
    $targetCount = max(1, $targetCount);
    $sectionClass = trim((string)($options['section_class'] ?? ''));
    $sectionClassHtml = $sectionClass !== '' ? ' ' . escapeHtml($sectionClass) : '';

    $cards = array_map(
        static fn (array $investor): string => renderInvestorCard($investor, $options),
        $investors
    );
    $headline = '<div class="investor-section__intro">'
        . '<div>'
        . '<h3>Возможные инвесторы</h3>'
        . '<p>Комбинация релевантных контактов из базы SmartBizSell и свежих рекомендаций AI.</p>'
        . '</div>'
        . '<span class="investor-section__count">' . count($investors) . ' из ' . $targetCount . '</span>'
        . '</div>';

    return '<section class="investor-section' . $sectionClassHtml . '">' . $headline . '<div class="investor-grid">' . implode('', $cards) . '</div></section>';
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
 *   - email: email для отправки тизера (может быть пустым)
 *   - reason: причина релевантности
 *   - source: источник ('catalog' или 'ai')
 * @return string HTML-код карточки инвестора
 */
function renderInvestorCard(array $investor, array $options = []): string
{
    // Экранируем все данные для защиты от XSS
    $name = escapeHtml($investor['name'] ?? 'Инвестор');
    $focus = escapeHtml($investor['focus'] ?? 'Область интересов уточняется');
    $check = escapeHtml($investor['check'] ?? '');
    $reason = escapeHtml($investor['reason'] ?? '');
    $emailRaw = trim((string)($investor['email'] ?? ''));
    $source = $investor['source'] ?? 'catalog';
    
    // Добавляем бейдж для AI-рекомендаций
    $badge = $source === 'ai' ? '<span class="investor-card__badge">AI рекомендация</span>' : '';
    
    // Формируем HTML для целевого чека (если указан)
    $checkHtml = $check !== '' ? '<p class="investor-card__check">Целевой чек: ' . $check . '</p>' : '';
    
    // Формируем HTML для причины релевантности (если указана)
    $reasonHtml = $reason !== '' ? '<p class="investor-card__reason">' . $reason . '</p>' : '';

    // Email для отправки тизера: валидный → mailto-ссылка, плейсхолдеры — как текст
    $emailHtml = '';
    if ($emailRaw !== '') {
        $isValidEmail = (bool)filter_var($emailRaw, FILTER_VALIDATE_EMAIL);
        if ($isValidEmail) {
            $emailEsc = escapeHtml($emailRaw);
            $emailHtml = '<p class="investor-card__email">Email для тизера: '
                . '<a href="mailto:' . $emailEsc . '">' . $emailEsc . '</a></p>';
        } else {
            $emailHtml = '<p class="investor-card__email investor-card__email--placeholder">'
                . 'Email для тизера: ' . escapeHtml($emailRaw) . '</p>';
        }
    }

    $showSendButton = $options['show_send_button'] ?? true;
    $actionsHtml = '';
    if ($showSendButton) {
        $button = '<button type="button" class="btn btn-investor-send" data-investor="' . $name . '">Отправить тизер</button>';
        $actionsHtml = '<div class="investor-card__actions">' . $button . '</div>';
    }

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
    {$emailHtml}
    {$actionsHtml}
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
function buildInvestorPool(array $payload, string $apiKey, array $options = []): array
{
    $catalogLimit = max(1, (int)($options['catalog_limit'] ?? 6));
    $aiLimit = max(0, min((int)($options['ai_limit'] ?? 4), 5));
    $totalLimit = max(1, (int)($options['total_limit'] ?? 10));
    $catalogPath = (string)($options['catalog_path'] ?? (__DIR__ . '/rag_investors.xlsx'));

    // Загружаем каталог инвесторов из Excel-файла
    $catalog = loadRagInvestors($catalogPath);
    if (empty($catalog)) {
        return [];
    }

    // Ранжируем инвесторов из каталога по релевантности
    // Ранжирование основано на совпадении ключевых слов и масштабе сделки
    $ranked = rankInvestorsByRelevance($catalog, $payload);

    // Fallback: если жёсткий фильтр по чеку оставил слишком мало кандидатов,
    // пересобираем ранжирование без фильтра (бонусы при этом сохраняются)
    if (count($ranked) < $catalogLimit) {
        $ranked = rankInvestorsByRelevance($catalog, $payload, ['apply_check_filter' => false]);
    }

    // Выбираем топ инвесторов из каталога
    $selected = array_slice($ranked, 0, $catalogLimit);
    
    // Запрашиваем AI-рекомендации (новых инвесторов, которых нет в каталоге)
    $aiSuggestions = $aiLimit > 0
        ? requestAiInvestorSuggestions($payload, $catalog, $apiKey, $aiLimit)
        : [];

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
        if (count($unique) >= $totalLimit) {
            break;  // Ограничиваем пул нужным количеством инвесторов
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
function rankInvestorsByRelevance(array $investors, array $payload, array $options = []): array
{
    // Включён ли жёсткий фильтр по диапазону чека (по умолчанию — да)
    $applyCheckFilter = (bool)($options['apply_check_filter'] ?? true);

    // Извлекаем ключевые слова из анкеты для поиска совпадений
    $keywords = buildAssetKeywords($payload);
    $results = [];

    // Распарсенная выручка компании в млн руб (null, если не указана/не распознана)
    $revenueMln = extractRevenueFromPayload($payload);

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

        // Логика по целевому чеку
        $checkRange = $check !== '' ? parseInvestorCheckRange($check) : null;
        $checkMatch = false;
        $isStartupPick = false;

        if ($checkRange !== null) {
            if ($revenueMln !== null) {
                // Допуск: считаем выручку подходящей, если она ∈ [min*0.8 … max*1.2]
                $minBound = ($checkRange['min'] ?? 0.0) * 0.8;
                $maxBound = $checkRange['max'] !== null ? $checkRange['max'] * 1.2 : INF;

                if ($revenueMln >= $minBound && $revenueMln <= $maxBound) {
                    $score += 5;          // Бонус за попадание в масштаб
                    $checkMatch = true;
                } elseif ($applyCheckFilter) {
                    // Жёсткий фильтр: явное несоответствие масштаба — пропускаем инвестора
                    continue;
                } else {
                    // Fallback-режим: только небольшой штраф
                    $score -= 1;
                }
            } else {
                // Выручка не указана → бонус инвесторам с маленьким минимальным чеком
                $minCheck = $checkRange['min'] ?? $checkRange['max'] ?? null;
                if ($minCheck !== null && $minCheck > 0) {
                    $bonus = max(0.0, 4.0 - log10($minCheck));
                    if ($bonus > 0) {
                        $score += $bonus;
                        $isStartupPick = $bonus >= 2.0;
                    }
                }
            }
        }

        // Email берётся из каталога (может быть пустым или плейсхолдером вроде "(нет публичного)")
        $email = trim((string)($item['email'] ?? ''));

        // Сохраняем результат с баллом релевантности
        $results[] = [
            'source' => 'catalog',
            'name' => $name,
            'focus' => $focus,
            'check' => $check,
            'email' => $email,
            'reason' => formatInvestorReason($focus, $check, $matched, $payload, [
                'check_match' => $checkMatch,
                'check_range' => $checkRange,
                'is_startup_pick' => $isStartupPick,
                'revenue_mln' => $revenueMln,
            ]),
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

    // Контекст по масштабу сделки: либо выручка, либо признак стартапа
    $revenueMln = extractRevenueFromPayload($payload);
    if ($revenueMln !== null) {
        $scaleHint = 'Выручка компании ≈ ' . formatMillionsRub($revenueMln)
            . '. Предлагай инвесторов, чей типичный чек сопоставим с этим масштабом.';
    } else {
        $scaleHint = 'Выручка не указана — компания вероятно стартап / ранняя стадия. '
            . 'Отдавай приоритет инвесторам, готовым на маленькие входные чеки.';
    }

    $prompt = <<<PROMPT
Ты — инвестиционный банкир SmartBizSell. На основании анкеты продавца и каталога инвесторов предложи до {$limit} новых стратегических покупателей, КОТОРЫХ НЕТ в каталоге. Ориентируйся на отрасль, масштаб и стратегию компании.

Каталог инвесторов (фрагмент, уже учтён в системе — повторять имена нельзя):
{$catalogExcerpt}

Названия всех существующих инвесторов (для исключения дублей):
{$namesList}

Профиль компании:
{$assetSummary}

Масштаб сделки:
{$scaleHint}

Требования:
- предлагай только реальных инвесторов (корпорации, фонды, private equity) с понятной мотивацией;
- каждая рекомендация должна содержать название, фокус интересов и короткую причину релевантности;
- учитывай масштаб сделки: для крупной выручки — корпорации и крупные PE; для стартапов — VC и бизнес-ангелы с маленькими чеками;
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
        // Email для AI-инвесторов отсутствует — карточка отобразит блок без email
        $suggestions[] = [
            'source' => 'ai',
            'name' => $name,
            'focus' => $focus !== '' ? $focus : 'Сферы уточняются',
            'check' => '',
            'email' => '',
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
    
    // Извлекаем выручку за последний отчетный период (приоритет: 2025, затем 2024)
    $revenue = '';
    if (!empty($payload['financial']['revenue']['2025_fact'])) {
        $revenue = (string)$payload['financial']['revenue']['2025_fact'];
    } elseif (!empty($payload['financial']['revenue']['2024_fact'])) {
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
function formatInvestorReason(
    string $focus,
    string $check,
    array $keywords,
    array $payload,
    array $context = []
): string {
    $parts = [];

    // Базовая часть — по совпадению ключевых слов или фокусу
    if (!empty($keywords)) {
        $unique = array_unique(array_map(static fn ($word) => mb_strtolower($word), $keywords));
        $phrases = array_map(
            static fn ($word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'),
            array_slice($unique, 0, 3)
        );
        $parts[] = 'Совпадает с фокусом: ' . implode(', ', $phrases) . '.';
    } elseif ($focus !== '' && $check !== '') {
        $parts[] = "Интересуется сегментом «{$focus}», диапазон сделок {$check}.";
    } elseif ($focus !== '') {
        $parts[] = "Работает в сегментах: {$focus}.";
    } else {
        $parts[] = 'Инвестор из каталога SmartBizSell с подходящим профилем сделок.';
    }

    // Контекстные пояснения по целевому чеку
    $checkMatch = (bool)($context['check_match'] ?? false);
    $isStartupPick = (bool)($context['is_startup_pick'] ?? false);
    $revenueMln = $context['revenue_mln'] ?? null;

    if ($checkMatch && $check !== '') {
        if ($revenueMln !== null) {
            $parts[] = "Целевой чек {$check} соответствует масштабу компании (выручка ≈ "
                . formatMillionsRub((float)$revenueMln) . ').';
        } else {
            $parts[] = "Целевой чек {$check} соответствует масштабу компании.";
        }
    } elseif ($isStartupPick) {
        $parts[] = 'Маленький входной чек — подходит для стартапов и ранних стадий.';
    }

    return implode(' ', $parts);
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
 *   - email: email для отправки тизера (может быть пустым)
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
        
        $record = ['name' => '', 'focus' => '', 'check' => '', 'email' => ''];
        
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
            } elseif ($column === 'D') {
                $record['email'] = $value;     // Колонка D: email для отправки тизера
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
/**
 * Парсит строку выручки в числовое значение в млн руб.
 *
 * Поддерживает форматы:
 *  - "180 млн ₽", "180 млн руб", "180000000", "180", "1,5 млрд",
 *  - диапазон "100-200 млн" (берётся среднее значение),
 *  - "1.2 млрд".
 *
 * @param string|null $raw Сырая строка выручки из анкеты
 * @return float|null Значение в млн руб или null, если распарсить не удалось
 */
function parseRevenueToMillions(?string $raw): ?float
{
    if ($raw === null) {
        return null;
    }

    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    // Нормализация: запятая как десятичный разделитель, удаляем пробелы внутри чисел
    $value = mb_strtolower($value);
    $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);

    // Поддержка диапазонов "100-200 млн" — берём среднее
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*[-–—]\s*(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)?/u', $value, $rangeMatch)) {
        $a = parseNumberWithUnit($rangeMatch[1], $rangeMatch[3] ?? '');
        $b = parseNumberWithUnit($rangeMatch[2], $rangeMatch[3] ?? '');
        if ($a !== null && $b !== null) {
            return ($a + $b) / 2.0;
        }
    }

    // Одиночное число с единицей: "180 млн", "1,5 млрд", "500 тыс"
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)?/u', $value, $match)) {
        return parseNumberWithUnit($match[1], $match[2] ?? '');
    }

    return null;
}

/**
 * Парсит диапазон целевого чека инвестора в млн руб.
 *
 * Поддерживает форматы:
 *  - "200 млн – 3 млрд руб" → [200, 3000]
 *  - "от 100 млн" → [100, null]
 *  - "до 50 млн" → [0, 50]
 *  - "100 млн руб" → [100, 100]
 *  - "P/E 7,3" или иные нечисловые → null (нейтрально)
 *
 * @param string $check Сырая строка целевого чека инвестора
 * @return array|null ['min' => float|null, 'max' => float|null] или null
 */
function parseInvestorCheckRange(string $check): ?array
{
    $value = mb_strtolower(trim($check));
    if ($value === '') {
        return null;
    }

    $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);

    // Игнорируем нечисловые мультипликаторы (P/E, EV/EBITDA и т.п.)
    if (preg_match('/\b(p\s*\/\s*e|ev\s*\/|p\s*\/|кратн)/u', $value)) {
        return null;
    }

    // "до 50 млн"
    if (preg_match('/(?:^|\s)до\s+(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)?/u', $value, $m)) {
        $max = parseNumberWithUnit($m[1], $m[2] ?? '');
        if ($max !== null) {
            return ['min' => 0.0, 'max' => $max];
        }
    }

    // "от 100 млн"
    if (preg_match('/(?:^|\s)от\s+(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)?/u', $value, $m)) {
        $min = parseNumberWithUnit($m[1], $m[2] ?? '');
        if ($min !== null) {
            return ['min' => $min, 'max' => null];
        }
    }

    // Диапазон "200 млн – 3 млрд"
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)?\s*[-–—]\s*(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)?/u', $value, $m)) {
        // Если единицы указаны только у одной стороны — используем её для обеих.
        // Optional-группы PHP при mismatched могут отсутствовать в массиве — поэтому ?? ''.
        $rawA = $m[2] ?? '';
        $rawB = $m[4] ?? '';
        $unitA = $rawA !== '' ? $rawA : $rawB;
        $unitB = $rawB !== '' ? $rawB : $rawA;
        $min = parseNumberWithUnit($m[1] ?? '', $unitA);
        $max = parseNumberWithUnit($m[3] ?? '', $unitB);
        if ($min !== null && $max !== null) {
            return ['min' => min($min, $max), 'max' => max($min, $max)];
        }
    }

    // Одиночное число: "100 млн руб"
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(тыс|млн|млрд|тысяч|миллион|миллиард)/u', $value, $m)) {
        $val = parseNumberWithUnit($m[1] ?? '', $m[2] ?? '');
        if ($val !== null) {
            return ['min' => $val, 'max' => $val];
        }
    }

    return null;
}

/**
 * Преобразует число + единицу измерения в значение в млн руб.
 *
 * @param string $number Число (с запятой или точкой)
 * @param string $unit Единица: тыс/млн/млрд/без единицы
 * @return float|null Значение в млн руб
 */
function parseNumberWithUnit(string $number, string $unit): ?float
{
    $clean = str_replace([',', ' '], ['.', ''], trim($number));
    if (!is_numeric($clean)) {
        return null;
    }
    $value = (float)$clean;
    $unit = mb_strtolower(trim($unit));

    if ($unit === 'млрд' || $unit === 'миллиард') {
        return $value * 1000.0;
    }
    if ($unit === 'тыс' || $unit === 'тысяч') {
        return $value / 1000.0;
    }
    if ($unit === 'млн' || $unit === 'миллион') {
        return $value;
    }

    // Без единицы — эвристика по величине:
    // > 1 000 000 — рубли → переводим в млн;
    // 1 000 … 1 000 000 — тыс. руб → в млн;
    // < 1 000 — уже млн.
    if ($value >= 1000000) {
        return $value / 1000000.0;
    }
    if ($value >= 1000) {
        return $value / 1000.0;
    }
    return $value;
}

/**
 * Извлекает выручку компании из payload анкеты и переводит в млн руб.
 *
 * Приоритет периодов: 2025_fact → 2024_fact.
 *
 * @param array $payload Payload анкеты продавца
 * @return float|null Выручка в млн руб или null
 */
function extractRevenueFromPayload(array $payload): ?float
{
    $candidates = [
        $payload['financial']['revenue']['2025_fact'] ?? null,
        $payload['financial']['revenue']['2024_fact'] ?? null,
        $payload['revenue'] ?? null,
    ];

    foreach ($candidates as $raw) {
        if ($raw === null || $raw === '') {
            continue;
        }
        $parsed = parseRevenueToMillions((string)$raw);
        if ($parsed !== null && $parsed > 0) {
            return $parsed;
        }
    }

    return null;
}

/**
 * Форматирует число (млн руб) в человекочитаемую строку.
 *
 * @param float $millions Значение в млн руб
 * @return string Например "350 млн руб" или "1,5 млрд руб"
 */
function formatMillionsRub(float $millions): string
{
    if ($millions >= 1000) {
        $bln = $millions / 1000.0;
        $rounded = round($bln, $bln >= 10 ? 0 : 1);
        $str = rtrim(rtrim(number_format($rounded, 1, ',', ' '), '0'), ',');
        return $str . ' млрд руб';
    }
    $rounded = round($millions, $millions >= 100 ? 0 : 1);
    $str = rtrim(rtrim(number_format($rounded, 1, ',', ' '), '0'), ',');
    return $str . ' млн руб';
}

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