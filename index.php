<?php
/**
 * Главная страница SmartBizSell.ru
 * 
 * Содержит:
 * - Навигацию с условным отображением для авторизованных/неавторизованных пользователей
 * - Hero секцию с описанием платформы
 * - Секцию возможностей
 * - Секцию "Как это работает"
 * - Каталог бизнесов для покупки
 * - Форму анкеты для продавцов (с сохранением в БД)
 * - Секцию контактов
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Загружаем опубликованные тизеры для отображения на главной странице
$publishedTeasers = [];
try {
    ensurePublishedTeasersTable();
    $pdo = getDBConnection();
    
    $stmt = $pdo->prepare("
        SELECT 
            pt.id,
            pt.seller_form_id,
            pt.moderated_html,
            pt.published_at,
            pt.card_title,
            sf.asset_name,
            sf.data_json,
            sf.presence_regions,
            sf.company_description,
            sf.financial_results,
            sf.status as form_status
        FROM published_teasers pt
        INNER JOIN seller_forms sf ON pt.seller_form_id = sf.id
        INNER JOIN (
            SELECT seller_form_id, MAX(published_at) as max_published_at
            FROM published_teasers
            WHERE moderation_status = 'published'
            GROUP BY seller_form_id
        ) latest ON pt.seller_form_id = latest.seller_form_id 
            AND pt.published_at = latest.max_published_at
        WHERE pt.moderation_status = 'published'
        ORDER BY pt.published_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $publishedTeasers = $stmt->fetchAll();
    
    // Извлекаем данные для карточек из каждого тизера
    foreach ($publishedTeasers as &$teaser) {
        $formData = json_decode($teaser['data_json'], true);
        $teaser['card_data'] = extractTeaserCardData($teaser, $formData);
    }
    unset($teaser);
} catch (PDOException $e) {
    error_log("Error loading published teasers: " . $e->getMessage());
}

/**
 * Извлекает данные для карточки бизнеса из тизера
 * 
 * Эта функция парсит HTML тизера и извлекает данные для отображения
 * в компактной карточке на главной странице. Использует DOM парсинг
 * для извлечения данных из hero блока (название, описание, чипы, статистика).
 * 
 * Особенности:
 * - Дедупликация чипов (тегов) для избежания повторений
 * - Приоритет цены предложения продавца над другими ценами
 * - Извлечение финансовых данных из статистики hero блока
 * - Fallback на данные из formData, если HTML парсинг не удался
 * 
 * @param array $teaser Данные из таблицы published_teasers (содержит moderated_html, asset_name, data_json)
 * @param array|null $formData Данные из data_json формы (опционально, используется как fallback)
 * @return array Массив с данными для карточки:
 *   - id: ID тизера
 *   - title: Название актива
 *   - price: Цена (приоритет у цены предложения продавца)
 *   - revenue, revenue_2026e: Выручка
 *   - profit, margin, growth: Финансовые показатели
 *   - employees: Количество сотрудников
 *   - description, full_description: Описание
 *   - chips: Массив чипов (тегов) из hero блока
 *   - stats: Массив статистики из hero блока
 *   - location, industry: Локация и отрасль
 */
