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
 * Собирает данные анкеты Term Sheet для формирования документа
 * Использует ТОЛЬКО данные из term_sheet_forms, не берет данные из seller_forms
 */
function buildTermSheetPayload(array $form): array
{
    $data = [];
    
    // Приоритетно используем data_json, так как там хранятся все данные формы
    if (!empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        if (is_array($decoded)) {
            // Исключаем generated_document из исходных данных
            foreach ($decoded as $key => $value) {
                if ($key !== 'generated_document') {
                    $data[$key] = $value;
                }
            }
        }
    }
    
    // Дополняем данными из отдельных полей таблицы (только если их нет в data_json)
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
 * Проверяет полноту данных анкеты Term Sheet
 * Возвращает массив названий недостающих обязательных полей
 */
function checkTermSheetCompleteness(array $formData): array
{
    $missingFields = [];
    
    // Проверяем наличие покупателей
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
 * Формирует промпт для генерации Term Sheet на основе данных анкеты
 */
function buildTermSheetPrompt(array $formData): string
{
    // Собираем информацию о покупателях
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
1. Документ должен быть на русском языке
2. Используй профессиональную юридическую терминологию
3. Структура документа должна включать следующие разделы:
   - Детали предполагаемой сделки (Покупатель, Продавец, Актив)
   - Тип сделки и условия (продажа доли, привлечение инвестиций)
   - Срок действия соглашения
   - Эксклюзивность
   - Заверения об обстоятельствах
   - Условия закрытия сделки
   - Применимое право
   - Корпоративное управление после сделки (если применимо)
   - Преимущественное право
4. Не используй технические комментарии, метки или служебную информацию
5. Не используй markdown разметку (\`\`\`, **, # и т.д.)
6. Текст должен быть готов для прямого использования в документе
7. Используй точные данные из анкеты, не выдумывай информацию
8. Если каких-то данных не хватает, используй стандартные формулировки для M&A сделок

Создай Term Sheet в виде структурированного текста, готового для использования в документе. Каждый раздел должен начинаться с заголовка и содержать детальную информацию на основе предоставленных данных.";

    return $prompt;
}

/**
 * Вызывает Together.ai API
 */
function callTogetherCompletions(string $prompt, string $apiKey): string
{
    $url = 'https://api.together.xyz/v1/chat/completions';
    
    $data = [
        'model' => TOGETHER_MODEL,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'Ты профессиональный M&A консультант, специализирующийся на создании Term Sheet для инвестиционных сделок. Твои документы всегда на русском языке, профессиональны и готовы к использованию.'
            ],
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 4000,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("Together.ai API error: HTTP $httpCode, Response: $response");
        throw new Exception("Ошибка при обращении к API ИИ");
    }
    
    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        error_log("Together.ai API unexpected response: " . $response);
        throw new Exception("Неожиданный формат ответа от API ИИ");
    }
    
    return $result['choices'][0]['message']['content'];
}

/**
 * Очищает ответ ИИ от технической информации и форматирует
 */
function cleanAndFormatTermSheet(string $rawResponse): string
{
    // Удаляем markdown разметку
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
 * Рендерит HTML для Term Sheet
 */
function renderTermSheetHtml(string $content, array $formData): string
{
    // Разбиваем контент на разделы
    $sections = explode("\n\n", $content);
    
    $html = '<div class="term-sheet-document" style="background: white; border-radius: 20px; padding: 48px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); max-width: 900px; margin: 0 auto; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, sans-serif; line-height: 1.8; color: #1a1a1a;">';
    
    // Заголовок
    $html .= '<div style="text-align: center; margin-bottom: 48px; padding-bottom: 32px; border-bottom: 3px solid #10B981;">';
    $html .= '<h1 style="font-size: 36px; font-weight: 800; margin-bottom: 12px; color: #1a1a1a;">Term Sheet</h1>';
    $html .= '<p style="font-size: 18px; color: #666; margin: 0;">Лист условий сделки</p>';
    $html .= '</div>';
    
    // Контент
    $html .= '<div class="term-sheet-content" style="font-size: 16px;">';
    
    foreach ($sections as $section) {
        $section = trim($section);
        if (empty($section)) {
            continue;
        }
        
        // Проверяем, является ли раздел заголовком
        if (preg_match('/^[А-ЯЁ][А-ЯЁ\s\d\.]+$/u', $section) && mb_strlen($section) < 100) {
            $html .= '<h2 style="font-size: 24px; font-weight: 700; margin-top: 40px; margin-bottom: 20px; color: #1a1a1a; padding-bottom: 12px; border-bottom: 2px solid #e9ecef;">' . htmlspecialchars($section, ENT_QUOTES, 'UTF-8') . '</h2>';
        } else {
            // Обычный текст
            $paragraphs = explode("\n", $section);
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if (!empty($para)) {
                    // Проверяем, является ли это подзаголовком
                    if (preg_match('/^[А-ЯЁ][а-яё\s\d\.]+:$/u', $para) && mb_strlen($para) < 80) {
                        $html .= '<h3 style="font-size: 18px; font-weight: 600; margin-top: 24px; margin-bottom: 12px; color: #333;">' . htmlspecialchars($para, ENT_QUOTES, 'UTF-8') . '</h3>';
                    } else {
                        $html .= '<p style="margin-bottom: 16px; line-height: 1.8;">' . nl2br(htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
                    }
                }
            }
        }
    }
    
    $html .= '</div>';
    
    // Футер
    $html .= '<div style="margin-top: 48px; padding-top: 32px; border-top: 2px solid #e9ecef; text-align: center; color: #666; font-size: 14px;">';
    $html .= '<p>Документ создан автоматически на основе данных анкеты</p>';
    $html .= '<p style="margin-top: 8px;">Дата создания: ' . date('d.m.Y H:i') . '</p>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    return $html;
}

