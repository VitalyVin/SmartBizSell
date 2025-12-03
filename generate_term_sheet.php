<?php
/**
 * generate_term_sheet.php
 *
 * Генерация Term Sheet через ИИ на основе данных анкеты продавца.
 * 
 * Основные этапы:
 * 1. Проверка авторизации и наличия отправленной анкеты
 * 2. Сбор данных анкеты для формирования промпта
 * 3. Вызов Together.ai для генерации Term Sheet
 * 4. Очистка ответа от технической информации
 * 5. Форматирование документа
 * 6. Сохранение в БД и возврат HTML
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    exit;
}

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Сессия недействительна.']);
    exit;
}

$apiKey = TOGETHER_API_KEY;
if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'API-ключ together.ai не настроен.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем последнюю отправленную анкету Term Sheet
    $stmt = $pdo->prepare("
        SELECT *
        FROM term_sheet_forms
        WHERE user_id = ?
          AND status IN ('submitted','review','approved')
        ORDER BY submitted_at DESC, updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $form = $stmt->fetch();

    if (!$form) {
        echo json_encode(['success' => false, 'message' => 'Нет отправленных анкет Term Sheet для формирования документа. Заполните и отправьте анкету Term Sheet.']);
        exit;
    }

    // Собираем данные анкеты
    $formData = buildTermSheetPayload($form);
    
    // Проверяем полноту данных
    $missingFields = checkTermSheetCompleteness($formData);
    if (!empty($missingFields)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Для генерации Term Sheet необходимо заполнить следующие поля: ' . implode(', ', $missingFields) . '. Пожалуйста, отредактируйте анкету.',
            'missing_fields' => $missingFields
        ]);
        exit;
    }
    
    // Формируем промпт для ИИ
    $prompt = buildTermSheetPrompt($formData);
    
    // Вызываем ИИ
    $rawResponse = callTogetherCompletions($prompt, $apiKey);
    
    // Очищаем и форматируем ответ
    $termSheetContent = cleanAndFormatTermSheet($rawResponse);
    
    // Проверяем полноту документа и добавляем недостающие элементы
    $termSheetContent = ensureDocumentCompleteness($termSheetContent, $formData);
    
    // Генерируем HTML
    $html = renderTermSheetHtml($termSheetContent, $formData);
    
    // Сохраняем сгенерированный документ в data_json исходной анкеты, не перезаписывая данные формы
    $originalDataJson = !empty($form['data_json']) ? json_decode($form['data_json'], true) : [];
    if (!is_array($originalDataJson)) {
        $originalDataJson = [];
    }
    
    // Сохраняем сгенерированный документ отдельно, не перезаписывая исходные данные
    $originalDataJson['generated_document'] = [
        'content' => $termSheetContent,
        'html' => $html,
        'generated_at' => date('c'),
    ];
    
    $updatedDataJson = json_encode($originalDataJson, JSON_UNESCAPED_UNICODE);
    
    // Обновляем только поле data_json, сохраняя все остальные данные анкеты
    $stmt = $pdo->prepare("
        UPDATE term_sheet_forms 
        SET data_json = ?, 
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$updatedDataJson, $form['id'], $user['id']]);

    echo json_encode([
        'success' => true,
        'html' => $html,
        'generated_at' => date('c'),
    ]);
} catch (Exception $e) {
    error_log('Term Sheet generation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Не удалось создать Term Sheet. Попробуйте позже.']);
}

/**
 * Собирает данные анкеты Term Sheet для формирования документа.
 * 
 * Важно: Использует ТОЛЬКО данные из term_sheet_forms, не берет данные из seller_forms.
 * Это гарантирует, что Term Sheet создается на основе данных анкеты Term Sheet,
 * а не анкеты продавца.
 * 
 * @param array $form Массив данных из таблицы term_sheet_forms
 * @return array Массив данных для формирования промпта ИИ
 * 
 * Структура возвращаемых данных:
 * - buyers/sellers/assets: массивы с информацией о сторонах сделки
 * - deal_types: типы сделок (sale, investment)
 * - investment_purposes: направления инвестиций
 * - warranties: заверения об обстоятельствах
 * - closing_conditions: условия закрытия сделки
 * - corporate_governance_*: корпоративное управление
 * - _meta: метаданные (form_id, submitted_at)
 */