function extractTeaserCardData(array $teaser, ?array $formData): array
{
    // Приоритет названия карточки:
    // 1. card_title (кастомное название от модератора)
    // 2. asset_name (если не скрыто продавцом)
    // 3. "Актив" (по умолчанию)
    
    // Проверяем кастомное название от модератора
    if (!empty($teaser['card_title'])) {
        $title = trim($teaser['card_title']);
    } else {
        // Маскирование названия актива по пожеланию продавца (asset_disclosure = 'нет')
        $disclosureRaw = '';
        if (is_array($formData) && isset($formData['asset_disclosure'])) {
            $disclosureRaw = (string)$formData['asset_disclosure'];
        }
        $disclosureNormalized = mb_strtolower(trim($disclosureRaw));
        $shouldMaskName = in_array($disclosureNormalized, ['нет', 'no', 'false', '0'], true);
        $titleSource = $teaser['asset_name'] ?: 'Актив';
        $title = $shouldMaskName ? 'Актив' : $titleSource;
    }

    $cardData = [
        'id' => $teaser['id'],
        'title' => $title,
        'price' => 0,
        'revenue' => 0,
        'revenue_2026e' => 0,
        'profit' => 0,
        'margin' => 0,
        'growth' => 0,
        'employees' => 0,
        'years' => 0,
        'description' => '',
        'full_description' => '',
        'advantages' => [],
        'risks' => [],
        'location' => 'other',
        'industry' => 'services',
        'contact' => '',
        'html' => $teaser['moderated_html'] ?: '',
        'chips' => [],
        'stats' => []
    ];
    
    // Парсим HTML тизера для извлечения данных из hero блока
    $html = $teaser['moderated_html'] ?? '';
    if (empty($html) && is_array($formData) && !empty($formData['teaser_snapshot']['html'])) {
        $html = $formData['teaser_snapshot']['html'];
    }
    
    if (!empty($html) && class_exists('DOMDocument')) {
        // Извлекаем данные из hero блока через DOM парсинг
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        
        // Извлекаем чипы (chips) с дедупликацией
        // Ищем все элементы teaser-chip внутри teaser-hero__tags
        // Используем более точный XPath, чтобы избежать дубликатов из-за вложенности
        $chips = $xpath->query("//div[contains(@class, 'teaser-hero__tags')]//span[contains(@class, 'teaser-chip') and not(ancestor::span[contains(@class, 'teaser-chip')])]");
        $uniqueChips = []; // Массив для отслеживания уникальных чипов по ключу
        $chipsList = []; // Список уникальных чипов для сохранения порядка
        $processedNodes = []; // Массив для отслеживания уже обработанных DOM узлов
        
        foreach ($chips as $chip) {
            // Проверяем, не обрабатывали ли мы уже этот узел
            $nodeId = spl_object_hash($chip);
            if (isset($processedNodes[$nodeId])) {
                continue;
            }
            $processedNodes[$nodeId] = true;
            
            $labelNode = $xpath->query(".//span[contains(@class, 'teaser-chip__label')]", $chip)->item(0);
            $valueNode = $xpath->query(".//strong[contains(@class, 'teaser-chip__value')]", $chip)->item(0);
            if ($labelNode && $valueNode) {
                $label = trim($labelNode->textContent);
                $value = trim($valueNode->textContent);
                
                // Пропускаем пустые значения
                if (empty($label) || empty($value)) {
                    continue;
                }
                
                // Нормализуем значения для сравнения (убираем лишние пробелы, приводим к верхнему регистру)
                $normalizedLabel = mb_strtoupper(trim($label));
                $normalizedValue = mb_strtoupper(trim($value));
                
                // Создаем уникальный ключ для проверки дубликатов
                $chipKey = $normalizedLabel . '|' . $normalizedValue;
                
                // Добавляем только если такого чипа еще нет
                if (!isset($uniqueChips[$chipKey])) {
                    $uniqueChips[$chipKey] = true;
                    $chipsList[] = ['label' => $label, 'value' => $value];
                    
                    // Извлекаем данные из чипов
                    if ($normalizedLabel === 'ПЕРСОНАЛ') {
                        $employees = (int)preg_replace('/[^0-9]/', '', $value);
                        if ($employees > 0) {
                            $cardData['employees'] = $employees;
                        }
                    }
                }
            }
        }
        // Сохраняем уникальные чипы
        $cardData['chips'] = $chipsList;
        
        // Извлекаем статистику из hero блока
        $stats = $xpath->query("//div[contains(@class, 'teaser-hero__stats')]//div[contains(@class, 'teaser-stat')]");
        $sellerPriceValue = null; // Значение цены предложения продавца
        $otherPriceValue = null; // Другая цена (если нет цены предложения продавца)
        
        // Сначала собираем все статистики
        $allStats = [];
        foreach ($stats as $stat) {
            $labelNode = $xpath->query(".//span[1]", $stat)->item(0);
            $valueNode = $xpath->query(".//strong", $stat)->item(0);
            $captionNode = $xpath->query(".//small", $stat)->item(0);
            
            if ($labelNode && $valueNode) {
                $label = trim($labelNode->textContent);
                $value = trim($valueNode->textContent);
                $caption = $captionNode ? trim($captionNode->textContent) : '';
                
                $statItem = [
                    'label' => $label,
                    'value' => $value,
                    'caption' => $caption
                ];
                $allStats[] = $statItem;
                
                // Извлекаем конкретные значения
                if (stripos($label, 'ВЫРУЧКА') !== false || stripos($label, 'Выручка') !== false) {
                    $revenueValue = (float)preg_replace('/[^0-9.]/', '', $value);
                    if ($revenueValue > 0) {
                        if (stripos($label, '2026') !== false) {
                            $cardData['revenue_2026e'] = $revenueValue;
                        } else {
                            $cardData['revenue'] = $revenueValue;
                        }
                    }
                }
                if (stripos($label, 'МАРЖИНАЛЬНОСТЬ') !== false || stripos($label, 'Маржинальность') !== false) {
                    $marginValue = (float)preg_replace('/[^0-9.]/', '', $value);
                    if ($marginValue > 0) {
                        $cardData['margin'] = $marginValue;
                    }
                }
                if (stripos($label, 'ТЕМП РОСТА') !== false || stripos($label, 'Темп роста') !== false) {
                    $growthValue = (float)preg_replace('/[^0-9.]/', '', $value);
                    if ($growthValue > 0) {
                        $cardData['growth'] = $growthValue;
                    }
                }
                // Извлекаем цену с приоритетом "Цена предложения Продавца"
                if (stripos($label, 'ЦЕНА') !== false || stripos($label, 'Цена') !== false) {
                    $priceValue = (float)preg_replace('/[^0-9.]/', '', $value);
                    if ($priceValue > 0) {
                        // Проверяем, является ли это ценой предложения продавца
                        if (stripos($caption, 'Цена предложения Продавца') !== false || 
                            stripos($caption, 'Цена предложения продавца') !== false ||
                            stripos($caption, 'предложения Продавца') !== false ||
                            stripos($caption, 'ПРЕДЛОЖЕНИЯ ПРОДАВЦА') !== false) {
                            $sellerPriceValue = $priceValue;
                        } elseif ($otherPriceValue === null) {
                            // Сохраняем первую другую цену (если нет цены предложения продавца)
                            $otherPriceValue = $priceValue;
                        }
                    }
                }
            }
        }
        
        // Сохраняем статистики
        $cardData['stats'] = $allStats;
        
        // Устанавливаем цену: приоритет у цены предложения продавца
        if ($sellerPriceValue !== null) {
            $cardData['price'] = $sellerPriceValue;
        } elseif ($otherPriceValue !== null) {
            $cardData['price'] = $otherPriceValue;
        }
        
        // Извлекаем описание из hero блока
        $descNode = $xpath->query("//div[contains(@class, 'teaser-hero__content')]//p[contains(@class, 'teaser-hero__description')]")->item(0);
        if ($descNode) {
            $description = trim($descNode->textContent);
            $cardData['description'] = mb_substr($description, 0, 150) . (mb_strlen($description) > 150 ? '...' : '');
            $cardData['full_description'] = $description;
        }
    }
    
    // Извлекаем цену из formData, приоритет у final_price (цена предложения продавца)
    // Используем только если не нашли цену предложения продавца в HTML
    if ($cardData['price'] == 0 && is_array($formData)) {
        // Приоритет 1: final_price (цена предложения продавца)
        if (isset($formData['final_price']) && $formData['final_price'] > 0) {
            $cardData['price'] = (float)$formData['final_price'];
        }
        // Приоритет 2: dcf_equity_value (только если final_price не указана)
        elseif (isset($formData['dcf_equity_value']) && $formData['dcf_equity_value'] > 0) {
            $cardData['price'] = (float)$formData['dcf_equity_value'];
        }
    }
    
    // Извлекаем финансовые данные из formData, если не нашли в HTML
    if ($cardData['revenue'] == 0 && is_array($formData)) {
        if (isset($formData['financial_results']) && is_array($formData['financial_results'])) {
            $financial = $formData['financial_results'];
            if (isset($financial['revenue']['2024_fact'])) {
                $cardData['revenue'] = (float)str_replace([' ', ','], '', (string)$financial['revenue']['2024_fact']);
            }
            if (isset($financial['profit_from_sales']['2024_fact'])) {
                $cardData['profit'] = (float)str_replace([' ', ','], '', (string)$financial['profit_from_sales']['2024_fact']);
            }
        }
    }
    
    // Извлекаем описание из formData, если не нашли в HTML
    if (empty($cardData['description']) && is_array($formData)) {
        if (isset($formData['teaser_snapshot']['hero_description'])) {
            $cardData['description'] = mb_substr($formData['teaser_snapshot']['hero_description'], 0, 150) . '...';
            $cardData['full_description'] = $formData['teaser_snapshot']['hero_description'];
        } elseif (!empty($teaser['company_description'])) {
            $cardData['description'] = mb_substr($teaser['company_description'], 0, 150) . '...';
            $cardData['full_description'] = $teaser['company_description'];
        }
    }
    
    // Извлекаем преимущества и риски из тизера
    if (is_array($formData) && isset($formData['teaser_snapshot']['data'])) {
        $teaserData = $formData['teaser_snapshot']['data'];
        if (isset($teaserData['advantages']) && is_array($teaserData['advantages'])) {
            $cardData['advantages'] = $teaserData['advantages'];
        }
        if (isset($teaserData['risks']) && is_array($teaserData['risks'])) {
            $cardData['risks'] = $teaserData['risks'];
        }
    }
    
    // Определяем регион
    if (!empty($teaser['presence_regions'])) {
        $regions = strtolower($teaser['presence_regions']);
        if (strpos($regions, 'москва') !== false) {
            $cardData['location'] = 'moscow';
        } elseif (strpos($regions, 'санкт-петербург') !== false || strpos($regions, 'спб') !== false) {
            $cardData['location'] = 'spb';
        } elseif (strpos($regions, 'екатеринбург') !== false || strpos($regions, 'екб') !== false) {
            $cardData['location'] = 'ekb';
        }
    }
    
    // Определяем отрасль из чипов или описания
    foreach ($cardData['chips'] as $chip) {
        if (stripos($chip['label'], 'СЕГМЕНТ') !== false || stripos($chip['label'], 'Сегмент') !== false) {
            $segment = strtolower($chip['value']);
            if (strpos($segment, 'it') !== false || strpos($segment, 'разработка') !== false || strpos($segment, 'saas') !== false) {
                $cardData['industry'] = 'it';
            } elseif (strpos($segment, 'ресторан') !== false || strpos($segment, 'кафе') !== false) {
                $cardData['industry'] = 'restaurant';
            } elseif (strpos($segment, 'интернет-магазин') !== false || strpos($segment, 'e-commerce') !== false) {
                $cardData['industry'] = 'ecommerce';
            } elseif (strpos($segment, 'магазин') !== false || strpos($segment, 'торговля') !== false) {
                $cardData['industry'] = 'retail';
            }
            break;
        }
    }
    
    // Если не определили отрасль, используем старую логику
    if ($cardData['industry'] === 'services' && !empty($teaser['company_description'])) {
        $desc = strtolower($teaser['company_description']);
        if (strpos($desc, 'it') !== false || strpos($desc, 'сайт') !== false || strpos($desc, 'разработка') !== false) {
            $cardData['industry'] = 'it';
        } elseif (strpos($desc, 'ресторан') !== false || strpos($desc, 'кафе') !== false) {
            $cardData['industry'] = 'restaurant';
        } elseif (strpos($desc, 'интернет-магазин') !== false || strpos($desc, 'e-commerce') !== false) {
            $cardData['industry'] = 'ecommerce';
        } elseif (strpos($desc, 'магазин') !== false || strpos($desc, 'торговля') !== false) {
            $cardData['industry'] = 'retail';
        }
    }
    
    return $cardData;
}

/**
 * Генерирует персонализированную SVG-иллюстрацию для карточки бизнеса на основе отрасли
 * 
 * Создает уникальные абстрактные иллюстрации, отражающие деятельность компании.
 * Использует градиенты и паттерны, соответствующие общему стилю сайта.
 * Выбирает одну из 10 различных иллюстраций на основе ID карточки для разнообразия.
 * 
 * @param string $industry Отрасль компании (it, restaurant, ecommerce, retail, services, manufacturing, real_estate)
 * @param string $productsServices Описание продуктов/услуг (для дополнительной персонализации)
 * @param int|string $cardId ID карточки для выбора варианта иллюстрации (по умолчанию случайный)
 * @return string SVG код иллюстрации
 */
