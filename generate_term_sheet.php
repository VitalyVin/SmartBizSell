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

// Получаем API ключ в зависимости от выбранного провайдера
$provider = getCurrentAIProvider();
if ($provider === 'alibaba') {
    $apiKey = ALIBABA_API_KEY;
    if (empty($apiKey)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'API-ключ Alibaba Cloud не настроен.']);
        exit;
    }
} else {
    $apiKey = TOGETHER_API_KEY;
    if (empty($apiKey)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'API-ключ together.ai не настроен.']);
        exit;
    }
}

try {
    $pdo = getDBConnection();
    
    // Получаем последнюю отправленную анкету Term Sheet для текущего пользователя
    // Ищем только анкеты со статусом 'submitted', 'review' или 'approved'
    // Сортируем по дате отправки и обновления (самые свежие первыми)
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

    // Собираем данные анкеты в единый массив для дальнейшей обработки
    // Функция buildTermSheetPayload извлекает данные из data_json и отдельных полей таблицы
    $formData = buildTermSheetPayload($form);
    
    // Проверяем полноту данных перед генерацией документа
    // Если отсутствуют обязательные поля, возвращаем ошибку с перечнем недостающих полей
    $missingFields = checkTermSheetCompleteness($formData);
    if (!empty($missingFields)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Для генерации Term Sheet необходимо заполнить следующие поля: ' . implode(', ', $missingFields) . '. Пожалуйста, отредактируйте анкету.',
            'missing_fields' => $missingFields
        ]);
        exit;
    }
    
    // Формируем детальный промпт для ИИ на основе данных анкеты
    // Промпт включает все необходимые разделы Term Sheet с конкретными данными
    $prompt = buildTermSheetPrompt($formData);
    
    // Вызываем ИИ-модель через Together.ai API для генерации Term Sheet
    // Используется модель, указанная в конфигурации (например, Qwen2.5)
    // Используем chat completions с system message для Term Sheet
    $systemMessage = 'Ты опытный M&A консультант и корпоративный юрист с глубоким знанием российского корпоративного права и практики инвестиционных сделок. Твоя специализация — создание Term Sheet для M&A и инвестиционных сделок. Твои документы всегда на русском языке, используют традиционную юридическую терминологию, точные формулировки и соответствуют лучшим практикам российского корпоративного права. КРИТИЧЕСКИ ВАЖНО: Всегда создавай ПОЛНЫЙ и ЗАВЕРШЕННЫЙ документ, включая обязательный раздел ПОДПИСИ СТОРОН в конце. Документ должен быть готов к использованию и подписанию.';
    $rawResponse = callAICompletions($prompt, $apiKey, 3, $systemMessage);
    
    // Проверяем, что ответ от API не пустой
    if (empty($rawResponse) || trim($rawResponse) === '') {
        error_log("Term Sheet generation: Empty response from API");
        throw new Exception("Получен пустой ответ от API ИИ. Попробуйте снова.");
    }
    
    // Очищаем ответ ИИ от технической информации (markdown, служебные фразы)
    // и форматируем для использования в документе
    $termSheetContent = cleanAndFormatTermSheet($rawResponse);
    
    // Проверяем, что после очистки контент не пустой
    if (empty($termSheetContent) || trim($termSheetContent) === '') {
        error_log("Term Sheet generation: Empty content after cleaning. Raw response length: " . strlen($rawResponse));
        // Если контент стал пустым после очистки, используем исходный ответ
        $termSheetContent = trim($rawResponse);
        // Если и исходный ответ пустой, выбрасываем исключение
        if (empty($termSheetContent)) {
            throw new Exception("После обработки ответа от ИИ получен пустой документ. Попробуйте снова.");
        }
    }
    
    // Проверяем полноту документа и добавляем недостающие элементы
    // Например, если ИИ не добавил раздел с подписями, добавляем его программно
    $termSheetContent = ensureDocumentCompleteness($termSheetContent, $formData);
    
    // Финальная проверка: документ должен содержать хотя бы минимальный контент
    if (empty($termSheetContent) || trim($termSheetContent) === '' || mb_strlen(trim($termSheetContent)) < 100) {
        error_log("Term Sheet generation: Document too short after processing. Length: " . mb_strlen($termSheetContent));
        throw new Exception("Сгенерированный документ слишком короткий или пустой. Попробуйте снова.");
    }
    
    // Генерируем HTML для отображения Term Sheet в браузере
    // Документ форматируется как таблица с двумя колонками (25%/75%)
    $html = renderTermSheetHtml($termSheetContent, $formData);
    
    // Сохраняем сгенерированный документ в data_json исходной анкеты
    // Важно: не перезаписываем исходные данные формы, только добавляем generated_document
    $originalDataJson = !empty($form['data_json']) ? json_decode($form['data_json'], true) : [];
    if (!is_array($originalDataJson)) {
        $originalDataJson = [];
    }
    
    // Сохраняем сгенерированный документ отдельно в структуре generated_document
    // Это позволяет хранить исходные данные анкеты и сгенерированный документ раздельно
    $originalDataJson['generated_document'] = [
        'content' => $termSheetContent,  // Текстовый контент для DOCX
        'html' => $html,                  // HTML для отображения в браузере
        'generated_at' => date('c'),      // Время генерации в формате ISO 8601
    ];
    
    $updatedDataJson = json_encode($originalDataJson, JSON_UNESCAPED_UNICODE);
    
    // Обновляем только поле data_json, сохраняя все остальные данные анкеты
    // Это гарантирует, что исходные данные формы не будут потеряны
    $stmt = $pdo->prepare("
        UPDATE term_sheet_forms 
        SET data_json = ?, 
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$updatedDataJson, $form['id'], $user['id']]);

    // Возвращаем успешный ответ с HTML для отображения в браузере
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
    // Извлекаем пороговые значения из анкеты для включения конкретных цифр в описание пунктов
    // Эти значения используются для формирования детальных формулировок в разделе корпоративного управления
    $majorTransactionThreshold = !empty($formData['major_transaction_threshold']) ? $formData['major_transaction_threshold'] : '10';
    $litigationThreshold = !empty($formData['litigation_threshold']) ? $formData['litigation_threshold'] : '5';
    $executiveCompensationThreshold = !empty($formData['executive_compensation_threshold']) ? $formData['executive_compensation_threshold'] : '3';
    
    // Формируем список вопросов, требующих единогласного решения
    // Пороговые значения подставляются в соответствующие формулировки для точности документа
    $unanimousDecisions = [];
    if (!empty($formData['unanimous_decisions_list']) && is_array($formData['unanimous_decisions_list'])) {
        // Маппинг типов решений на их текстовые описания с включением пороговых значений
        $decisionMap = [
            'charter' => 'Изменение устава и уставного капитала',
            'budget' => 'Утверждение бюджета / бизнес-плана',
            'dividends' => 'Распределение чистой прибыли и дивидендная политика',
            // Включаем конкретное пороговое значение для крупных сделок
            'major_transactions' => 'Совершение любых сделок на сумму свыше ' . $majorTransactionThreshold . ' млн руб., за исключением сделок, условия которых во всех существенных аспектах утверждены в рамках бюджета Общества',
            'real_estate' => 'Совершение любых сделок с недвижимостью',
            'ip' => 'Совершение любых сделок с интеллектуальной собственностью',
            // Включаем конкретное пороговое значение для судебных процессов
            'litigation' => 'Вопросы, связанные с участием в судебных и арбитражных процессах (в случае если сумма требований превышает ' . $litigationThreshold . ' млн руб.)',
            // Включаем конкретное пороговое значение для вознаграждения топ-менеджеров
            'executive_compensation' => 'Утверждение условий трудовых договоров с работниками, годовое вознаграждение которых до вычета налогов превышает или может превысить ' . $executiveCompensationThreshold . ' млн руб.',
            'subsidiaries' => 'Принятие решений об участии в уставных капиталах иных компаний',
            'debt' => 'Совершение сделок, влекущих возникновение финансовой задолженности',
            'guarantees' => 'Предоставление обеспечений по обязательствам третьих лиц',
            'financing' => 'Предоставление финансирования третьим лицам',
        ];
        // Проходим по выбранным пунктам и добавляем их описания в список
        foreach ($formData['unanimous_decisions_list'] as $key => $value) {
            if (!empty($value) && isset($decisionMap[$key])) {
                $unanimousDecisions[] = $decisionMap[$key];
            }
        }
    }
    // Объединяем все вопросы в одну строку для включения в промпт
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

    // Корпоративное управление - добавляем раздел, если есть хотя бы одно поле
    if (!empty($ceoAppointment) || !empty($cfoAppointment) || !empty($unanimousDecisionsText)) {
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
// Функция callTogetherCompletions() теперь определена в config.php
// Используется универсальная функция callAICompletions() с поддержкой system message

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
    
    // Проверяем, есть ли раздел с подписями в документе
    // Ищем различные варианты написания "ПОДПИСИ СТОРОН" (заглавными, строчными, смешанный регистр)
    $hasSignatures = preg_match('/ПОДПИСИ\s+СТОРОН|подписи\s+сторон|Подписи\s+сторон/ui', $content);
    
    // Если раздел с подписями отсутствует, добавляем его программно
    // Это гарантирует, что документ всегда будет полным и готовым к подписанию
    if (!$hasSignatures) {
        // Получаем имена сторон для раздела подписей
        // Приоритет: массив buyers/sellers, затем одиночные поля buyer_name/seller_name
        // Если ничего не найдено, используем общие названия "Покупатель" и "Продавец"
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
        
        // Формируем раздел с подписями в стандартном формате
        // Включает поля для подписей и ФИО обеих сторон, а также поле для даты
        $signaturesSection = "\n\nПОДПИСИ СТОРОН\n\n" .
            $buyerName . ":\n" .
            "_________________ / _________________\n" .
            "(подпись)          (ФИО)\n\n" .
            $sellerName . ":\n" .
            "_________________ / _________________\n" .
            "(подпись)          (ФИО)\n\n" .
            "Дата: _______________";
        
        // Добавляем раздел с подписями в конец документа
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
    // Сохраняем исходный контент для возможного восстановления
    $originalResponse = $rawResponse;
    
    // Удаляем markdown разметку, которая может присутствовать в ответе ИИ
    // Блоки кода (```...```)
    $cleaned = preg_replace('/```[\s\S]*?```/', '', $rawResponse);
    // Жирный текст (**текст**)
    $cleaned = preg_replace('/\*\*(.*?)\*\*/', '$1', $cleaned);
    // Курсив (*текст*)
    $cleaned = preg_replace('/\*(.*?)\*/', '$1', $cleaned);
    // Заголовки (# Заголовок)
    $cleaned = preg_replace('/#+\s*/', '', $cleaned);
    // Inline код (`код`)
    $cleaned = preg_replace('/`(.*?)`/', '$1', $cleaned);
    
    // Удаляем технические комментарии, которые могут быть в ответе
    // HTML комментарии (<!-- ... -->)
    $cleaned = preg_replace('/<!--[\s\S]*?-->/', '', $cleaned);
    // Многострочные комментарии (/* ... */)
    $cleaned = preg_replace('/\/\*[\s\S]*?\*\//', '', $cleaned);
    // Однострочные комментарии (// ...)
    $cleaned = preg_replace('/\/\/.*$/m', '', $cleaned);
    
    // Сохраняем длину после удаления markdown для проверки
    $afterMarkdownLength = mb_strlen($cleaned);
    
    // Удаляем служебные фразы ИИ, которые не должны попадать в финальный документ
    // Эти фразы часто появляются в начале или конце ответа ИИ
    // ВАЖНО: удаляем только в начале документа, чтобы не удалить весь контент
    $aiPhrases = [
        'Как ИИ-ассистент',
        'Как AI-ассистент',
        'Я создал',
        'Вот Term Sheet',
        'Ниже представлен',
        'Созданный документ',
        'Документ включает',
    ];
    
    // Удаляем служебные фразы только в начале документа (первые 500 символов)
    // Это предотвращает удаление всего контента, если фраза встречается в середине
    $beginning = mb_substr($cleaned, 0, 500);
    $rest = mb_substr($cleaned, 500);
    
    foreach ($aiPhrases as $phrase) {
        // Удаляем фразу только если она в начале документа
        $beginning = preg_replace('/^' . preg_quote($phrase, '/') . '[\s\S]*?(?=\n\n|\n[А-ЯЁ]|ЛИСТ|TERM|ПОКУПАТЕЛЬ|ПРОДАВЕЦ)/iu', '', $beginning);
    }
    
    $cleaned = $beginning . $rest;
    
    // Проверка: если после удаления фраз контент стал слишком коротким (менее 50% от длины после markdown),
    // значит мы удалили слишком много - используем версию только с удаленным markdown
    if (mb_strlen($cleaned) < $afterMarkdownLength * 0.5 && $afterMarkdownLength > 200) {
        error_log("Term Sheet cleaning removed too much content. Using version without AI phrases removal.");
        // Используем версию после удаления markdown, но без удаления служебных фраз
        $cleaned = preg_replace('/```[\s\S]*?```/', '', $originalResponse);
        $cleaned = preg_replace('/\*\*(.*?)\*\*/', '$1', $cleaned);
        $cleaned = preg_replace('/\*(.*?)\*/', '$1', $cleaned);
        $cleaned = preg_replace('/#+\s*/', '', $cleaned);
        $cleaned = preg_replace('/`(.*?)`/', '$1', $cleaned);
        $cleaned = preg_replace('/<!--[\s\S]*?-->/', '', $cleaned);
        $cleaned = preg_replace('/\/\*[\s\S]*?\*\//', '', $cleaned);
        $cleaned = preg_replace('/\/\/.*$/m', '', $cleaned);
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
/**
 * Рендерит HTML для отображения Term Sheet в виде таблицы с двумя колонками.
 * 
 * Форматирует документ как таблицу:
 * - Левая колонка (25% ширины) - название пункта
 * - Правая колонка (75% ширины) - содержание пункта
 * 
 * @param string $content Текст Term Sheet
 * @param array $formData Данные анкеты (для дополнительной информации)
 * @return string HTML код для отображения Term Sheet
 */
function renderTermSheetHtml(string $content, array $formData): string
{
    // Проверяем, что контент не пустой
    $content = trim($content);
    if (empty($content)) {
        error_log("renderTermSheetHtml: Empty content provided");
        throw new Exception("Не удалось сгенерировать содержимое документа. Попробуйте снова.");
    }
    
    // Разбиваем контент на разделы по двойным переносам строк
    $sections = explode("\n\n", $content);
    
    // Начало HTML документа с базовыми стилями
    $html = '<div class="term-sheet-document" style="background: white; border-radius: 20px; padding: 48px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 1200px; width: 100%; margin: 0 auto; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, sans-serif; line-height: 1.8; color: #1a1a1a;">';
    
    // Заголовок документа (Term Sheet)
    $html .= '<div style="text-align: center; margin-bottom: 48px; padding-bottom: 32px; border-bottom: 3px solid #10B981;">';
    $html .= '<h1 style="font-size: 36px; font-weight: 800; margin-bottom: 12px; color: #1a1a1a;">Term Sheet</h1>';
    $html .= '<p style="font-size: 18px; color: #666; margin: 0;">Лист условий сделки</p>';
    $html .= '</div>';
    
    // Начинаем таблицу с двумя колонками
    // Формат: левая колонка (25%) - название пункта, правая колонка (75%) - содержание
    $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 16px; margin-bottom: 32px;">';
    
    // Переменные для накопления данных текущего раздела
    // $currentSectionTitle - название раздела (будет в левой колонке)
    // $currentSectionContent - массив HTML-элементов с содержимым раздела (будет в правой колонке)
    $currentSectionTitle = '';
    $currentSectionContent = [];
    
    // Обрабатываем каждый раздел контента
    foreach ($sections as $section) {
        $section = trim($section);
        if (empty($section)) {
            continue; // Пропускаем пустые разделы
        }
        
        // Проверяем, является ли раздел заголовком первого уровня (H2)
        // Паттерн: строка из заглавных русских букв, цифр, пробелов и точек (до 100 символов)
        // Такие заголовки становятся названиями пунктов в левой колонке таблицы
        if (preg_match('/^[А-ЯЁ][А-ЯЁ\s\d\.]+$/u', $section) && mb_strlen($section) < 100) {
            // Если у нас уже есть накопленное содержимое предыдущего раздела, выводим его в таблицу
            // Это происходит при встрече нового заголовка - значит предыдущий раздел завершен
            if (!empty($currentSectionTitle) && !empty($currentSectionContent)) {
                $html .= '<tr>';
                // Левая колонка: название пункта (25% ширины, жирный шрифт)
                $html .= '<td style="width: 25%; vertical-align: top; padding: 16px 20px 16px 0; font-weight: 700; color: #1a1a1a; border-bottom: 1px solid #e9ecef;">';
                $html .= htmlspecialchars($currentSectionTitle, ENT_QUOTES, 'UTF-8');
                $html .= '</td>';
                // Правая колонка: содержание пункта (75% ширины)
                $html .= '<td style="width: 75%; vertical-align: top; padding: 16px 0; border-bottom: 1px solid #e9ecef;">';
                $html .= implode('', $currentSectionContent);
                $html .= '</td>';
                $html .= '</tr>';
            }
            
            // Начинаем новый раздел: сохраняем заголовок и очищаем содержимое
            $currentSectionTitle = $section;
            $currentSectionContent = [];
        } else {
            // Обычный текст - это содержимое текущего раздела
            // Разбиваем на параграфы по одинарным переносам строк для обработки
            $paragraphs = explode("\n", $section);
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if (!empty($para)) {
                    // Проверяем, является ли это подзаголовком второго уровня (H3)
                    // Паттерн: строка, начинающаяся с заглавной буквы и заканчивающаяся на ":" (до 80 символов)
                    if (preg_match('/^[А-ЯЁ][а-яё\s\d\.]+:$/u', $para) && mb_strlen($para) < 80) {
                        // Подзаголовок - форматируем как H3
                        $currentSectionContent[] = '<h3 style="font-size: 18px; font-weight: 600; margin-top: 16px; margin-bottom: 8px; color: #333;">' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '</h3>';
                    } else {
                        // Обычный параграф - добавляем в содержимое раздела
                        $currentSectionContent[] = '<p style="margin-bottom: 12px; line-height: 1.8;">' . nl2br(htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
                    }
                }
            }
        }
    }
    
    // Выводим последний раздел, если он есть
    // Это необходимо, так как последний раздел не будет обработан циклом (нет следующего заголовка)
    if (!empty($currentSectionTitle) && !empty($currentSectionContent)) {
        $html .= '<tr>';
        // Левая колонка: название пункта
        $html .= '<td style="width: 25%; vertical-align: top; padding: 16px 20px 16px 0; font-weight: 700; color: #1a1a1a; border-bottom: 1px solid #e9ecef;">';
        $html .= htmlspecialchars($currentSectionTitle, ENT_QUOTES, 'UTF-8');
        $html .= '</td>';
        // Правая колонка: содержание пункта
        $html .= '<td style="width: 75%; vertical-align: top; padding: 16px 0; border-bottom: 1px solid #e9ecef;">';
        $html .= implode('', $currentSectionContent);
        $html .= '</td>';
        $html .= '</tr>';
    }
    
    $html .= '</table>'; // Закрываем таблицу
    
    // Футер с информацией о документе
    $html .= '<div style="margin-top: 48px; padding-top: 32px; border-top: 2px solid #e9ecef; text-align: center; color: #666; font-size: 14px;">';
    $html .= '<p>Документ создан автоматически на основе данных анкеты</p>';
    $html .= '<p style="margin-top: 8px;">Дата создания: ' . date('d.m.Y H:i') . '</p>';
    $html .= '</div>';
    
    $html .= '</div>'; // Закрываем основной контейнер
    
    return $html;
}