function buildTermSheetPayload(array $form): array
{
    $data = [];
    
    // Приоритетно используем data_json, так как там хранятся все данные формы
    // включая массивы покупателей, продавцов, активов и другие сложные структуры
    if (!empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        if (is_array($decoded)) {
            // Исключаем generated_document из исходных данных, чтобы не смешивать
            // исходные данные анкеты со сгенерированным документом
            foreach ($decoded as $key => $value) {
                if ($key !== 'generated_document') {
                    $data[$key] = $value;
                }
            }
        }
    }
    
    // Дополняем данными из отдельных полей таблицы (только если их нет в data_json)
    // Это нужно для обратной совместимости и случаев, когда данные хранятся в колонках
    $mapping = [
        'buyer_name' => 'buyer_name',
        'buyer_inn' => 'buyer_inn',
        'seller_name' => 'seller_name',
        'seller_inn' => 'seller_inn',
        'asset_name' => 'asset_name',
        'asset_inn' => 'asset_inn',
        'deal_type' => 'deal_type',
        'deal_share_percent' => 'deal_share_percent',
        'investment_amount' => 'investment_amount',
        'agreement_duration' => 'agreement_duration',
        'exclusivity' => 'exclusivity',
        'applicable_law' => 'applicable_law',
        'corporate_governance_ceo' => 'corporate_governance_ceo',
        'corporate_governance_cfo' => 'corporate_governance_cfo',
    ];
    
    foreach ($mapping as $key => $column) {
        if (empty($data[$key]) && !empty($form[$column])) {
            $data[$key] = $form[$column];
        }
    }
    
    // Добавляем метаданные
    $data['_meta'] = [
        'form_id' => $form['id'],
        'submitted_at' => $form['submitted_at'] ?? null,
    ];
    
    return $data;
}

/**
 * Проверяет полноту данных анкеты Term Sheet перед генерацией документа.
 * 
 * Проверяет наличие обязательных полей:
 * - Покупатель (buyers или buyer_name)
 * - Продавец (sellers или seller_name)
 * - Актив (assets или asset_name)
 * - Тип сделки (deal_types или deal_type)
 * 
 * @param array $formData Данные анкеты Term Sheet
 * @return array Массив названий недостающих обязательных полей (на русском языке)
 *              Если все поля заполнены, возвращает пустой массив
 */
function checkTermSheetCompleteness(array $formData): array
{
    $missingFields = [];
    
    // Проверяем наличие покупателей
    // Поддерживаем как массив buyers, так и одиночное поле buyer_name
    $hasBuyers = false;
    if (!empty($formData['buyers']) && is_array($formData['buyers'])) {
        foreach ($formData['buyers'] as $buyer) {
            if (!empty($buyer['name'])) {
                $hasBuyers = true;
                break;
            }
        }
    }
    if (!$hasBuyers && empty($formData['buyer_name'])) {
        $missingFields[] = 'Покупатель';
    }
    
    // Проверяем наличие продавцов
    $hasSellers = false;
    if (!empty($formData['sellers']) && is_array($formData['sellers'])) {
        foreach ($formData['sellers'] as $seller) {
            if (!empty($seller['name'])) {
                $hasSellers = true;
                break;
            }
        }
    }
    if (!$hasSellers && empty($formData['seller_name'])) {
        $missingFields[] = 'Продавец';
    }
    
    // Проверяем наличие активов
    $hasAssets = false;
    if (!empty($formData['assets']) && is_array($formData['assets'])) {
        foreach ($formData['assets'] as $asset) {
            if (!empty($asset['name'])) {
                $hasAssets = true;
                break;
            }
        }
    }
    if (!$hasAssets && empty($formData['asset_name'])) {
        $missingFields[] = 'Актив';
    }
    
    // Проверяем тип сделки
    $hasDealType = false;
    if (!empty($formData['deal_types']) && is_array($formData['deal_types']) && !empty($formData['deal_types'])) {
        $hasDealType = true;
    } elseif (!empty($formData['deal_type'])) {
        $hasDealType = true;
    }
    if (!$hasDealType) {
        $missingFields[] = 'Тип сделки';
    }
    
    return $missingFields;
}

/**
 * Формирует детальный промпт для генерации Term Sheet через ИИ.
 * 
 * Собирает все данные из анкеты Term Sheet и структурирует их в виде
 * текстового промпта для ИИ-модели. Промпт включает:
 * - Детали сторон сделки (покупатель, продавец, актив)
 * - Тип сделки и условия (продажа доли, привлечение инвестиций)
 * - Срок действия соглашения и эксклюзивность
 * - Заверения об обстоятельствах
 * - Условия закрытия сделки
 * - Корпоративное управление после сделки
 * - Преимущественное право
 * 
 * @param array $formData Данные анкеты Term Sheet
 * @return string Готовый промпт для отправки в ИИ-модель
 */