function generateBusinessCardIllustration(string $industry, string $productsServices = '', $cardId = null): string
{
    // Определяем цветовую схему и паттерн на основе отрасли
    $themes = [
        'it' => [
            'gradient' => ['#667EEA', '#764BA2'],
            'accent' => '#8B5CF6',
            'pattern' => 'circuits',
            'elements' => ['code', 'cloud', 'network']
        ],
        'restaurant' => [
            'gradient' => ['#FFC107', '#FF5722'],
            'accent' => '#FF9800',
            'pattern' => 'organic',
            'elements' => ['food', 'chef', 'dining']
        ],
        'ecommerce' => [
            'gradient' => ['#4CAF50', '#009688'],
            'accent' => '#22C55E',
            'pattern' => 'grid',
            'elements' => ['shopping', 'cart', 'package']
        ],
        'retail' => [
            'gradient' => ['#9C27B0', '#E91E63'],
            'accent' => '#EC4899',
            'pattern' => 'waves',
            'elements' => ['store', 'products', 'display']
        ],
        'services' => [
            'gradient' => ['#3F51B5', '#673AB7'],
            'accent' => '#6366F1',
            'pattern' => 'geometric',
            'elements' => ['handshake', 'consulting', 'support']
        ],
        'manufacturing' => [
            'gradient' => ['#607D8B', '#455A64'],
            'accent' => '#64748B',
            'pattern' => 'industrial',
            'elements' => ['factory', 'production', 'machinery']
        ],
        'real_estate' => [
            'gradient' => ['#0EA5E9', '#14B8A6'],
            'accent' => '#06B6D4',
            'pattern' => 'architectural',
            'elements' => ['building', 'key', 'property']
        ]
    ];
    
    $theme = $themes[$industry] ?? $themes['services'];
    $gradientStart = $theme['gradient'][0];
    $gradientEnd = $theme['gradient'][1];
    $accent = $theme['accent'];
    
    // Выбираем один из 10 вариантов иллюстраций на основе ID карточки для разнообразия
    $cardIdHash = $cardId !== null ? abs(crc32((string)$cardId)) : abs(crc32($industry . $productsServices));
    $variant = ($cardIdHash % 10) + 1; // От 1 до 10
    
    // Генерируем уникальный SVG на основе выбранного варианта
    $svg = '';
    $uniqueId = md5($industry . $cardId . $variant);
    
    switch ($variant) {
        case 1:
            // Вариант 1: Градиентные волны
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.35" />
            <stop offset="50%" style="stop-color:{$accent};stop-opacity:0.25" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <linearGradient id="wave-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.15" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <path d="M0,60 Q50,40 100,60 T200,60 L200,200 L0,200 Z" fill="url(#wave-{$uniqueId})" />
    <path d="M0,100 Q50,80 100,100 T200,100 L200,200 L0,200 Z" fill="url(#wave-{$uniqueId})" opacity="0.7" />
    <path d="M0,140 Q50,120 100,140 T200,140 L200,200 L0,200 Z" fill="url(#wave-{$uniqueId})" opacity="0.5" />
</svg>
SVG;
            break;
            
        case 2:
            // Вариант 2: Геометрические формы
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <radialGradient id="shape-{$uniqueId}">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.5" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0" />
        </radialGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <polygon points="50,50 100,30 150,50 150,100 100,120 50,100" fill="url(#shape-{$uniqueId})" />
    <circle cx="100" cy="150" r="35" fill="url(#shape-{$uniqueId})" opacity="0.7" />
    <rect x="130" y="120" width="45" height="45" fill="url(#shape-{$uniqueId})" rx="10" transform="rotate(45 152.5 142.5)" />
</svg>
SVG;
            break;
            
        case 3:
            // Вариант 3: Сетка с акцентами
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <linearGradient id="box-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.45" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0.15" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <g opacity="0.25">
        <line x1="0" y1="50" x2="200" y2="50" stroke="{$accent}" stroke-width="1.5" />
        <line x1="0" y1="100" x2="200" y2="100" stroke="{$accent}" stroke-width="1.5" />
        <line x1="0" y1="150" x2="200" y2="150" stroke="{$accent}" stroke-width="1.5" />
        <line x1="50" y1="0" x2="50" y2="200" stroke="{$accent}" stroke-width="1.5" />
        <line x1="100" y1="0" x2="100" y2="200" stroke="{$accent}" stroke-width="1.5" />
        <line x1="150" y1="0" x2="150" y2="200" stroke="{$accent}" stroke-width="1.5" />
    </g>
    <rect x="25" y="25" width="50" height="50" fill="url(#box-{$uniqueId})" rx="6" />
    <rect x="85" y="65" width="50" height="50" fill="url(#box-{$uniqueId})" rx="6" />
    <rect x="125" y="125" width="50" height="50" fill="url(#box-{$uniqueId})" rx="6" />
</svg>
SVG;
            break;
            
        case 4:
            // Вариант 4: Органические формы
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.35" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <radialGradient id="blob-{$uniqueId}">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.5" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0" />
        </radialGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <ellipse cx="60" cy="70" rx="40" ry="30" fill="url(#blob-{$uniqueId})" transform="rotate(-20 60 70)" />
    <ellipse cx="140" cy="100" rx="35" ry="45" fill="url(#blob-{$uniqueId})" transform="rotate(30 140 100)" />
    <ellipse cx="100" cy="150" rx="45" ry="25" fill="url(#blob-{$uniqueId})" transform="rotate(-15 100 150)" />
    <circle cx="45" cy="120" r="12" fill="{$accent}" opacity="0.25" />
    <circle cx="155" cy="55" r="14" fill="{$accent}" opacity="0.25" />
    <circle cx="170" cy="145" r="8" fill="{$accent}" opacity="0.25" />
</svg>
SVG;
            break;
            
        case 5:
            // Вариант 5: Линейные паттерны
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <path d="M20,50 Q50,30 80,50 T140,50 T200,50" stroke="{$accent}" stroke-width="2.5" fill="none" opacity="0.35" />
    <path d="M40,100 Q70,80 100,100 T160,100 T200,100" stroke="{$accent}" stroke-width="2.5" fill="none" opacity="0.35" />
    <path d="M60,150 Q90,130 120,150 T180,150 T200,150" stroke="{$accent}" stroke-width="2.5" fill="none" opacity="0.35" />
    <line x1="80" y1="50" x2="100" y2="100" stroke="{$accent}" stroke-width="2" opacity="0.25" />
    <line x1="100" y1="100" x2="120" y2="150" stroke="{$accent}" stroke-width="2" opacity="0.25" />
    <circle cx="80" cy="50" r="8" fill="{$accent}" opacity="0.4" />
    <circle cx="100" cy="100" r="8" fill="{$accent}" opacity="0.4" />
    <circle cx="120" cy="150" r="8" fill="{$accent}" opacity="0.4" />
</svg>
SVG;
            break;
            
        case 6:
            // Вариант 6: Круги и точки
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <radialGradient id="circle-{$uniqueId}">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.45" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0" />
        </radialGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <circle cx="100" cy="100" r="50" fill="url(#circle-{$uniqueId})" />
    <circle cx="50" cy="50" r="25" fill="url(#circle-{$uniqueId})" opacity="0.6" />
    <circle cx="150" cy="60" r="20" fill="url(#circle-{$uniqueId})" opacity="0.6" />
    <circle cx="160" cy="150" r="30" fill="url(#circle-{$uniqueId})" opacity="0.5" />
    <circle cx="40" cy="150" r="18" fill="{$accent}" opacity="0.3" />
    <circle cx="170" cy="40" r="12" fill="{$accent}" opacity="0.3" />
</svg>
SVG;
            break;
            
        case 7:
            // Вариант 7: Треугольники и стрелки
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <linearGradient id="triangle-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.5" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0.15" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <polygon points="100,40 130,80 70,80" fill="url(#triangle-{$uniqueId})" />
    <polygon points="50,100 80,140 20,140" fill="url(#triangle-{$uniqueId})" opacity="0.7" />
    <polygon points="150,120 180,160 120,160" fill="url(#triangle-{$uniqueId})" opacity="0.7" />
    <path d="M100,100 L130,100 L120,90 M130,100 L120,110" stroke="{$accent}" stroke-width="3" fill="none" opacity="0.4" />
    <path d="M70,100 L40,100 L50,90 M40,100 L50,110" stroke="{$accent}" stroke-width="3" fill="none" opacity="0.4" />
</svg>
SVG;
            break;
            
        case 8:
            // Вариант 8: Волны и потоки
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.35" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <linearGradient id="flow-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="0%">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.4" />
            <stop offset="50%" style="stop-color:{$gradientEnd};stop-opacity:0.25" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0.15" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <path d="M0,70 Q25,50 50,70 T100,70 T150,70 T200,70 L200,200 L0,200 Z" fill="url(#flow-{$uniqueId})" />
    <path d="M0,110 Q25,90 50,110 T100,110 T150,110 T200,110 L200,200 L0,200 Z" fill="url(#flow-{$uniqueId})" opacity="0.7" />
    <path d="M0,150 Q25,130 50,150 T100,150 T150,150 T200,150 L200,200 L0,200 Z" fill="url(#flow-{$uniqueId})" opacity="0.5" />
</svg>
SVG;
            break;
            
        case 9:
            // Вариант 9: Многоугольники
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
        <linearGradient id="poly-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$accent};stop-opacity:0.5" />
            <stop offset="100%" style="stop-color:{$accent};stop-opacity:0.15" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <polygon points="100,30 140,60 130,110 70,110 60,60" fill="url(#poly-{$uniqueId})" />
    <polygon points="40,100 70,120 60,160 20,150" fill="url(#poly-{$uniqueId})" opacity="0.7" />
    <polygon points="160,100 180,120 170,160 140,150" fill="url(#poly-{$uniqueId})" opacity="0.7" />
    <polygon points="100,170 120,150 140,180 80,180" fill="url(#poly-{$uniqueId})" opacity="0.6" />
</svg>
SVG;
            break;
            
        case 10:
            // Вариант 10: Абстрактные линии
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
    <path d="M0,50 L80,50 L100,80 L120,50 L200,50" stroke="{$accent}" stroke-width="3" fill="none" opacity="0.35" />
    <path d="M0,100 L60,100 L80,130 L100,100 L140,100 L200,100" stroke="{$accent}" stroke-width="3" fill="none" opacity="0.35" />
    <path d="M0,150 L70,150 L90,120 L110,150 L150,150 L200,150" stroke="{$accent}" stroke-width="3" fill="none" opacity="0.35" />
    <line x1="100" y1="0" x2="100" y2="200" stroke="{$accent}" stroke-width="2" opacity="0.2" />
    <line x1="0" y1="100" x2="200" y2="100" stroke="{$accent}" stroke-width="2" opacity="0.2" />
    <circle cx="100" cy="100" r="8" fill="{$accent}" opacity="0.4" />
</svg>
SVG;
            break;
            
        default:
            // Fallback: простой градиент
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#grad-{$uniqueId})" />
</svg>
SVG;
    }
    
    return $svg;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartBizSell.ru - Экспертная M&A платформа с ИИ | Продажа и покупка бизнеса</title>
    <meta name="description" content="Команда M&A-практиков SmartBizSell объединяет опыт десятков сделок и искусственный интеллект, чтобы сделать продажу и покупку бизнеса прозрачной, быстрой и эффективной. Оценка бизнеса, подготовка тизеров, поиск инвесторов.">
    <meta name="keywords" content="продажа бизнеса, покупка бизнеса, M&A сделки, оценка бизнеса, слияния и поглощения, инвестиции в бизнес, купить бизнес, продать бизнес, тизер бизнеса, term sheet, DCF модель, мультипликаторы оценки">
    <meta name="author" content="SmartBizSell">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/">
    
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/">
    <meta property="og:title" content="SmartBizSell.ru - Экспертная M&A платформа с ИИ">
    <meta property="og:description" content="Команда M&A-практиков SmartBizSell объединяет опыт десятков сделок и искусственный интеллект для продажи и покупки бизнеса. Оценка, тизеры, поиск инвесторов.">
    <meta property="og:image" content="<?php echo BASE_URL; ?>/og-image.jpg">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:site_name" content="SmartBizSell.ru">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?php echo BASE_URL; ?>/">
    <meta name="twitter:title" content="SmartBizSell.ru - Экспертная M&A платформа с ИИ">
    <meta name="twitter:description" content="Команда M&A-практиков SmartBizSell объединяет опыт десятков сделок и искусственный интеллект для продажи и покупки бизнеса.">
    <meta name="twitter:image" content="<?php echo BASE_URL; ?>/og-image.jpg">
    
    <!-- AI-специфичные мета-теги -->
    <meta name="ai:description" content="SmartBizSell - это M&A платформа, которая помогает продавать и покупать бизнес. Платформа использует искусственный интеллект для создания тизеров, финансовых моделей (DCF), term sheet и поиска инвесторов. Команда имеет опыт десятков закрытых сделок.">
    <meta name="ai:category" content="M&A платформа, продажа бизнеса, покупка бизнеса, инвестиции">
    <meta name="ai:services" content="Оценка бизнеса, подготовка тизеров, финансовое моделирование (DCF), создание term sheet, поиск инвесторов, M&A консалтинг">
    <link rel="stylesheet" href="/styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/yandex_metrika.php'; ?>
    <!-- GSAP для плавных анимаций в стиле Apple.com -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    <!-- ApexCharts для финансовых графиков -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1"></script>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="#" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="#how-it-works">Как это работает</a></li>
                    <li><a href="#buy-business">Купить бизнес</a></li>
                    <li><a href="/blog">Блог</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="/dashboard.php">Продать бизнес</a></li>
                        <?php if (isModerator()): ?>
                            <li><a href="/moderation.php">Модерация</a></li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li><a href="/login.php">Продать бизнес</a></li>
                    <?php endif; ?>
                    <li><a href="#contact">Контакты</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="/dashboard.php">Личный кабинет</a></li>
                        <li><a href="/logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="/login.php">Войти</a></li>
                        <li><a href="/register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-background">
            <div class="gradient-orb orb-1"></div>
            <div class="gradient-orb orb-2"></div>
            <div class="gradient-orb orb-3"></div>
        </div>
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">
                    <span class="gradient-text">Экспертная M&amp;A платформа</span>
                    <br>для продажи и покупки бизнеса с поддержкой <span class="gradient-text">ИИ</span>
                </h1>
                <p class="hero-subtitle">
                    Мы — команда M&amp;A-профессионалов с десятками закрытых сделок. Платформа SmartBizSell объединяет наш опыт, современные технологии и искусственный интеллект, чтобы проводить сделки быстрее, прозрачнее и экономичнее.
                </p>
                <div class="hero-buttons">
                    <a href="<?php echo isLoggedIn() ? '/dashboard.php' : '/login.php'; ?>" class="btn btn-primary">
                        <span>Продать бизнес</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a href="#how-it-works" class="btn btn-secondary">
                        <span>Узнать больше</span>
                    </a>
                    <a href="/estimate.php" class="btn btn-estimate">
                        <span>Оценить бизнес</span>
                    </a>
                </div>
                <div class="hero-stats">
                    <div class="stat-item" data-stat="500">
                        <div class="stat-number">500+</div>
                        <div class="stat-label">Проверенных инвесторов</div>
                    </div>
                    <div class="stat-item" data-stat="150">
                        <div class="stat-number">150+</div>
                        <div class="stat-label">Закрытых M&amp;A-сделок</div>
                    </div>
                    <div class="stat-item" data-stat="3">
                        <div class="stat-number">3 часа</div>
                        <div class="stat-label">На модерацию тизера</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Преимущества SmartBizSell</h2>
                <p class="section-subtitle">Экспертиза команды M&amp;A, усиленная искусственным интеллектом и современными технологиями</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="5" y="16" width="4" height="11" rx="2" fill="#6366F1"/>
                            <rect x="14" y="9" width="4" height="18" rx="2" fill="#8B5CF6"/>
                            <rect x="23" y="4" width="4" height="23" rx="2" fill="#A5B4FC"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">ИИ-Генерация тизеров</h3>
                    <p class="feature-description">
                        Используем проверенные нами подходы к тизерам и подключаем ИИ для точной аналитики, чтобы каждый инвестор сразу видел ценность бизнеса.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6 22L13 15L18 21L26 10" stroke="#22D3EE" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="13" cy="15" r="2" fill="#22D3EE"/>
                            <circle cx="18" cy="21" r="2" fill="#22D3EE"/>
                            <circle cx="26" cy="10" r="2" fill="#22D3EE"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Автоматические финансовые модели</h3>
                    <p class="feature-description">
                        Формируем финансовые модели по стандартам сделок M&amp;A и ускоряем расчёты с помощью нейросетей — быстро, прозрачно и с учётом ключевых метрик.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M16 6L18.4721 12.5279L25 15L18.4721 17.4721L16 24L13.5279 17.4721L7 15L13.5279 12.5279L16 6Z" fill="url(#gradStar1)"/>
                            <defs>
                                <linearGradient id="gradStar1" x1="7" y1="6" x2="25" y2="24" gradientUnits="userSpaceOnUse">
                                    <stop stop-color="#FDE047"/>
                                    <stop offset="1" stop-color="#F97316"/>
                                </linearGradient>
                            </defs>
                        </svg>
                    </div>
                    <h3 class="feature-title">Ускорение процессов</h3>
                    <p class="feature-description">
                        Цифровые пайплайны заменяют ручные задачи: готовим материалы, структурируем данные и запускаем показы в разы быстрее традиционных процессов.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10" cy="10" r="4" stroke="#F97316" stroke-width="2"/>
                            <circle cx="22" cy="10" r="4" stroke="#FACC15" stroke-width="2"/>
                            <circle cx="16" cy="22" r="4" stroke="#FB923C" stroke-width="2"/>
                            <path d="M12 12L15 19M20 12L17 19" stroke="#F97316" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Умный подбор покупателей</h3>
                    <p class="feature-description">
                        Соединяем данные о прошлых сделках, нашу экспертную оценку и алгоритмы рекомендаций, чтобы вывести к вам релевантных инвесторов без лишнего шума.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="16" cy="16" r="11" stroke="#10B981" stroke-width="2" opacity="0.6"/>
                            <path d="M16 7V16L23 19" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M10 21C12 23 14 24 16 24C20 24 23 21 23 17" stroke="#34D399" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Безопасность данных</h3>
                    <p class="feature-description">
                        Следуем лучшим практикам комплаенса и используем корпоративный уровень защиты, чтобы вся информация о сделке оставалась конфиденциальной.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M14 4H8C7.46957 4 6.96086 4.21071 6.58579 4.58579C6.21071 4.96086 6 5.46957 6 6V26C6 26.5304 6.21071 27.0391 6.58579 27.4142C6.96086 27.7893 7.46957 28 8 28H24C24.5304 28 25.0391 27.7893 25.4142 27.4142C25.7893 27.0391 26 26.5304 26 26V12L18 4H14Z" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M18 4V12H26" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20 18H12" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20 22H12" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14 10H12" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Создание Term Sheet</h3>
                    <p class="feature-description">
                        Автоматически формируем инвестиционный меморандум с ключевыми условиями сделки. Term Sheet помогает закрепить параметры сделки и ускорить переговоры с инвесторами.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="how-it-works" class="how-it-works">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Как проходит сделка с нами</h2>
                <p class="section-subtitle">Совмещаем экспертное сопровождение и автоматизацию, чтобы вы видели каждый шаг и результат в цифрах</p>
            </div>
            <div class="steps">
                <div class="step-item">
                    <div class="step-number">01</div>
                    <div class="step-content">
                        <h3 class="step-title">Заполните анкету</h3>
                        <p class="step-description">
                            Делитесь ключевыми данными о компании. Мы убрали лишние вопросы и сразу подсказываем, какие цифры важны для успешной сделки.
                        </p>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-number">02</div>
                    <div class="step-content">
                        <h3 class="step-title">Создайте тизер в личном кабинете</h3>
                        <p class="step-description">
                            После заполнения анкеты вы сами запускаете создание тизера в личном кабинете. ИИ анализирует данные и генерирует профессиональный тизер, DCF модель и оценку за несколько минут.
                        </p>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-number">03</div>
                    <div class="step-content">
                        <h3 class="step-title">Отправьте тизер на модерацию</h3>
                        <p class="step-description">
                            Вы отправляете созданный тизер на модерацию. Наша команда M&amp;A-консультантов проверяет материалы за несколько часов, при необходимости корректирует и публикует тизер. Term Sheet можно создать в любой момент для закрепления ключевых условий сделки.
                        </p>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-number">04</div>
                    <div class="step-content">
                        <h3 class="step-title">Выход на рынок</h3>
                        <p class="step-description">
                            Размещаем предложение на платформе, подключаем нашу сеть покупателей и управляем коммуникациями. Вы видите статус каждого лида и экономику сделки.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Buy Business Section -->
    <section id="buy-business" class="buy-business-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Купить бизнес</h2>
                <p class="section-subtitle">Изучайте сделки, подготовленные нашей M&amp;A-командой и подтверждённые аналитикой платформы</p>
            </div>
            
            <div class="filter-bar">
                <div class="filter-group">
                    <label for="filter-industry">Отрасль:</label>
                    <select id="filter-industry" class="filter-select">
                        <option value="">Все отрасли</option>
                        <option value="retail">Розничная торговля</option>
                        <option value="services">Услуги</option>
                        <option value="manufacturing">Производство</option>
                        <option value="it">IT и технологии</option>
                        <option value="restaurant">Рестораны и кафе</option>
                        <option value="ecommerce">E-commerce</option>
                        <option value="real_estate">Недвижимость</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter-price">Цена до:</label>
                    <select id="filter-price" class="filter-select">
                        <option value="">Любая цена</option>
                        <option value="5000000">до 5 млн ₽</option>
                        <option value="10000000">до 10 млн ₽</option>
                        <option value="50000000">до 50 млн ₽</option>
                        <option value="100000000">до 100 млн ₽</option>
                        <option value="999999999">свыше 100 млн ₽</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter-location">Город:</label>
                    <select id="filter-location" class="filter-select">
                        <option value="">Все города</option>
                        <option value="moscow">Москва</option>
                        <option value="spb">Санкт-Петербург</option>
                        <option value="ekb">Екатеринбург</option>
                        <option value="other">Другие города</option>
                    </select>
                </div>
            </div>

            <div class="businesses-grid" id="businesses-grid">
                <?php if (empty($publishedTeasers)): ?>
                    <!-- Статические карточки (fallback, если нет опубликованных тизеров) -->
                <!-- Business Card 1 -->
                <div class="business-card card-it"
                     data-industry="it"
                     data-price="15000000"
                     data-location="moscow"
                     data-id="1"
                     data-title="IT-Стартап по разработке SaaS"
                     data-revenue="12000000"
                     data-employees="8"
                     data-years="3"
                     data-profit="4800000"
                     data-growth="25"
                     data-description="Развивающийся SaaS-проект с активной клиентской базой. Продукт для автоматизации бизнес-процессов. Готовая команда разработки. Стабильный рост выручки."
                     data-full-description="Компания специализируется на разработке и поддержке SaaS-решений для автоматизации бизнес-процессов малого и среднего бизнеса. Продукт включает модули для управления проектами, CRM, аналитики и отчетности. Активная клиентская база насчитывает более 200 компаний. Команда из 8 опытных разработчиков и менеджеров. Бизнес работает по модели подписки (SaaS), что обеспечивает стабильный ежемесячный доход. Высокий потенциал для масштабирования."
                     data-advantages="Готовая команда разработки|Активная клиентская база 200+ компаний|Стабильный рекуррентный доход|Высокий потенциал роста|Современные технологии|Автоматизированные процессы"
                     data-risks="Зависимость от ключевых сотрудников|Конкуренция на рынке SaaS"
                     data-contact="+7 (495) 123-45-67">
                    <div class="card-header">
                        <div class="card-illustration">
                            <?php echo generateBusinessCardIllustration('it', 'SaaS разработка', 1); ?>
                        </div>
                        <div class="card-icon-bg">
                            <div class="card-icon">💻</div>
                        </div>
                        <div class="card-badge">Новое</div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">IT-Стартап по разработке SaaS</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">12 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">8</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">3 года</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Развивающийся SaaS-проект с активной клиентской базой. Продукт для автоматизации бизнес-процессов. Готовая команда разработки. Стабильный рост выручки.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">15 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 2 -->
                <div class="business-card card-restaurant"
                     data-industry="restaurant"
                     data-price="8000000"
                     data-location="moscow"
                     data-id="2"
                     data-title="Сеть кофеен в центре Москвы"
                     data-revenue="25000000"
                     data-employees="15"
                     data-years="5"
                     data-profit="7500000"
                     data-growth="15"
                     data-description="Две кофейни в проходимых местах центра Москвы. Налаженные поставки, обученный персонал, постоянная клиентская база."
                     data-full-description="Успешная сеть из двух кофеен, расположенных в центре Москвы в местах с высокой проходимостью. Обе точки оснащены современным оборудованием, налажены прямые поставки от обжарщиков. Обученная команда из 15 сотрудников работает по стандартизированным процессам. Постоянная клиентская база и лояльная аудитория. Высокий средний чек и стабильная прибыльность."
                     data-advantages="Две точки в центре Москвы|Налаженные поставки|Обученный персонал|Высокая проходимость|Лояльная клиентская база|Готовая инфраструктура"
                     data-risks="Конкуренция в сегменте|Зависимость от локации"
                     data-contact="+7 (495) 234-56-78">
                    <div class="card-header">
                        <div class="card-illustration">
                            <?php echo generateBusinessCardIllustration('restaurant', 'Кофейни', 2); ?>
                        </div>
                        <div class="card-icon-bg">
                            <div class="card-icon">🍽️</div>
                        </div>
                        <div class="card-badge badge-popular">Популярное</div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Сеть кофеен в центре Москвы</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">25 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">15</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">5 лет</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Две кофейни в проходимых местах центра Москвы. Налаженные поставки, обученный персонал, постоянная клиентская база. Готовая к работе инфраструктура.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">8 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 3 -->
                <div class="business-card card-ecommerce"
                     data-industry="ecommerce"
                     data-price="12000000"
                     data-location="spb"
                     data-id="3"
                     data-title="Интернет-магазин детских товаров"
                     data-revenue="18000000"
                     data-employees="5"
                     data-years="4"
                     data-profit="5400000"
                     data-growth="20"
                     data-description="Успешный онлайн-магазин с собственным складом и логистикой. Широкий ассортимент товаров для детей."
                     data-full-description="Успешный интернет-магазин детских товаров с собственной складской логистикой. Широкий ассортимент от 0 до 12 лет. Собственный склад площадью 500 кв.м, отлаженная система доставки по всей России. Активная маркетинговая стратегия в социальных сетях и контекстной рекламе. Высокий уровень клиентского сервиса и положительные отзывы. Стабильный рост продаж."
                     data-advantages="Собственный склад|Отлаженная логистика|Широкий ассортимент|Активный маркетинг|Высокий сервис|Рост продаж 20%"
                     data-risks="Сезонность спроса|Зависимость от поставщиков"
                     data-contact="+7 (812) 345-67-89">
                    <div class="card-header">
                        <div class="card-illustration">
                            <?php echo generateBusinessCardIllustration('ecommerce', 'Интернет-магазин', 3); ?>
                        </div>
                        <div class="card-icon-bg">
                            <div class="card-icon">🛒</div>
                        </div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Интернет-магазин детских товаров</h3>
                        <p class="card-location">📍 Санкт-Петербург</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">18 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">5</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">4 года</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Успешный онлайн-магазин с собственным складом и логистикой. Широкий ассортимент товаров для детей. Активная маркетинговая стратегия и высокий уровень клиентского сервиса.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">12 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 4 -->
                <div class="business-card card-services"
                     data-industry="real_estate"
                     data-price="3000000"
                     data-location="moscow"
                     data-id="4"
                     data-title="Агентство недвижимости"
                     data-revenue="8000000"
                     data-employees="12"
                     data-years="7"
                     data-profit="2400000"
                     data-growth="10"
                     data-description="Стабильное агентство недвижимости с сильной репутацией. Офис в центре Москвы, команда опытных риелторов."
                     data-full-description="Стабильное агентство недвижимости с сильной репутацией на рынке. Офис в центре Москвы площадью 120 кв.м. Команда из 12 опытных риелторов с сертификатами. Обширная база объектов недвижимости и клиентов. Лицензия на осуществление риелторской деятельности. Все необходимые документы в порядке. Стабильный поток клиентов и сделок."
                     data-advantages="Центральный офис|Опытная команда|Обширная база|Лицензия|Сильная репутация|Стабильный поток клиентов"
                     data-risks="Зависимость от рынка недвижимости|Конкуренция"
                     data-contact="+7 (495) 456-78-90">
                    <div class="card-header">
                        <div class="card-illustration">
                            <?php echo generateBusinessCardIllustration('real_estate', 'Недвижимость', 4); ?>
                        </div>
                        <div class="card-icon-bg">
                            <div class="card-icon">💼</div>
                        </div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Агентство недвижимости</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">8 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">12</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">7 лет</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Стабильное агентство недвижимости с сильной репутацией. Офис в центре Москвы, команда опытных риелторов, база объектов и клиентов. Лицензия и все необходимые документы.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">3 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 5 -->
                <div class="business-card card-retail"
                     data-industry="retail"
                     data-price="6000000"
                     data-location="ekb"
                     data-id="5"
                     data-title="Сеть магазинов одежды"
                     data-revenue="20000000"
                     data-employees="10"
                     data-years="6"
                     data-profit="6000000"
                     data-growth="12"
                     data-description="Три магазина одежды в торговых центрах Екатеринбурга. Налаженные поставки от производителей, узнаваемый бренд."
                     data-full-description="Сеть из трех магазинов одежды, расположенных в крупных торговых центрах Екатеринбурга. Налаженные прямые поставки от производителей без посредников. Узнаваемый бренд и лояльная клиентская база. Стильный мерчендайзинг и современный дизайн магазинов. Стабильный доход и потенциал для расширения сети в другие города."
                     data-advantages="Три точки продаж|Прямые поставки|Узнаваемый бренд|Торговые центры|Лояльная база|Потенциал расширения"
                     data-risks="Конкуренция в ритейле|Зависимость от арендодателей"
                     data-contact="+7 (343) 567-89-01">
                    <div class="card-header">
                        <div class="card-illustration">
                            <?php echo generateBusinessCardIllustration('retail', 'Магазины одежды', 5); ?>
                        </div>
                        <div class="card-icon-bg">
                            <div class="card-icon">🏪</div>
                        </div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Сеть магазинов одежды</h3>
                        <p class="card-location">📍 Екатеринбург</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">20 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">10</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">6 лет</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Три магазина одежды в торговых центрах Екатеринбурга. Налаженные поставки от производителей, узнаваемый бренд, лояльная клиентская база. Стабильный доход и потенциал для расширения.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">6 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 6 -->
                <div class="business-card card-beauty"
                     data-industry="services"
                     data-price="4500000"
                     data-location="moscow"
                     data-id="6"
                     data-title="Салон красоты премиум-класса"
                     data-revenue="15000000"
                     data-employees="8"
                     data-years="4"
                     data-profit="5250000"
                     data-growth="18"
                     data-description="Премиальный салон красоты в центре Москвы. Современное оборудование, профессиональная команда стилистов."
                     data-full-description="Премиальный салон красоты в центре Москвы площадью 200 кв.м. Современное профессиональное оборудование ведущих мировых брендов. Команда из 8 профессиональных стилистов, визажистов и мастеров маникюра. Постоянная клиентская база из 500+ постоянных клиентов. Высокий средний чек и отличная репутация. Система предварительной записи и лояльности."
                     data-advantages="Центральная локация|Премиум оборудование|Профессиональная команда|Постоянная база 500+|Высокий средний чек|Отличная репутация"
                     data-risks="Зависимость от мастеров|Конкуренция в сегменте"
                     data-contact="+7 (495) 678-90-12">
                    <div class="card-header">
                        <div class="card-illustration">
                            <?php echo generateBusinessCardIllustration('services', 'Салон красоты', 6); ?>
                        </div>
                        <div class="card-icon-bg">
                            <div class="card-icon">✂️</div>
                        </div>
                        <div class="card-badge badge-recommended">Рекомендуем</div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Салон красоты премиум-класса</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">15 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">8</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">4 года</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Премиальный салон красоты в центре Москвы. Современное оборудование, профессиональная команда стилистов, постоянная клиентская база. Высокий средний чек и отличная репутация.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">4 500 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>
                <?php else: ?>
                    <!-- Динамические карточки из опубликованных тизеров -->
                    <?php foreach ($publishedTeasers as $teaser): ?>
                        <?php 
                        $card = $teaser['card_data'];
                        // Форматируем цену (уже в миллионах)
                        $priceFormatted = $card['price'] > 0 ? number_format($card['price'], 0, '.', ' ') . ' млн ₽' : 'По запросу';
                        // Форматируем выручку (уже в миллионах)
                        $revenueFormatted = $card['revenue'] > 0 ? number_format($card['revenue'], 0, '.', ' ') . ' млн ₽' : ($card['revenue_2026e'] > 0 ? number_format($card['revenue_2026e'], 0, '.', ' ') . ' млн ₽' : '—');
                        $locationLabels = [
                            'moscow' => 'Москва',
                            'spb' => 'Санкт-Петербург',
                            'ekb' => 'Екатеринбург',
                            'other' => 'Другие города'
                        ];
                        $locationLabel = $locationLabels[$card['location']] ?? 'Другие города';
                        $industryIcons = [
                            'it' => '💻',
                            'restaurant' => '🍽️',
                            'ecommerce' => '🛒',
                            'retail' => '🏪',
                            'services' => '💼',
                            'manufacturing' => '🏭',
                            'real_estate' => '🏢'
                        ];
                        $icon = $industryIcons[$card['industry']] ?? '💼';
                        ?>
                        <div class="business-card card-<?php echo htmlspecialchars($card['industry'], ENT_QUOTES, 'UTF-8'); ?> business-card-enhanced"
                             data-industry="<?php echo htmlspecialchars($card['industry'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-price="<?php echo $card['price'] * 1000000; ?>"
                             data-location="<?php echo htmlspecialchars($card['location'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-id="<?php echo $card['id']; ?>"
                             data-teaser-id="<?php echo $teaser['id']; ?>"
                             data-seller-form-id="<?php echo $teaser['seller_form_id']; ?>"
                             data-title="<?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-revenue="<?php echo ($card['revenue'] > 0 ? $card['revenue'] : $card['revenue_2026e']) * 1000000; ?>"
                             data-employees="<?php echo $card['employees']; ?>"
                             data-years="<?php echo $card['years']; ?>"
                             data-profit="<?php echo $card['profit'] * 1000000; ?>"
                             data-growth="<?php echo $card['growth']; ?>"
                             data-description="<?php echo htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-full-description="<?php echo htmlspecialchars($card['full_description'], ENT_QUOTES, 'UTF-8'); ?>"
                             data-advantages="<?php echo htmlspecialchars(implode('|', $card['advantages']), ENT_QUOTES, 'UTF-8'); ?>"
                             data-risks="<?php echo htmlspecialchars(implode('|', $card['risks']), ENT_QUOTES, 'UTF-8'); ?>"
                             data-contact="<?php echo htmlspecialchars($card['contact'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="card-header">
                                <div class="card-illustration">
                                    <?php 
                                    $productsServices = '';
                                    if (is_array($formData) && !empty($formData['products_services'])) {
                                        $productsServices = $formData['products_services'];
                                    }
                                    echo generateBusinessCardIllustration($card['industry'], $productsServices, $card['id']);
                                    ?>
                                </div>
                                <div class="card-icon-bg">
                                    <div class="card-icon"><?php echo $icon; ?></div>
                                </div>
                                <?php if ($teaser['published_at'] && (time() - strtotime($teaser['published_at'])) < 86400 * 7): ?>
                                    <div class="card-badge">Новое</div>
                                <?php endif; ?>
                            </div>

                            <div class="card-content">
                                <h3 class="card-title"><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p class="card-description"><?php echo htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                
                                <?php if (!empty($card['chips'])): ?>
                                    <?php
                                    // Убираем дубликаты чипов перед отображением (дополнительная проверка)
                                    $uniqueChipsDisplay = [];
                                    $seenKeysDisplay = [];
                                    foreach ($card['chips'] as $chip) {
                                        // Нормализуем для сравнения
                                        $normalizedLabel = mb_strtoupper(trim($chip['label'] ?? ''));
                                        $normalizedValue = mb_strtoupper(trim($chip['value'] ?? ''));
                                        
                                        // Пропускаем пустые значения
                                        if (empty($normalizedLabel) || empty($normalizedValue)) {
                                            continue;
                                        }
                                        
                                        $chipKey = $normalizedLabel . '|' . $normalizedValue;
                                        if (!isset($seenKeysDisplay[$chipKey])) {
                                            $seenKeysDisplay[$chipKey] = true;
                                            $uniqueChipsDisplay[] = $chip;
                                        }
                                    }
                                    ?>
                                    <?php if (!empty($uniqueChipsDisplay)): ?>
                                        <div class="card-chips" style="display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0;">
                                            <?php foreach ($uniqueChipsDisplay as $chip): ?>
                                                <span class="card-chip" style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; background: rgba(99, 102, 241, 0.1); border-radius: 6px; font-size: 11px; color: #6366F1;">
                                                    <strong style="font-weight: 600;"><?php echo htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                                                    <span><?php echo htmlspecialchars($chip['value'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if (!empty($card['stats'])): ?>
                                    <?php
                                    // Фильтруем статистику: для цены показываем только "Цена предложения Продавца"
                                    $filteredStats = [];
                                    $sellerPriceStat = null;
                                    foreach ($card['stats'] as $stat) {
                                        $label = mb_strtoupper(trim($stat['label'] ?? ''));
                                        $caption = mb_strtoupper(trim($stat['caption'] ?? ''));
                                        
                                        // Если это цена, проверяем caption
                                        if (stripos($label, 'ЦЕНА') !== false || stripos($label, 'Цена') !== false) {
                                            // Если это цена предложения продавца, сохраняем её
                                            if (stripos($caption, 'ЦЕНА ПРЕДЛОЖЕНИЯ ПРОДАВЦА') !== false || 
                                                stripos($caption, 'ПРЕДЛОЖЕНИЯ ПРОДАВЦА') !== false) {
                                                $sellerPriceStat = $stat;
                                            }
                                            // Пропускаем другие цены, если есть цена предложения продавца
                                            if ($sellerPriceStat === null) {
                                                $filteredStats[] = $stat;
                                            }
                                        } else {
                                            // Для не-цен добавляем все статистики
                                            $filteredStats[] = $stat;
                                        }
                                    }
                                    
                                    // Если нашли цену предложения продавца, добавляем её в начало
                                    if ($sellerPriceStat !== null) {
                                        array_unshift($filteredStats, $sellerPriceStat);
                                    }
                                    
                                    // Ограничиваем до 4 элементов
                                    $filteredStats = array_slice($filteredStats, 0, 4);
                                    ?>
                                    <?php if (!empty($filteredStats)): ?>
                                        <div class="card-stats" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 16px 0; padding: 16px; background: rgba(0, 0, 0, 0.02); border-radius: 12px;">
                                            <?php foreach ($filteredStats as $stat): ?>
                                                <div class="card-stat" style="display: flex; flex-direction: column; gap: 4px;">
                                                    <span style="font-size: 10px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;"><?php echo htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <strong style="font-size: 18px; font-weight: 700; color: var(--text-primary);"><?php echo htmlspecialchars($stat['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                                    <?php if (!empty($stat['caption'])): ?>
                                                        <small style="font-size: 10px; color: var(--text-secondary);"><?php echo htmlspecialchars($stat['caption'], ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="card-metrics" style="display: flex; gap: 16px; margin: 12px 0;">
                                        <?php if ($card['revenue'] > 0 || $card['revenue_2026e'] > 0): ?>
                                        <div class="metric">
                                            <div class="metric-value"><?php echo $revenueFormatted; ?></div>
                                            <div class="metric-label">Выручка</div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($card['employees'] > 0): ?>
                                        <div class="metric">
                                            <div class="metric-value"><?php echo $card['employees']; ?></div>
                                            <div class="metric-label">Сотрудников</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="card-footer">
                                <div class="card-price">
                                    <span class="price-amount"><?php echo $priceFormatted; ?></span>
                                    <span class="price-label">Цена продажи</span>
                                </div>
                                <button class="card-button">Подробнее</button>
                            </div>

                            <div class="card-glow"></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="no-results" id="no-results" style="display: none;">
                <p>По вашему запросу ничего не найдено. Попробуйте изменить фильтры.</p>
            </div>
        </div>
    </section>

    <!-- Business Detail Modal -->
    <div class="modal-overlay" id="business-modal">
        <div class="modal-container">
            <button class="modal-close" aria-label="Close modal">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-icon-wrapper">
                        <div class="modal-icon" id="modal-icon">💼</div>
                    </div>
                    <div class="modal-title-section">
                        <h2 class="modal-title" id="modal-title">Название бизнеса</h2>
                        <p class="modal-location" id="modal-location">📍 Город</p>
                    </div>
                    <div class="modal-badge" id="modal-badge"></div>
                    <button class="modal-share-btn" id="modal-share-btn" title="Поделиться ссылкой" aria-label="Поделиться ссылкой">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M15 6.66667C16.3807 6.66667 17.5 5.54738 17.5 4.16667C17.5 2.78595 16.3807 1.66667 15 1.66667C13.6193 1.66667 12.5 2.78595 12.5 4.16667C12.5 4.63081 12.6315 5.0636 12.8619 5.43056L7.6381 8.56944C7.40764 8.20248 7.03026 7.91667 6.66667 7.91667C5.28595 7.91667 4.16667 9.03595 4.16667 10.4167C4.16667 11.7974 5.28595 12.9167 6.66667 12.9167C7.03026 12.9167 7.40764 12.6309 7.6381 12.2639L12.8619 15.4028C13.0924 15.0358 13.4697 14.75 13.8333 14.75C15.214 14.75 16.3333 15.8693 16.3333 17.25C16.3333 18.6307 15.214 19.75 13.8333 19.75C12.4526 19.75 11.3333 18.6307 11.3333 17.25C11.3333 16.7859 11.4648 16.3531 11.6953 15.9861L6.47139 12.8472C6.24089 13.2142 5.86351 13.5 5.5 13.5C4.11929 13.5 3 12.3807 3 11C3 9.61929 4.11929 8.5 5.5 8.5C5.86351 8.5 6.24089 8.78581 6.47139 9.15278L11.6953 6.01389C11.4648 5.64693 11.3333 5.21414 11.3333 4.75H15Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                <div class="modal-body">
                    <!-- Полный тизер в формате личного кабинета -->
                    <div class="teaser-section" id="modal-teaser-section">
                        <div class="teaser-result" id="modal-teaser-content">
                            <p style="text-align: center; color: var(--text-secondary); padding: 40px;">Загрузка тизера...</p>
                        </div>
                    </div>

                    <!-- Блок документов актива -->
                    <div class="modal-documents-section" id="modal-documents-section" style="display: none;">
                        <div class="modal-documents-header">
                            <h3>Документы</h3>
                                </div>
                        <div class="modal-documents-list" id="modal-documents-list">
                            <p style="text-align: center; color: var(--text-secondary); padding: 20px;">Загрузка документов...</p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" id="modal-close-btn">Закрыть</button>
                    <button class="btn btn-primary" id="modal-contact-btn">
                        <span>Связаться с продавцом</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- Seller Form Section -->
    <section class="seller-form-cta">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Продажа бизнеса через SmartBizSell</h2>
                <p class="section-subtitle">Анкета доступна только в личном кабинете. После заполнения вы получите автоматический DCF-анализ и сможете вернуться к данным в любой момент.</p>
            </div>
            <div style="text-align:center; margin-top: 32px;">
                <a class="btn btn-primary" href="<?php echo isLoggedIn() ? '/dashboard.php' : '/login.php'; ?>">Перейти в личный кабинет</a>
            </div>
        </div>
    </section>
    <!-- Contact Section -->
    <section id="contact" class="contact">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Свяжитесь с нами</h2>
                <p class="section-subtitle">Обсудим цели сделки, расскажем о подходе команды и покажем платформу в работе</p>
            </div>
            <div class="contact-grid">
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="4" y="6" width="24" height="20" rx="3" stroke="#6366F1" stroke-width="2" fill="none"/>
                            <path d="M4 10L16 18L28 10" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3>Email</h3>
                    <p>info@smartbizsell.ru</p>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="6" y="4" width="20" height="24" rx="4" stroke="#0EA5E9" stroke-width="2" fill="none"/>
                            <path d="M12 8H20M12 12H20M12 16H18" stroke="#0EA5E9" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3>Телефон</h3>
                    <p>+7 929 9373802</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <a href="#" class="footer-logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <p class="footer-text">
                    Экспертная M&amp;A команда, которая внедрила свой опыт в цифровую платформу и ИИ, чтобы проводить сделки быстрее, прозрачнее и выгоднее.
                </p>
                <div class="footer-links">
                    <div class="footer-links-column">
                        <h4>Навигация</h4>
                    <a href="#how-it-works">Как это работает</a>
                    <a href="#buy-business">Купить бизнес</a>
                    <a href="#seller-form">Продать бизнес</a>
                    <a href="#contact">Контакты</a>
                    </div>
                    <div class="footer-links-column">
                        <h4>Услуги</h4>
                        <a href="/services/sell-business">Продажа бизнеса</a>
                        <a href="/services/buy-business">Покупка бизнеса</a>
                        <a href="/services/valuation">Оценка бизнеса</a>
                        <a href="/services/ma-advisory">M&A консалтинг</a>
                    </div>
                    <div class="footer-links-column">
                        <h4>Информация</h4>
                        <a href="/blog">Блог</a>
                        <a href="/about">О нас</a>
                        <a href="/faq">FAQ</a>
                    </div>
                </div>
                <div class="footer-copyright">
                    <p>&copy; 2026 SmartBizSell.ru. Все права защищены.</p>
                </div>
            </div>
        </div>
    </footer>

    <!-- Структурированные данные (JSON-LD) для поисковых систем -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "Organization",
                "@id": "<?php echo BASE_URL; ?>/#organization",
                "name": "SmartBizSell",
                "url": "<?php echo BASE_URL; ?>",
                "logo": "<?php echo BASE_URL; ?>/logo.png",
                "description": "Экспертная M&A платформа с искусственным интеллектом для продажи и покупки бизнеса",
                "contactPoint": {
                    "@type": "ContactPoint",
                    "contactType": "Customer Service",
                    "email": "<?php echo ADMIN_EMAIL; ?>"
                },
                "sameAs": [
                    "https://www.smartbizsell.ru"
                ]
            },
            {
                "@type": "WebSite",
                "@id": "<?php echo BASE_URL; ?>/#website",
                "url": "<?php echo BASE_URL; ?>",
                "name": "SmartBizSell.ru",
                "description": "Экспертная M&A платформа с ИИ для продажи и покупки бизнеса",
                "publisher": {
                    "@id": "<?php echo BASE_URL; ?>/#organization"
                },
                "potentialAction": {
                    "@type": "SearchAction",
                    "target": {
                        "@type": "EntryPoint",
                        "urlTemplate": "<?php echo BASE_URL; ?>/buy-business?q={search_term_string}"
                    },
                    "query-input": "required name=search_term_string"
                }
            },
            {
                "@type": "Service",
                "@id": "<?php echo BASE_URL; ?>/#service",
                "name": "M&A услуги",
                "description": "Продажа и покупка бизнеса, оценка бизнеса, подготовка тизеров, финансовое моделирование, создание term sheet, поиск инвесторов",
                "provider": {
                    "@id": "<?php echo BASE_URL; ?>/#organization"
                },
                "serviceType": "M&A консалтинг",
                "areaServed": {
                    "@type": "Country",
                    "name": "Россия"
                },
                "hasOfferCatalog": {
                    "@type": "OfferCatalog",
                    "name": "Услуги SmartBizSell",
                    "itemListElement": [
                        {
                            "@type": "Offer",
                            "itemOffered": {
                                "@type": "Service",
                                "name": "Продажа бизнеса",
                                "description": "Подготовка бизнеса к продаже, создание тизера, поиск покупателей"
                            }
                        },
                        {
                            "@type": "Offer",
                            "itemOffered": {
                                "@type": "Service",
                                "name": "Покупка бизнеса",
                                "description": "Поиск и оценка бизнесов для покупки, due diligence"
                            }
                        },
                        {
                            "@type": "Offer",
                            "itemOffered": {
                                "@type": "Service",
                                "name": "Оценка бизнеса",
                                "description": "Оценка стоимости бизнеса методом DCF и мультипликаторов"
                            }
                        },
                        {
                            "@type": "Offer",
                            "itemOffered": {
                                "@type": "Service",
                                "name": "M&A консалтинг",
                                "description": "Консультации по сделкам слияний и поглощений"
                            }
                        }
                    ]
                }
            },
            {
                "@type": "BreadcrumbList",
                "@id": "<?php echo BASE_URL; ?>/#breadcrumb",
                "itemListElement": [
                    {
                        "@type": "ListItem",
                        "position": 1,
                        "name": "Главная",
                        "item": "<?php echo BASE_URL; ?>/"
                    }
                ]
            }
        ]
    }
    </script>

    <script src="/script.js?v=<?php echo time(); ?>"></script>
    <script>
        // Функция для извлечения ID бизнеса из URL
        function getBusinessIdFromUrl() {
            // Сначала проверяем GET-параметр (для совместимости)
            const urlParams = new URLSearchParams(window.location.search);
            const businessParam = urlParams.get('business');
            if (businessParam) {
                return parseInt(businessParam);
            }
            
            // Если параметра нет, проверяем путь /business/{id}
            const pathMatch = window.location.pathname.match(/\/business\/(\d+)/);
            if (pathMatch && pathMatch[1]) {
                return parseInt(pathMatch[1]);
            }
            
            return null;
        }
        
        // Получаем ID бизнеса из URL
        const businessIdFromUrl = getBusinessIdFromUrl();
        
        // Функция для открытия карточки по ID
        function openBusinessCardById(cardId) {
            console.log('Attempting to open business card with ID:', cardId);
            // Ищем карточку по data-teaser-id (приоритет) или data-id
            const cards = document.querySelectorAll('.business-card');
            console.log('Found', cards.length, 'business cards on page');
            
            // Логируем все карточки для отладки
            cards.forEach((card, index) => {
                const teaserId = card.getAttribute('data-teaser-id');
                const id = card.getAttribute('data-id');
                console.log(`Card ${index}: data-teaser-id="${teaserId}", data-id="${id}"`);
            });
            
            for (const card of cards) {
                const teaserId = card.getAttribute('data-teaser-id');
                const id = card.getAttribute('data-id');
                
                // Проверяем оба атрибута - приоритет у data-teaser-id
                const matchesTeaserId = teaserId && parseInt(teaserId) === parseInt(cardId);
                const matchesDataId = id && parseInt(id) === parseInt(cardId);
                
                if (matchesTeaserId || matchesDataId) {
                    console.log('Found matching card:', card);
                    console.log('  - data-teaser-id:', teaserId);
                    console.log('  - data-id:', id);
                    console.log('  - Looking for ID:', cardId);
                    // Открываем модальное окно сразу, без прокрутки
                    if (typeof openBusinessModal === 'function') {
                        openBusinessModal(card);
                        return true;
                    } else {
                        console.error('openBusinessModal function is not available');
                        return false;
                    }
                }
            }
            console.warn('Карточка с ID', cardId, 'не найдена. Проверенные карточки:', 
                Array.from(cards).map(c => ({
                    teaserId: c.getAttribute('data-teaser-id'),
                    id: c.getAttribute('data-id')
                }))
            );
            return false;
        }
        
        // Открываем карточку при загрузке страницы, если указан ID в URL
        if (businessIdFromUrl) {
            console.log('Business ID from URL:', businessIdFromUrl);
            
            // Ждем полной загрузки страницы, всех скриптов и карточек
            const tryOpenCard = (attempt = 0) => {
                const maxAttempts = 20; // Максимум 4 секунды (20 * 200ms)
                
                // Проверяем, что DOM загружен
                if (document.readyState === 'loading') {
                    console.log('Document still loading, waiting...');
                    setTimeout(() => tryOpenCard(attempt), 200);
                    return;
                }
                
                // Проверяем, что функция openBusinessModal доступна
                if (typeof openBusinessModal !== 'function') {
                    if (attempt < maxAttempts) {
                        console.log('openBusinessModal not available yet, attempt', attempt + 1);
                        setTimeout(() => tryOpenCard(attempt + 1), 200);
                        return;
                    } else {
                        console.error('openBusinessModal function not found after', maxAttempts, 'attempts');
                        return;
                    }
                }
                
                // Проверяем наличие карточек
                const cards = document.querySelectorAll('.business-card');
                if (cards.length === 0) {
                    if (attempt < maxAttempts) {
                        console.log('Cards not loaded yet, attempt', attempt + 1);
                        setTimeout(() => tryOpenCard(attempt + 1), 200);
                        return;
                    } else {
                        console.error('No business cards found after', maxAttempts, 'attempts');
                        return;
                    }
                }
                
                console.log('Cards loaded, attempting to open card with ID:', businessIdFromUrl);
                const opened = openBusinessCardById(businessIdFromUrl);
                
                if (!opened && attempt < maxAttempts) {
                    console.log('Card not found, retrying... attempt', attempt + 1);
                    setTimeout(() => tryOpenCard(attempt + 1), 200);
                }
            };
            
            // Запускаем попытки открытия карточки
            if (document.readyState === 'complete') {
                // Страница уже загружена
                setTimeout(() => tryOpenCard(0), 500);
            } else {
                // Ждем полной загрузки страницы
                window.addEventListener('load', () => {
                    setTimeout(() => tryOpenCard(0), 500);
                });
                
                // Также пробуем после DOMContentLoaded
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', () => {
                        setTimeout(() => tryOpenCard(0), 500);
                    });
                }
            }
        }
        
        // Обработка кнопок назад/вперед в браузере
        window.addEventListener('popstate', (event) => {
            console.log('Popstate event:', event.state);
            if (event.state && event.state.businessId) {
                // Открываем карточку при нажатии "назад"
                openBusinessCardById(event.state.businessId);
            } else {
                // Проверяем URL на наличие /business/{id}
                const businessId = getBusinessIdFromUrl();
                if (businessId) {
                    openBusinessCardById(businessId);
                } else {
                    // Закрываем модальное окно при нажатии "назад" без состояния
                    if (typeof closeBusinessModal === 'function') {
                        closeBusinessModal();
                    }
                }
            }
        });
    </script>
</body>
</html>