function buildTermSheetPrompt(array $formData): string
{
    // Собираем информацию о покупателях
    // Поддерживаем множественных покупателей (массив) и одиночного (поле)
    $buyers = [];
    if (!empty($formData['buyers']) && is_array($formData['buyers'])) {
        foreach ($formData['buyers'] as $buyer) {
            $buyerInfo = [];
            if (!empty($buyer['name'])) $buyerInfo[] = "ЮЛ: " . $buyer['name'];
            if (!empty($buyer['inn'])) $buyerInfo[] = "ИНН: " . $buyer['inn'];
            if (!empty($buyer['individual_name'])) $buyerInfo[] = "ФЛ: " . $buyer['individual_name'];
            if (!empty($buyer['individual_inn'])) $buyerInfo[] = "ИНН ФЛ: " . $buyer['individual_inn'];
            if (!empty($buyerInfo)) {
                $buyers[] = implode(", ", $buyerInfo);
            }
        }
    } else {
        if (!empty($formData['buyer_name'])) {
            $buyerInfo = "ЮЛ: " . $formData['buyer_name'];
            if (!empty($formData['buyer_inn'])) {
                $buyerInfo .= ", ИНН: " . $formData['buyer_inn'];
            }
            $buyers[] = $buyerInfo;
        }
    }
    $buyersText = !empty($buyers) ? implode("; ", $buyers) : "не указан";
    
    // Собираем информацию о продавцах
    $sellers = [];
    if (!empty($formData['sellers']) && is_array($formData['sellers'])) {
        foreach ($formData['sellers'] as $seller) {
            $sellerInfo = [];
            if (!empty($seller['name'])) $sellerInfo[] = "ЮЛ: " . $seller['name'];
            if (!empty($seller['inn'])) $sellerInfo[] = "ИНН: " . $seller['inn'];
            if (!empty($seller['individual_name'])) $sellerInfo[] = "ФЛ: " . $seller['individual_name'];
            if (!empty($seller['individual_inn'])) $sellerInfo[] = "ИНН ФЛ: " . $seller['individual_inn'];
            if (!empty($sellerInfo)) {
                $sellers[] = implode(", ", $sellerInfo);
            }
        }
    } else {
        if (!empty($formData['seller_name'])) {
            $sellerInfo = "ЮЛ: " . $formData['seller_name'];
            if (!empty($formData['seller_inn'])) {
                $sellerInfo .= ", ИНН: " . $formData['seller_inn'];
            }
            $sellers[] = $sellerInfo;
        }
    }
    $sellersText = !empty($sellers) ? implode("; ", $sellers) : "не указан";
    
    // Собираем информацию об активах
    $assets = [];
    if (!empty($formData['assets']) && is_array($formData['assets'])) {
        foreach ($formData['assets'] as $asset) {
            $assetInfo = [];
            if (!empty($asset['name'])) $assetInfo[] = $asset['name'];
            if (!empty($asset['inn'])) $assetInfo[] = "ИНН: " . $asset['inn'];
            if (!empty($assetInfo)) {
                $assets[] = implode(", ", $assetInfo);
            }
        }
    } else {
        if (!empty($formData['asset_name'])) {
            $assetInfo = $formData['asset_name'];
            if (!empty($formData['asset_inn'])) {
                $assetInfo .= ", ИНН: " . $formData['asset_inn'];
            }
            $assets[] = $assetInfo;
        }
    }
    $assetsText = !empty($assets) ? implode("; ", $assets) : "не указан";
    
    // Тип сделки
    $dealTypes = [];
    if (!empty($formData['deal_types']) && is_array($formData['deal_types'])) {
        foreach ($formData['deal_types'] as $type) {
            if ($type === 'sale') {
                $dealTypes[] = "Продажа доли актива";
                if (!empty($formData['deal_share_percent'])) {
                    $dealTypes[count($dealTypes) - 1] .= " (" . $formData['deal_share_percent'] . "%)";
                }
            } elseif ($type === 'investment') {
                $dealTypes[] = "Привлечение инвестиций (cash-in)";
                if (!empty($formData['investment_amount'])) {
                    $dealTypes[count($dealTypes) - 1] .= " в размере " . $formData['investment_amount'] . " млн руб.";
                }
            }
        }
    } elseif (!empty($formData['deal_type'])) {
        $dealTypes[] = $formData['deal_type'];
    }
    $dealTypeText = !empty($dealTypes) ? implode("; ", $dealTypes) : "не указан";
    
    // Направление инвестиций
    $investmentPurposes = [];
    if (!empty($formData['investment_purposes']) && is_array($formData['investment_purposes'])) {
        $purposeMap = [
            'development' => 'Развитие бизнеса (капитальные затраты и т.п.)',
            'working_capital' => 'Пополнение оборотного капитала',
            'debt_repayment' => 'Погашение кредитов и займов от третьих сторон',
        ];
        foreach ($formData['investment_purposes'] as $purpose) {
            if (isset($purposeMap[$purpose])) {
                $investmentPurposes[] = $purposeMap[$purpose];
            }
        }
    }
    $investmentPurposesText = !empty($investmentPurposes) ? implode("; ", $investmentPurposes) : "";
    
    // Срок действия соглашения
    $agreementDuration = $formData['agreement_duration'] ?? 3;
    
    // Эксклюзивность
    $exclusivity = !empty($formData['exclusivity']) && $formData['exclusivity'] === 'yes' ? 'Да' : 'Нет';
    
    // Применимое право
    $applicableLaw = $formData['applicable_law'] ?? 'российское право';
    
    // Заверения об обстоятельствах
    $warranties = [];
    if (!empty($formData['warranties']) && is_array($formData['warranties'])) {
        $warrantyMap = [
            'legal_capacity' => 'Правоспособности (юридической возможности совершения сделки)',
            'title' => 'Титула на приобретаемые доли Актива',
            'tax' => 'Налоговых вопросов',
            'litigation' => 'Судебных и административных споров Актива',
            'compliance' => 'Соблюдения применимого законодательства',
            'other' => 'Иные заверения, согласованные Сторонами',
        ];
        foreach ($formData['warranties'] as $key => $value) {
            if (!empty($value) && isset($warrantyMap[$key])) {
                $warranties[] = $warrantyMap[$key];
            }
        }
    }
    $warrantiesText = !empty($warranties) ? implode("; ", $warranties) : "стандартные заверения для M&A сделок";
    
    // Условия закрытия сделки
    $closingConditions = [];
    if (!empty($formData['closing_conditions']) && is_array($formData['closing_conditions'])) {
        foreach ($formData['closing_conditions'] as $condition) {
            if (!empty($condition)) {
                $closingConditions[] = $condition;
            }
        }
    }
    $closingConditionsText = !empty($closingConditions) ? implode("; ", $closingConditions) : "стандартные условия закрытия сделки";
    
    // Корпоративное управление
    $ceoAppointment = '';
    if (!empty($formData['corporate_governance_ceo'])) {
        $ceoMap = [
            'buyer' => 'Покупателем',
            'seller' => 'Текущими акционерами',
            'unanimous' => 'Единогласно',
        ];
        $ceoAppointment = $ceoMap[$formData['corporate_governance_ceo']] ?? '';
    }
    
    $cfoAppointment = '';
    if (!empty($formData['corporate_governance_cfo'])) {
        $cfoMap = [
            'buyer' => 'Покупателем',
            'seller' => 'Текущими акционерами',
            'unanimous' => 'Единогласно',
        ];
        $cfoAppointment = $cfoMap[$formData['corporate_governance_cfo']] ?? '';
    }
    
    // Вопросы, требующие единогласного решения
    $unanimousDecisions = [];
    if (!empty($formData['unanimous_decisions_list']) && is_array($formData['unanimous_decisions_list'])) {
        $decisionMap = [
            'charter' => 'Изменение устава и уставного капитала',
            'budget' => 'Утверждение бюджета / бизнес-плана',
            'dividends' => 'Распределение чистой прибыли и дивидендная политика',
            'major_transactions' => 'Совершение крупных сделок',
            'real_estate' => 'Совершение сделок с недвижимостью',
            'ip' => 'Совершение сделок с интеллектуальной собственностью',
            'litigation' => 'Вопросы, связанные с участием в судебных процессах',
            'executive_compensation' => 'Утверждение условий трудовых договоров с топ-менеджерами',
            'subsidiaries' => 'Принятие решений об участии в уставных капиталах иных компаний',
            'debt' => 'Совершение сделок, влекущих возникновение финансовой задолженности',
            'guarantees' => 'Предоставление обеспечений по обязательствам третьих лиц',
            'financing' => 'Предоставление финансирования третьим лицам',
        ];
        foreach ($formData['unanimous_decisions_list'] as $key => $value) {
            if (!empty($value) && isset($decisionMap[$key])) {
                $unanimousDecisions[] = $decisionMap[$key];
            }
        }
    }
    $unanimousDecisionsText = !empty($unanimousDecisions) ? implode("; ", $unanimousDecisions) : "";
    
    // Преимущественное право
    $preemptiveRight = !empty($formData['preemptive_right']) ? 'Да' : 'Нет';
    
    $prompt = "Ты — профессиональный M&A консультант. Создай Term Sheet (лист условий сделки) на основе следующих данных из анкеты:

ДЕТАЛИ ПРЕДПОЛАГАЕМОЙ СДЕЛКИ:
Покупатель: {$buyersText}
Продавец: {$sellersText}
Актив: {$assetsText}

ТИП СДЕЛКИ: {$dealTypeText}";

    if (!empty($investmentPurposesText)) {
        $prompt .= "\nНаправление инвестиций: {$investmentPurposesText}";
    }

    $prompt .= "\n\nСРОК ДЕЙСТВИЯ СОГЛАШЕНИЯ: {$agreementDuration} месяцев
ЭКСКЛЮЗИВНОСТЬ: {$exclusivity}
ПРИМЕНИМОЕ ПРАВО: {$applicableLaw}

ЗАВЕРЕНИЯ ОБ ОБСТОЯТЕЛЬСТВАХ:
{$warrantiesText}

УСЛОВИЯ ЗАКРЫТИЯ СДЕЛКИ:
{$closingConditionsText}";

    if (!empty($ceoAppointment) || !empty($cfoAppointment)) {
        $prompt .= "\n\nКОРПОРАТИВНОЕ УПРАВЛЕНИЕ ПОСЛЕ СДЕЛКИ:";
        if (!empty($ceoAppointment)) {
            $prompt .= "\nГенеральный директор назначается: {$ceoAppointment}";
        }
        if (!empty($cfoAppointment)) {
            $prompt .= "\nФинансовый директор назначается: {$cfoAppointment}";
        }
        if (!empty($unanimousDecisionsText)) {
            $prompt .= "\nВопросы, требующие единогласного решения: {$unanimousDecisionsText}";
        }
    }

    $prompt .= "\n\nПРЕИМУЩЕСТВЕННОЕ ПРАВО: {$preemptiveRight}

ТРЕБОВАНИЯ К TERM SHEET:

ЮРИДИЧЕСКАЯ ТОЧНОСТЬ И ТРАДИЦИОННЫЕ ФОРМУЛИРОВКИ:
1. Используй классическую юридическую терминологию, принятую в российской корпоративной практике:
   - Стороны, Покупатель, Продавец, Актив, Сделка, Закрытие Сделки
   - Участники, Акционеры, Уставный капитал, Доля участия
   - Заверения об обстоятельствах, Гарантии, Представления
   - Условия отлагательные, Условия отменительные
   - Преимущественное право приобретения, Tag-along, Drag-along
   - Корпоративное управление, Органы управления, Единогласное решение
   - Применимое право, Подсудность, Разрешение споров

2. Используй традиционные юридические конструкции и формулировки:
   - Стороны пришли к соглашению о нижеследующем
   - Настоящий Term Sheet определяет основные условия предполагаемой Сделки
   - Права и обязанности Сторон возникают при наступлении следующих условий
   - Стороны обязуются, Стороны гарантируют, Стороны подтверждают
   - В случае если, При условии, При соблюдении следующих требований
   - Стороны признают и соглашаются, Стороны подтверждают, что
   - Настоящий Term Sheet не является обязывающим соглашением, за исключением...
   - Детальные условия Сделки будут закреплены в окончательных документах

3. Структура документа должна включать следующие разделы с детальными формулировками (ОБЯЗАТЕЛЬНО ВСЕ РАЗДЕЛЫ):
   - ДЕТАЛИ ПРЕДПОЛАГАЕМОЙ СДЕЛКИ (полные наименования, ИНН, правовая форма)
   - ТИП СДЕЛКИ И УСЛОВИЯ (детальное описание структуры сделки, размер доли, цена)
   - СРОК ДЕЙСТВИЯ СОГЛАШЕНИЯ (точные даты, условия продления)
   - ЭКСКЛЮЗИВНОСТЬ (обязательства сторон, исключения)
   - ЗАВЕРЕНИЯ ОБ ОБСТОЯТЕЛЬСТВАХ (детальный перечень с традиционными формулировками)
   - УСЛОВИЯ ЗАКРЫТИЯ СДЕЛКИ (отлагательные условия, документы, процедуры)
   - ПРИМЕНИМОЕ ПРАВО И РАЗРЕШЕНИЕ СПОРОВ (российское право, арбитраж, подсудность)
   - КОРПОРАТИВНОЕ УПРАВЛЕНИЕ ПОСЛЕ СДЕЛКИ (детальное описание механизмов управления, если применимо)
   - ПРЕИМУЩЕСТВЕННОЕ ПРАВО (механизм реализации, условия)
   - ПОДПИСИ СТОРОН (обязательный раздел в конце документа с полями для подписей Покупателя и Продавца)

4. Каждый раздел должен содержать:
   - Детальное описание с использованием юридических терминов
   - Точные формулировки, соответствующие российской корпоративной практике
   - Указание на правовые последствия и обязательства сторон
   - Ссылки на применимое законодательство (при необходимости)

5. Стиль и язык:
   - Документ должен быть на русском языке
   - Используй официально-деловой стиль
   - Избегай разговорных выражений и упрощений
   - Каждое положение должно быть сформулировано четко и однозначно
   - Используй нумерацию и подпункты для структурирования информации

6. Технические требования:
   - Не используй технические комментарии, метки или служебную информацию
   - Не используй markdown разметку (```, **, # и т.д.)
   - Текст должен быть готов для прямого использования в документе
   - Используй точные данные из анкеты, не выдумывай информацию
   - Если каких-то данных не хватает, используй стандартные формулировки для M&A сделок

7. Объем и детализация:
   - Документ должен быть достаточно подробным и содержательным
   - Каждый раздел должен раскрывать тему максимально полно
   - Используй традиционные юридические конструкции для полноты описания

8. ОБЯЗАТЕЛЬНАЯ ПОЛНОТА ДОКУМЕНТА:
   - Документ должен быть ПОЛНЫМ и ЗАВЕРШЕННЫМ
   - ВСЕ разделы из пункта 3 должны быть обязательно включены
   - В конце документа ОБЯЗАТЕЛЬНО должен быть раздел ПОДПИСИ СТОРОН со следующей структурой:
     ПОДПИСИ СТОРОН
     
     Покупатель:
     _________________ / _________________
     (подпись)          (ФИО)
     
     Продавец:
     _________________ / _________________
     (подпись)          (ФИО)
     
     Дата: _______________
   
   - НЕ прерывай документ на середине - он должен быть полностью завершен
   - Убедись, что документ содержит все необходимые разделы от начала до конца
   - Документ должен быть готов к использованию и подписанию

Создай Term Sheet в виде структурированного текста, готового для использования в документе. Каждый раздел должен начинаться с заголовка и содержать детальную информацию на основе предоставленных данных, используя традиционные юридические формулировки и терминологию, принятую в российской корпоративной практике. 

КРИТИЧЕСКИ ВАЖНО: Документ должен быть ПОЛНЫМ и ЗАВЕРШЕННЫМ, включая обязательный раздел ПОДПИСИ СТОРОН в конце. Не прерывай генерацию документа - он должен содержать все разделы от начала до конца.";

    return $prompt;
}

/**
 * Вызывает Together.ai API для генерации Term Sheet.
 * 
 * Отправляет промпт в ИИ-модель и получает сгенерированный текст Term Sheet.
 * Использует модель, указанную в константе TOGETHER_MODEL.
 * 
 * @param string $prompt Промпт с данными анкеты для генерации Term Sheet
 * @param string $apiKey API ключ для доступа к Together.ai
 * @return string Сгенерированный текст Term Sheet
 * @throws Exception Если произошла ошибка при обращении к API или неожиданный формат ответа
 */
function callTogetherCompletions(string $prompt, string $apiKey): string
{
    $url = 'https://api.together.xyz/v1/chat/completions';
    
    // Формируем запрос к API
    // system message задает роль ИИ как профессионального M&A консультанта
    // user message содержит детальный промпт с данными анкеты
    $data = [
        'model' => TOGETHER_MODEL, // Модель из конфигурации (например, Qwen2.5)
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты опытный M&A консультант и корпоративный юрист с глубоким знанием российского корпоративного права и практики инвестиционных сделок. Твоя специализация — создание Term Sheet для M&A и инвестиционных сделок. Твои документы всегда на русском языке, используют традиционную юридическую терминологию, точные формулировки и соответствуют лучшим практикам российского корпоративного права. КРИТИЧЕСКИ ВАЖНО: Всегда создавай ПОЛНЫЙ и ЗАВЕРШЕННЫЙ документ, включая обязательный раздел ПОДПИСИ СТОРОН в конце. Документ должен быть готов к использованию и подписанию.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7, // Баланс между креативностью и точностью
        'max_tokens' => 12000, // Максимальная длина ответа (увеличено для гарантии полного документа с подписями)
    ];
    
    // Выполняем HTTP запрос к API через cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Возвращать результат, а не выводить
    curl_setopt($ch, CURLOPT_POST, true); // POST запрос
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // JSON тело запроса
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey, // API ключ в заголовке
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Таймаут 120 секунд (генерация может занять время)
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Проверяем HTTP статус код
    if ($httpCode !== 200) {
        error_log("Together.ai API error: HTTP $httpCode, Response: $response");
        throw new Exception("Ошибка при обращении к API ИИ");
    }
    
    // Парсим JSON ответ и извлекаем сгенерированный текст
    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        error_log("Together.ai API unexpected response: " . $response);
        throw new Exception("Неожиданный формат ответа от API ИИ");
    }
    
    // Возвращаем сгенерированный контент
    return $result['choices'][0]['message']['content'];
}

/**
 * Проверяет полноту документа и добавляет недостающие элементы.
 * 
 * Проверяет наличие обязательных разделов, особенно раздела с подписями.
 * Если раздел с подписями отсутствует, добавляет его в конец документа.
 * 
 * @param string $content Текст Term Sheet
 * @param array $formData Данные анкеты (для извлечения имен сторон)
 * @return string Полный текст Term Sheet с обязательными разделами
 */
function ensureDocumentCompleteness(string $content, array $formData): string
{
    $content = trim($content);
    
    // Проверяем, есть ли раздел с подписями
    $hasSignatures = preg_match('/ПОДПИСИ\s+СТОРОН|подписи\s+сторон|Подписи\s+сторон/ui', $content);
    
    if (!$hasSignatures) {
        // Получаем имена сторон для раздела подписей
        $buyerName = 'Покупатель';
        if (!empty($formData['buyers']) && is_array($formData['buyers']) && !empty($formData['buyers'][0]['name'])) {
            $buyerName = $formData['buyers'][0]['name'];
        } elseif (!empty($formData['buyer_name'])) {
            $buyerName = $formData['buyer_name'];
        }
        
        $sellerName = 'Продавец';
        if (!empty($formData['sellers']) && is_array($formData['sellers']) && !empty($formData['sellers'][0]['name'])) {
            $sellerName = $formData['sellers'][0]['name'];
        } elseif (!empty($formData['seller_name'])) {
            $sellerName = $formData['seller_name'];
        }
        
        // Добавляем раздел с подписями в конец документа
        $signaturesSection = "\n\nПОДПИСИ СТОРОН\n\n" .
            $buyerName . ":\n" .
            "_________________ / _________________\n" .
            "(подпись)          (ФИО)\n\n" .
            $sellerName . ":\n" .
            "_________________ / _________________\n" .
            "(подпись)          (ФИО)\n\n" .
            "Дата: _______________";
        
        $content .= $signaturesSection;
    }
    
    return $content;
}

/**
 * Очищает ответ ИИ от технической информации и форматирует для использования.
 * 
 * Удаляет:
 * - Markdown разметку (```, **, #, `)
 * - HTML комментарии и технические теги
 * - Служебные фразы ИИ ("Как ИИ-ассистент", "Я создал" и т.д.)
 * 
 * Нормализует:
 * - Множественные переносы строк (более 2 подряд)
 * - Пробелы и отступы
 * 
 * @param string $rawResponse Сырой ответ от ИИ-модели
 * @return string Очищенный и отформатированный текст Term Sheet
 */
function cleanAndFormatTermSheet(string $rawResponse): string
{
    // Удаляем markdown разметку (блоки кода, жирный текст, заголовки, inline код)
    $cleaned = preg_replace('/```[\s\S]*?```/', '', $rawResponse);
    $cleaned = preg_replace('/\*\*(.*?)\*\*/', '$1', $cleaned);
    $cleaned = preg_replace('/\*(.*?)\*/', '$1', $cleaned);
    $cleaned = preg_replace('/#+\s*/', '', $cleaned);
    $cleaned = preg_replace('/`(.*?)`/', '$1', $cleaned);
    
    // Удаляем технические комментарии
    $cleaned = preg_replace('/<!--[\s\S]*?-->/', '', $cleaned);
    $cleaned = preg_replace('/\/\*[\s\S]*?\*\//', '', $cleaned);
    $cleaned = preg_replace('/\/\/.*$/m', '', $cleaned);
    
    // Удаляем служебные фразы ИИ
    $aiPhrases = [
        'Как ИИ-ассистент',
        'Как AI-ассистент',
        'Я создал',
        'Вот Term Sheet',
        'Ниже представлен',
        'Созданный документ',
        'Документ включает',
    ];
    
    foreach ($aiPhrases as $phrase) {
        $cleaned = preg_replace('/' . preg_quote($phrase, '/') . '[\s\S]*?(?=\n\n|\n[А-Я]|$)/i', '', $cleaned);
    }
    
    // Нормализуем пробелы
    $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
    $cleaned = trim($cleaned);
    
    return $cleaned;
}

/**
 * Рендерит HTML для отображения Term Sheet в браузере.
 * 
 * Преобразует текстовый контент в структурированный HTML с:
 * - Заголовком документа
 * - Разделами (H2) и подразделами (H3)
 * - Параграфами текста
 * - Футером с датой создания
 * 
 * Автоматически определяет заголовки по паттернам:
 * - H2: строки из заглавных букв (русский алфавит) длиной до 100 символов
 * - H3: строки, заканчивающиеся на ":" длиной до 80 символов
 * 
 * @param string $content Очищенный текстовый контент Term Sheet
 * @param array $formData Данные анкеты (используются для метаданных)
 * @return string HTML код для отображения Term Sheet
 */
function renderTermSheetHtml(string $content, array $formData): string
{
    // Разбиваем контент на разделы по двойным переносам строк
    $sections = explode("\n\n", $content);
    
    // Начало HTML документа с базовыми стилями
    $html = '<div class="term-sheet-document" style="background: white; border-radius: 20px; padding: 48px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 1200px; width: 100%; margin: 0 auto; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, sans-serif; line-height: 1.8; color: #1a1a1a;">';
    
    // Заголовок документа (Term Sheet)
    $html .= '<div style="text-align: center; margin-bottom: 48px; padding-bottom: 32px; border-bottom: 3px solid #10B981;">';
    $html .= '<h1 style="font-size: 36px; font-weight: 800; margin-bottom: 12px; color: #1a1a1a;">Term Sheet</h1>';
    $html .= '<p style="font-size: 18px; color: #666; margin: 0;">Лист условий сделки</p>';
    $html .= '</div>';
    
    // Основной контент документа
    $html .= '<div class="term-sheet-content" style="font-size: 16px;">';
    
    foreach ($sections as $section) {
        $section = trim($section);
        if (empty($section)) {
            continue; // Пропускаем пустые разделы
        }
        
        // Проверяем, является ли раздел заголовком первого уровня (H2)
        // Паттерн: строка из заглавных русских букв, цифр, пробелов и точек
        if (preg_match('/^[А-ЯЁ][А-ЯЁ\s\d\.]+$/u', $section) && mb_strlen($section) < 100) {
            $html .= '<h2 style="font-size: 24px; font-weight: 700; margin-top: 40px; margin-bottom: 20px; color: #1a1a1a; padding-bottom: 12px; border-bottom: 2px solid #e9ecef;">' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '</h2>';
        } else {
            // Обычный текст - разбиваем на параграфы по одинарным переносам строк
            $paragraphs = explode("\n", $section);
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if (!empty($para)) {
                    // Проверяем, является ли это подзаголовком второго уровня (H3)
                    // Паттерн: строка, начинающаяся с заглавной буквы и заканчивающаяся на ":"
                    if (preg_match('/^[А-ЯЁ][а-яё\s\d\.]+:$/u', $para) && mb_strlen($para) < 80) {
                        $html .= '<h3 style="font-size: 18px; font-weight: 600; margin-top: 24px; margin-bottom: 12px; color: #333;">' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '</h3>';
                    } else {
                        $html .= '<p style="margin-bottom: 16px; line-height: 1.8;">' . nl2br(htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
                    }
                }
            }
        }
    }
    
    $html .= '</div>'; // Закрываем контент
    
    // Футер с информацией о документе
    $html .= '<div style="margin-top: 48px; padding-top: 32px; border-top: 2px solid #e9ecef; text-align: center; color: #666; font-size: 14px;">';
    $html .= '<p>Документ создан автоматически на основе данных анкеты</p>';
    $html .= '<p style="margin-top: 8px;">Дата создания: ' . date('d.m.Y H:i') . '</p>';
    $html .= '</div>';
    
    $html .= '</div>'; // Закрываем основной контейнер
    
    return $html;
}

