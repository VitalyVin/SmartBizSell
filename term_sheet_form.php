<?php
/**
 * Форма Term Sheet для создания инвестиционного меморандума
 * 
 * Функциональность:
 * - Создание и редактирование Term Sheet
 * - Сохранение черновиков с полными данными в JSON
 * - Валидация обязательных полей при отправке
 * - Восстановление данных из черновиков
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';
if (!isLoggedIn()) {
    redirectToLogin();
}

$pdo = getDBConnection();
ensureTermSheetFormSchema($pdo);
$formId = null;
$existingForm = null;
$draftMessage = false;

/**
 * Поля, обязательные для отправки Term Sheet (не применяются к сохранению черновика)
 */
$requiredFields = [
    'asset_name',
    'deal_type',
];

/**
 * Проверяет, является ли поле обязательным для отправки формы
 */
function isFieldRequired(string $field): bool
{
    global $requiredFields;
    return in_array($field, $requiredFields, true);
}

/**
 * Возвращает HTML-атрибут required для обязательных полей
 */
function requiredAttr(string $field): string
{
    return isFieldRequired($field) ? ' required' : '';
}

/**
 * Возвращает CSS-класс для обязательных полей
 */
function requiredClass(string $field): string
{
    return isFieldRequired($field) ? ' required-field' : '';
}

/**
 * Рекурсивная нормализация значений для корректного JSON
 */
function normalizeDraftValue($value)
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $innerValue) {
            $normalized[$key] = normalizeDraftValue($innerValue);
        }
        return $normalized;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        return mb_convert_encoding($trimmed, 'UTF-8', 'UTF-8');
    }

    return $value;
}

/**
 * Формирует безопасный payload для сохранения черновика
 */
function buildDraftPayload(array $source): array
{
    $scalarFields = [
        'buyer_name', 'buyer_inn', 'buyer_individual_name', 'buyer_individual_inn',
        'seller_name', 'seller_inn', 'seller_individual_name', 'seller_individual_inn',
        'asset_name', 'asset_inn',
        'deal_type', 'deal_share_percent', 'investment_amount', 'investment_purpose',
        'agreement_duration', 'exclusivity', 'applicable_law',
        'corporate_governance_ceo', 'corporate_governance_cfo',
        'unanimous_decisions', 'preemptive_right',
        'major_transaction_threshold', 'litigation_threshold', 'executive_compensation_threshold',
    ];

    $payload = [];

    foreach ($scalarFields as $field) {
        if (array_key_exists($field, $source)) {
            $payload[$field] = normalizeDraftValue($source[$field]);
        }
    }

    // Массивы для множественных значений
    $arrayFields = [
        'buyers', 'sellers', 'assets', 'deal_types', 'investment_purposes',
        'warranties', 'closing_conditions', 'unanimous_decisions_list'
    ];

    foreach ($arrayFields as $field) {
        if (isset($source[$field])) {
            $payload[$field] = normalizeDraftValue($source[$field]);
        }
    }

    if (isset($source['save_draft'])) {
        $payload['save_draft'] = $source['save_draft'];
    }

    if (!empty($source['form_id'])) {
        $payload['form_id'] = $source['form_id'];
    }

    return $payload;
}

/**
 * Проверяет существование колонки в таблице term_sheet_forms
 */
function termSheetFormsColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema
          AND TABLE_NAME = 'term_sheet_forms'
          AND COLUMN_NAME = :column
        LIMIT 1
    ");
    $stmt->execute([
        'schema' => DB_NAME,
        'column' => $column,
    ]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Восстанавливает данные формы из базы данных в $_POST
 * Исключает generated_document, так как это служебная информация
 */
function hydrateFormFromDb(array $form): void
{
    // Если есть data_json (для черновиков), используем его
    if (!empty($form['data_json'])) {
        $decodedData = json_decode($form['data_json'], true);
        if (is_array($decodedData)) {
            // Восстанавливаем все данные, кроме generated_document
            foreach ($decodedData as $key => $value) {
                if ($key !== 'generated_document') {
                    $_POST[$key] = $value;
                }
            }
        }
    }

    // Дополняем данными из отдельных полей таблицы (если их нет в data_json)
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

    foreach ($mapping as $postKey => $column) {
        // Используем значение из БД только если его нет в $_POST
        if (!isset($_POST[$postKey]) || empty($_POST[$postKey])) {
            if (!empty($form[$column])) {
                $_POST[$postKey] = $form[$column];
            }
        }
    }
}

/**
 * Обеспечивает актуальность схемы таблицы term_sheet_forms
 */
function ensureTermSheetFormSchema(PDO $pdo): void
{
    static $schemaChecked = false;
    if ($schemaChecked) {
        return;
    }
    $schemaChecked = true;

    // Создаем таблицу, если она не существует
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS term_sheet_forms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                
                -- Основные данные
                buyer_name VARCHAR(500) DEFAULT NULL,
                buyer_inn VARCHAR(20) DEFAULT NULL,
                seller_name VARCHAR(500) DEFAULT NULL,
                seller_inn VARCHAR(20) DEFAULT NULL,
                asset_name VARCHAR(500) DEFAULT NULL,
                asset_inn VARCHAR(20) DEFAULT NULL,
                
                -- Детали сделки
                deal_type VARCHAR(255) DEFAULT NULL,
                deal_share_percent DECIMAL(5,2) DEFAULT NULL,
                investment_amount DECIMAL(15,2) DEFAULT NULL,
                agreement_duration INT DEFAULT 3,
                exclusivity ENUM('yes', 'no') DEFAULT 'no',
                applicable_law VARCHAR(255) DEFAULT 'российское право',
                
                -- Корпоративное управление
                corporate_governance_ceo VARCHAR(255) DEFAULT NULL,
                corporate_governance_cfo VARCHAR(255) DEFAULT NULL,
                
                -- Статус и метаданные
                status ENUM('draft', 'submitted', 'review', 'approved', 'rejected') DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                submitted_at TIMESTAMP NULL DEFAULT NULL,
                data_json JSON DEFAULT NULL,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        error_log("Term Sheet forms table created or already exists");
    } catch (PDOException $e) {
        error_log("Failed to create term_sheet_forms table: " . $e->getMessage());
    }
}

$errors = [];

// ==================== ОБРАБОТКА ФОРМЫ ====================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureTermSheetFormSchema($pdo);

    $formId = isset($_POST['form_id']) ? (int)$_POST['form_id'] : null;
    if ($formId) {
        $stmt = $pdo->prepare("SELECT * FROM term_sheet_forms WHERE id = ? AND user_id = ?");
        $stmt->execute([$formId, $_SESSION['user_id']]);
        $existingForm = $stmt->fetch();
    }

    // Берем первые значения из массивов для валидации
    $buyerName = !empty($_POST['buyers'][0]['name']) ? sanitizeInput($_POST['buyers'][0]['name']) : '';
    $buyerIndividualName = !empty($_POST['buyers'][0]['individual_name']) ? sanitizeInput($_POST['buyers'][0]['individual_name']) : '';
    $sellerName = !empty($_POST['sellers'][0]['name']) ? sanitizeInput($_POST['sellers'][0]['name']) : '';
    $sellerIndividualName = !empty($_POST['sellers'][0]['individual_name']) ? sanitizeInput($_POST['sellers'][0]['individual_name']) : '';
    $assetName = !empty($_POST['assets'][0]['name']) ? sanitizeInput($_POST['assets'][0]['name']) : '';

    $saveDraftFlag = $_POST['save_draft_flag'] ?? '';
    // Определяем, является ли это сохранением черновика или финальной отправкой
    // Приоритет: если нажата кнопка "submit" (Отправить Term Sheet), это всегда финальная отправка
    // Если нажата кнопка "save_draft" (Сохранить черновик) И НЕ нажата "submit", это сохранение черновика
    $isDraftSave = isset($_POST['save_draft']) && !isset($_POST['submit']) && $saveDraftFlag !== '0';
    
    // Логирование для отладки
    error_log("Term Sheet form submission: isDraftSave=" . ($isDraftSave ? 'true' : 'false') . 
              ", save_draft=" . (isset($_POST['save_draft']) ? 'set' : 'not set') . 
              ", submit=" . (isset($_POST['submit']) ? 'set' : 'not set') . 
              ", save_draft_flag=" . $saveDraftFlag . 
              ", form_id=" . ($formId ?? 'null'));

    // Валидация обязательных полей (только для финальной отправки)
    if (!$isDraftSave) {
        // Для покупателя: должно быть заполнено либо название ЮЛ, либо ФИО ФЛ
        if (empty($buyerName) && empty($buyerIndividualName)) {
            $errors['buyer_name'] = 'Укажите название покупателя (ЮЛ) или ФИО покупателя (ФЛ)';
        }
        // Для продавца: должно быть заполнено либо название ЮЛ, либо ФИО ФЛ
        if (empty($sellerName) && empty($sellerIndividualName)) {
            $errors['seller_name'] = 'Укажите название продавца (ЮЛ) или ФИО продавца (ФЛ)';
        }
        // Для актива: название обязательно (актив обычно - это юридическое лицо)
        if (empty($assetName)) {
            $errors['asset_name'] = 'Укажите название актива';
        }
        if (empty($_POST['deal_type'])) {
            $errors['deal_type'] = 'Выберите тип сделки';
        }
    }

    // Если ошибок валидации нет, сохраняем данные
    if (empty($errors)) {
        try {
            $draftPayload = buildDraftPayload($_POST);
            $dataJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);

            if ($dataJson === false) {
                $dataJson = json_encode(normalizeDraftValue($draftPayload), JSON_UNESCAPED_UNICODE);
                if ($dataJson === false) {
                    $dataJson = json_encode(new stdClass());
                }
            }

            if ($isDraftSave) {
                // Сохранение черновика
                // Берем первые значения из массивов для основных полей
                // Для покупателя и продавца используем либо название ЮЛ, либо ФИО ФЛ
                $firstBuyer = !empty($_POST['buyers'][0]['name']) ? $_POST['buyers'][0]['name'] : (!empty($_POST['buyers'][0]['individual_name']) ? $_POST['buyers'][0]['individual_name'] : '');
                $firstSeller = !empty($_POST['sellers'][0]['name']) ? $_POST['sellers'][0]['name'] : (!empty($_POST['sellers'][0]['individual_name']) ? $_POST['sellers'][0]['individual_name'] : '');
                $firstAsset = !empty($_POST['assets'][0]['name']) ? $_POST['assets'][0]['name'] : '';
                
                // Сохраняем существующий generated_document, если он есть
                if ($formId && $existingForm && !empty($existingForm['data_json'])) {
                    $existingData = json_decode($existingForm['data_json'], true);
                    if (is_array($existingData) && !empty($existingData['generated_document'])) {
                        $draftPayload['generated_document'] = $existingData['generated_document'];
                        $dataJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
                    }
                }
                
                if ($formId && $existingForm) {
                    // При сохранении черновика сохраняем текущий статус (не меняем на draft, если форма уже была отправлена)
                    $preserveStatus = $existingForm['status'] ?? 'draft';
                    // Если статус был submitted, review или approved, сохраняем его, иначе ставим draft
                    if (!in_array($preserveStatus, ['submitted', 'review', 'approved'], true)) {
                        $preserveStatus = 'draft';
                    }
                    $stmt = $pdo->prepare("UPDATE term_sheet_forms SET buyer_name = ?, seller_name = ?, asset_name = ?, data_json = ?, status = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
                    $stmt->execute([$firstBuyer, $firstSeller, $firstAsset, $dataJson, $preserveStatus, $formId, $_SESSION['user_id']]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO term_sheet_forms (user_id, buyer_name, seller_name, asset_name, data_json, status) VALUES (?, ?, ?, ?, ?, 'draft')");
                    $stmt->execute([$_SESSION['user_id'], $firstBuyer, $firstSeller, $firstAsset, $dataJson]);
                    $formId = $pdo->lastInsertId();
                }

                header('Location: term_sheet_form.php?saved=1&form_id=' . $formId);
                exit;
            } else {
                // Финальная отправка
                // Берем первые значения из массивов для основных полей
                // Для покупателя и продавца используем либо название ЮЛ, либо ФИО ФЛ
                $firstBuyer = !empty($_POST['buyers'][0]['name']) ? sanitizeInput($_POST['buyers'][0]['name']) : (!empty($_POST['buyers'][0]['individual_name']) ? sanitizeInput($_POST['buyers'][0]['individual_name']) : '');
                $buyerInn = !empty($_POST['buyers'][0]['inn']) ? sanitizeInput($_POST['buyers'][0]['inn']) : (!empty($_POST['buyers'][0]['individual_inn']) ? sanitizeInput($_POST['buyers'][0]['individual_inn']) : '');
                $firstSeller = !empty($_POST['sellers'][0]['name']) ? sanitizeInput($_POST['sellers'][0]['name']) : (!empty($_POST['sellers'][0]['individual_name']) ? sanitizeInput($_POST['sellers'][0]['individual_name']) : '');
                $sellerInn = !empty($_POST['sellers'][0]['inn']) ? sanitizeInput($_POST['sellers'][0]['inn']) : (!empty($_POST['sellers'][0]['individual_inn']) ? sanitizeInput($_POST['sellers'][0]['individual_inn']) : '');
                $firstAsset = !empty($_POST['assets'][0]['name']) ? sanitizeInput($_POST['assets'][0]['name']) : '';
                $assetInn = !empty($_POST['assets'][0]['inn']) ? sanitizeInput($_POST['assets'][0]['inn']) : '';
                
                $dealType = sanitizeInput($_POST['deal_type'] ?? '');
                $dealSharePercent = !empty($_POST['deal_share_percent']) ? (float)$_POST['deal_share_percent'] : null;
                $investmentAmount = !empty($_POST['investment_amount']) ? (float)$_POST['investment_amount'] : null;
                $agreementDuration = !empty($_POST['agreement_duration']) ? (int)$_POST['agreement_duration'] : 3;
                $exclusivity = sanitizeInput($_POST['exclusivity'] ?? 'no');
                $applicableLaw = sanitizeInput($_POST['applicable_law'] ?? 'российское право');
                $corporateGovernanceCeo = sanitizeInput($_POST['corporate_governance_ceo'] ?? '');
                $corporateGovernanceCfo = sanitizeInput($_POST['corporate_governance_cfo'] ?? '');

                // Сохраняем существующий generated_document, если он есть
                if ($formId && $existingForm && !empty($existingForm['data_json'])) {
                    $existingData = json_decode($existingForm['data_json'], true);
                    if (is_array($existingData) && !empty($existingData['generated_document'])) {
                        $draftPayload['generated_document'] = $existingData['generated_document'];
                        $dataJson = json_encode($draftPayload, JSON_UNESCAPED_UNICODE);
                    }
                }

                if ($formId && $existingForm) {
                    // При финальной отправке обновляем submitted_at только если его еще нет (сохраняем оригинальную дату отправки)
                    error_log("Term Sheet: Updating form ID {$formId} to status 'submitted'");
                    $stmt = $pdo->prepare("UPDATE term_sheet_forms SET
                        buyer_name = ?, buyer_inn = ?, seller_name = ?, seller_inn = ?,
                        asset_name = ?, asset_inn = ?, deal_type = ?, deal_share_percent = ?,
                        investment_amount = ?, agreement_duration = ?, exclusivity = ?,
                        applicable_law = ?, corporate_governance_ceo = ?, corporate_governance_cfo = ?,
                        data_json = ?, status = 'submitted', submitted_at = COALESCE(submitted_at, NOW()), updated_at = NOW()
                        WHERE id = ? AND user_id = ?");
                    $result = $stmt->execute([
                        $firstBuyer, $buyerInn, $firstSeller, $sellerInn,
                        $firstAsset, $assetInn, $dealType, $dealSharePercent,
                        $investmentAmount, $agreementDuration, $exclusivity,
                        $applicableLaw, $corporateGovernanceCeo, $corporateGovernanceCfo,
                        $dataJson, $formId, $_SESSION['user_id']
                    ]);
                    error_log("Term Sheet: Update result for form ID {$formId}: " . ($result ? 'success' : 'failed') . ", rows affected: " . $stmt->rowCount());
                } else {
                    $stmt = $pdo->prepare("INSERT INTO term_sheet_forms (
                        user_id, buyer_name, buyer_inn, seller_name, seller_inn,
                        asset_name, asset_inn, deal_type, deal_share_percent,
                        investment_amount, agreement_duration, exclusivity,
                        applicable_law, corporate_governance_ceo, corporate_governance_cfo,
                        data_json, status, submitted_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())");
                    $stmt->execute([
                        $_SESSION['user_id'], $firstBuyer, $buyerInn, $firstSeller, $sellerInn,
                        $firstAsset, $assetInn, $dealType, $dealSharePercent,
                        $investmentAmount, $agreementDuration, $exclusivity,
                        $applicableLaw, $corporateGovernanceCeo, $corporateGovernanceCfo,
                        $dataJson
                    ]);
                    $formId = $pdo->lastInsertId();
                }

                header('Location: dashboard.php?success=1&type=term_sheet');
                exit;
            }
        } catch (PDOException $e) {
            error_log("Error saving term sheet form: " . $e->getMessage());
            if ($isDraftSave) {
                $errors['general'] = 'Ошибка сохранения черновика: ' . $e->getMessage();
            } else {
                $errors['general'] = 'Ошибка сохранения Term Sheet. Попробуйте позже.';
            }
        }
    }
}

// Загружаем существующий черновик или форму для редактирования
$formId = null;
$existingForm = null;
$draftMessage = false;

if (isset($_GET['form_id'])) {
    $formId = (int)$_GET['form_id'];
    $stmt = $pdo->prepare("SELECT * FROM term_sheet_forms WHERE id = ? AND user_id = ?");
    $stmt->execute([$formId, $_SESSION['user_id']]);
    $existingForm = $stmt->fetch();
} else {
    // Загружаем последний черновик если нет form_id
    $stmt = $pdo->prepare("SELECT * FROM term_sheet_forms WHERE user_id = ? AND status = 'draft' ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $existingForm = $stmt->fetch();
    if ($existingForm) {
        $formId = $existingForm['id'];
    }
}

if (isset($_GET['saved'])) {
    $draftMessage = true;
}

// Если есть существующая форма, загружаем данные
if ($existingForm) {
    hydrateFormFromDb($existingForm);
}

// Инициализация значений по умолчанию
if (!isset($_POST['agreement_duration']) || empty($_POST['agreement_duration'])) {
    $_POST['agreement_duration'] = 3;
}
if (!isset($_POST['exclusivity']) || empty($_POST['exclusivity'])) {
    $_POST['exclusivity'] = 'no';
}
if (!isset($_POST['applicable_law']) || empty($_POST['applicable_law'])) {
    $_POST['applicable_law'] = 'российское право';
}

// Инициализация массивов
if (!isset($_POST['buyers']) || !is_array($_POST['buyers'])) {
    $_POST['buyers'] = [['name' => '', 'inn' => '', 'individual_name' => '', 'individual_inn' => '']];
}
if (!isset($_POST['sellers']) || !is_array($_POST['sellers'])) {
    $_POST['sellers'] = [['name' => '', 'inn' => '', 'individual_name' => '', 'individual_inn' => '']];
}
if (!isset($_POST['assets']) || !is_array($_POST['assets'])) {
    $_POST['assets'] = [['name' => '', 'inn' => '']];
}
if (!isset($_POST['deal_types']) || !is_array($_POST['deal_types'])) {
    $_POST['deal_types'] = [];
}
if (!isset($_POST['investment_purposes']) || !is_array($_POST['investment_purposes'])) {
    $_POST['investment_purposes'] = [];
}
if (!isset($_POST['warranties']) || !is_array($_POST['warranties'])) {
    $_POST['warranties'] = ['legal_capacity' => '1', 'title' => '1', 'tax' => '1', 'litigation' => '1', 'compliance' => '1'];
}
if (!isset($_POST['closing_conditions']) || !is_array($_POST['closing_conditions'])) {
    $_POST['closing_conditions'] = [
        'signing' => 'Подписание Сторонами обязывающей документации',
        'approvals' => 'Получение всех необходимых одобрений государственных органов и согласий третьих лиц',
        'diligence' => 'Успешное завершение Покупателем комплексной проверки (due diligence)',
        'conditions' => 'Выполнения иных условий, согласованных Сторонами по результатам комплексной проверки (due diligence)',
    ];
}
if (!isset($_POST['unanimous_decisions_list']) || !is_array($_POST['unanimous_decisions_list'])) {
    $_POST['unanimous_decisions_list'] = [
        'charter' => '1',
        'budget' => '1',
        'dividends' => '1',
        'major_transactions' => '1',
        'real_estate' => '1',
        'ip' => '1',
        'litigation' => '1',
        'executive_compensation' => '1',
        'subsidiaries' => '1',
        'debt' => '1',
        'guarantees' => '1',
        'financing' => '1',
    ];
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Term Sheet - SmartBizSell</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="index.php">Главная</a></li>
                    <li><a href="dashboard.php">Личный кабинет</a></li>
                    <li><a href="logout.php">Выйти</a></li>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <div class="container" style="max-width: 1200px; margin: 40px auto; padding: 0 20px;">
        <div style="background: white; border-radius: 20px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
            <div style="margin-bottom: 30px;">
                <h1 style="font-size: 32px; font-weight: 700; margin-bottom: 10px; color: #1a1a1a;">Term Sheet</h1>
                <p style="color: #666; font-size: 16px;">Создайте инвестиционный меморандум с ключевыми условиями сделки</p>
            </div>

            <?php if ($draftMessage): ?>
                <div id="draft-saved-message" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center;">
                    <span class="success-icon" style="font-size: 24px; margin-right: 10px;">✓</span>
                    <span>Черновик успешно сохранен!</span>
                </div>
            <?php endif; ?>
            
            <?php if ($existingForm && in_array($existingForm['status'] ?? '', ['submitted', 'review', 'approved'], true)): ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center;">
                    <span style="font-size: 24px; margin-right: 10px;">⚠️</span>
                    <div>
                        <strong>Редактирование отправленной формы</strong>
                        <p style="margin: 5px 0 0 0; font-size: 14px;">Вы редактируете форму со статусом "<?php echo htmlspecialchars($existingForm['status'] ?? 'submitted'); ?>". При сохранении черновика статус сохранится, при отправке форма будет обновлена.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors['general'])): ?>
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($errors['general']); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="term-sheet-form">
                <?php if ($formId): ?>
                    <input type="hidden" name="form_id" value="<?php echo $formId; ?>">
                <?php endif; ?>
                <input type="hidden" name="save_draft_flag" id="save_draft_flag" value="0">

                <!-- Раздел 1: Детали предполагаемой сделки -->
                <div class="form-section" style="margin-bottom: 40px;">
                    <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: #1a1a1a; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                        1. Детали предполагаемой сделки
                    </h2>

                    <!-- Покупатель -->
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Покупатель <span style="color: red;">*</span></label>
                        <p style="font-size: 13px; color: #666; margin-bottom: 10px;">Заполните либо "Название ЮЛ" (если покупатель - юридическое лицо), либо "ФИО ФЛ" (если покупатель - физическое лицо)</p>
                        <div id="buyers-container">
                            <?php foreach ($_POST['buyers'] as $index => $buyer): ?>
                                <div class="buyer-item" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 10px;">
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">Название ЮЛ</label>
                                            <input type="text" name="buyers[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($buyer['name'] ?? ''); ?>" 
                                                   class="form-control">
                                        </div>
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН</label>
                                            <input type="text" name="buyers[<?php echo $index; ?>][inn]" value="<?php echo htmlspecialchars($buyer['inn'] ?? ''); ?>" 
                                                   class="form-control" pattern="\d{10}|\d{12}">
                                        </div>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ФИО ФЛ</label>
                                            <input type="text" name="buyers[<?php echo $index; ?>][individual_name]" value="<?php echo htmlspecialchars($buyer['individual_name'] ?? ''); ?>" 
                                                   class="form-control">
                                        </div>
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН ФЛ</label>
                                            <input type="text" name="buyers[<?php echo $index; ?>][individual_inn]" value="<?php echo htmlspecialchars($buyer['individual_inn'] ?? ''); ?>" 
                                                   class="form-control" pattern="\d{12}">
                                        </div>
                                    </div>
                                    <?php if ($index > 0): ?>
                                        <button type="button" class="btn-remove" onclick="removeBuyer(<?php echo $index; ?>)" style="margin-top: 10px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['buyer_name'])): ?>
                            <span class="error-message" style="display: block; color: var(--accent-color); font-size: 14px; margin-top: 8px;"><?php echo $errors['buyer_name']; ?></span>
                        <?php endif; ?>
                        <button type="button" onclick="addBuyer()" style="margin-top: 10px; padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">+ Добавить покупателя</button>
                    </div>

                    <!-- Продавец -->
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Продавец <span style="color: red;">*</span></label>
                        <p style="font-size: 13px; color: #666; margin-bottom: 10px;">Заполните либо "Название ЮЛ" (если продавец - юридическое лицо), либо "ФИО ФЛ" (если продавец - физическое лицо)</p>
                        <div id="sellers-container">
                            <?php foreach ($_POST['sellers'] as $index => $seller): ?>
                                <div class="seller-item" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 10px;">
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">Название ЮЛ</label>
                                            <input type="text" name="sellers[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($seller['name'] ?? ''); ?>" 
                                                   class="form-control">
                                        </div>
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН</label>
                                            <input type="text" name="sellers[<?php echo $index; ?>][inn]" value="<?php echo htmlspecialchars($seller['inn'] ?? ''); ?>" 
                                                   class="form-control" pattern="\d{10}|\d{12}">
                                        </div>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ФИО ФЛ <span style="color: #666; font-size: 12px;">(если продавец - физ. лицо)</span></label>
                                            <input type="text" name="sellers[<?php echo $index; ?>][individual_name]" value="<?php echo htmlspecialchars($seller['individual_name'] ?? ''); ?>" 
                                                   class="form-control">
                                        </div>
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН ФЛ</label>
                                            <input type="text" name="sellers[<?php echo $index; ?>][individual_inn]" value="<?php echo htmlspecialchars($seller['individual_inn'] ?? ''); ?>" 
                                                   class="form-control" pattern="\d{12}">
                                        </div>
                                    </div>
                                    <?php if ($index > 0): ?>
                                        <button type="button" class="btn-remove" onclick="removeSeller(<?php echo $index; ?>)" style="margin-top: 10px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (isset($errors['seller_name'])): ?>
                            <span class="error-message" style="display: block; color: var(--accent-color); font-size: 14px; margin-top: 8px;"><?php echo $errors['seller_name']; ?></span>
                        <?php endif; ?>
                        <button type="button" onclick="addSeller()" style="margin-top: 10px; padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">+ Добавить продавца</button>
                    </div>

                    <!-- Актив -->
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Актив <span style="color: red;">*</span></label>
                        <div id="assets-container">
                            <?php foreach ($_POST['assets'] as $index => $asset): ?>
                                <div class="asset-item" style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">Название ЮЛ</label>
                                            <input type="text" name="assets[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($asset['name'] ?? ''); ?>" 
                                                   class="form-control<?php echo requiredClass('asset_name'); ?>"<?php echo requiredAttr('asset_name'); ?>>
                                        </div>
                                        <div>
                                            <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН</label>
                                            <input type="text" name="assets[<?php echo $index; ?>][inn]" value="<?php echo htmlspecialchars($asset['inn'] ?? ''); ?>" 
                                                   class="form-control" pattern="\d{10}|\d{12}">
                                        </div>
                                    </div>
                                    <?php if ($index > 0): ?>
                                        <button type="button" class="btn-remove" onclick="removeAsset(<?php echo $index; ?>)" style="margin-top: 10px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addAsset()" style="margin-top: 10px; padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">+ Добавить актив</button>
                    </div>

                    <!-- Сделка -->
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Сделка (множественный выбор) <span style="color: red;">*</span></label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="deal_types[]" value="sale" 
                                       <?php echo in_array('sale', $_POST['deal_types'] ?? []) ? 'checked' : ''; ?>
                                       class="deal-type-checkbox" style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Продажа % Актива</span>
                            </label>
                            <div id="sale-percent-container" style="margin-left: 26px; <?php echo in_array('sale', $_POST['deal_types'] ?? []) ? '' : 'display: none;'; ?>">
                                <input type="number" name="deal_share_percent" value="<?php echo htmlspecialchars($_POST['deal_share_percent'] ?? ''); ?>" 
                                       min="0" max="100" step="0.01" placeholder="%" 
                                       style="width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                            </div>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="deal_types[]" value="investment" 
                                       <?php echo in_array('investment', $_POST['deal_types'] ?? []) ? 'checked' : ''; ?>
                                       class="deal-type-checkbox" style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Привлечение инвестиций (cash-in) в размере</span>
                            </label>
                            <div id="investment-amount-container" style="margin-left: 26px; <?php echo in_array('investment', $_POST['deal_types'] ?? []) ? '' : 'display: none;'; ?>">
                                <input type="number" name="investment_amount" value="<?php echo htmlspecialchars($_POST['investment_amount'] ?? ''); ?>" 
                                       min="0" step="0.01" placeholder="млн руб." 
                                       style="width: 200px; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                            </div>
                        </div>
                    </div>

                    <!-- Направление инвестиций -->
                    <div class="form-group" style="margin-bottom: 25px;" id="investment-purpose-container" style="<?php echo in_array('investment', $_POST['deal_types'] ?? []) ? '' : 'display: none;'; ?>">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Привлеченные инвестиции (cash-in) направляются на (множественный выбор)</label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="investment_purposes[]" value="development" 
                                       <?php echo in_array('development', $_POST['investment_purposes'] ?? []) ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Развитие бизнеса (капитальные затраты и т.п.)</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="investment_purposes[]" value="working_capital" 
                                       <?php echo in_array('working_capital', $_POST['investment_purposes'] ?? []) ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Пополнение оборотного капитала</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="investment_purposes[]" value="debt_repayment" 
                                       <?php echo in_array('debt_repayment', $_POST['investment_purposes'] ?? []) ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Погашение кредитов и займов от третьих сторон</span>
                            </label>
                        </div>
                    </div>

                    <!-- Срок действия соглашения -->
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Срок действия соглашения</label>
                        <input type="number" name="agreement_duration" value="<?php echo htmlspecialchars($_POST['agreement_duration'] ?? '3'); ?>" 
                               min="1" style="width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        <span style="margin-left: 10px;">месяц</span>
                    </div>

                    <!-- Эксклюзивность -->
                    <div class="form-group" style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Эксклюзивность</label>
                        <div style="display: flex; gap: 20px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="exclusivity" value="yes" 
                                       <?php echo ($_POST['exclusivity'] ?? 'no') === 'yes' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Да</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="exclusivity" value="no" 
                                       <?php echo ($_POST['exclusivity'] ?? 'no') === 'no' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Нет</span>
                            </label>
                        </div>
                        <p style="font-size: 13px; color: #666; margin-top: 5px;">(обязательство не вести переговоры о Сделке с другими Покупателями в течение срока действия соглашения)</p>
                    </div>
                </div>

                <!-- Раздел 2: Заверения об обстоятельствах -->
                <div class="form-section" style="margin-bottom: 40px;">
                    <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: #1a1a1a; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                        2. Заверения об обстоятельствах
                    </h2>
                    <p style="color: #666; margin-bottom: 15px;">Продавец предоставит Покупателю заверения об обстоятельствах, стандартные для такого типа сделок, включая, но не ограничиваясь, заверения в отношении:</p>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="warranties[legal_capacity]" value="1" 
                                   <?php echo isset($_POST['warranties']['legal_capacity']) ? 'checked' : 'checked'; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>Правоспособности (юридической возможности совершения сделки)</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="warranties[title]" value="1" 
                                   <?php echo isset($_POST['warranties']['title']) ? 'checked' : 'checked'; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>Титула на приобретаемые доли Актива</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="warranties[tax]" value="1" 
                                   <?php echo isset($_POST['warranties']['tax']) ? 'checked' : 'checked'; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>Налоговых вопросов</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="warranties[litigation]" value="1" 
                                   <?php echo isset($_POST['warranties']['litigation']) ? 'checked' : 'checked'; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>Судебных и административных споров Актива</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="warranties[compliance]" value="1" 
                                   <?php echo isset($_POST['warranties']['compliance']) ? 'checked' : 'checked'; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>Соблюдения применимого законодательства</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="warranties[other]" value="1" 
                                   <?php echo isset($_POST['warranties']['other']) ? 'checked' : ''; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>Иные заверения, согласованные Сторонами</span>
                        </label>
                    </div>
                    <p style="font-size: 13px; color: #666; margin-top: 15px;">Заверения об обстоятельствах Продавца будут даваться на дату подписания обязывающей документации и на дату закрытия Сделки.</p>
                </div>

                <!-- Раздел 3: Условия закрытия сделки -->
                <div class="form-section" style="margin-bottom: 40px;">
                    <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: #1a1a1a; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                        3. Условия закрытия сделки
                    </h2>
                    <p style="color: #666; margin-bottom: 15px;">Права и обязанности по закрытию Сделки возникают при наступлении всех перечисленных ниже обстоятельств:</p>
                    <div id="closing-conditions-container">
                        <?php foreach ($_POST['closing_conditions'] as $key => $value): ?>
                            <div class="closing-condition-item" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                                <input type="text" name="closing_conditions[<?php echo $key; ?>]" value="<?php echo htmlspecialchars($value); ?>" 
                                       style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                                <button type="button" onclick="removeClosingCondition('<?php echo $key; ?>')" 
                                        style="padding: 8px 12px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" onclick="addClosingCondition()" style="margin-top: 10px; padding: 8px 16px; background: #667eea; color: white; border: none; border-radius: 5px; cursor: pointer;">+ Добавить условие</button>
                </div>

                <!-- Раздел 4: Применимое право -->
                <div class="form-section" style="margin-bottom: 40px;">
                    <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: #1a1a1a; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                        4. Применимое право
                    </h2>
                    <div class="form-group">
                        <input type="text" name="applicable_law" value="<?php echo htmlspecialchars($_POST['applicable_law'] ?? 'российское право'); ?>" 
                               style="width: 100%; max-width: 400px; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                    </div>
                </div>

                <!-- Раздел 5: Корпоративное управление -->
                <div class="form-section" style="margin-bottom: 40px;">
                    <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: #1a1a1a; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                        5. Корпоративное управление после сделки
                    </h2>
                    <p style="color: #666; margin-bottom: 15px; font-style: italic;">(при продаже менее 100% Актива)</p>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Генеральный директор назначается:</label>
                        <div style="display: flex; gap: 20px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="corporate_governance_ceo" value="buyer" 
                                       <?php echo ($_POST['corporate_governance_ceo'] ?? '') === 'buyer' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Покупателем</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="corporate_governance_ceo" value="seller" 
                                       <?php echo ($_POST['corporate_governance_ceo'] ?? '') === 'seller' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Текущими акционерами</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="corporate_governance_ceo" value="unanimous" 
                                       <?php echo ($_POST['corporate_governance_ceo'] ?? '') === 'unanimous' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Единогласно</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Финансовый директор назначается:</label>
                        <div style="display: flex; gap: 20px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="corporate_governance_cfo" value="buyer" 
                                       <?php echo ($_POST['corporate_governance_cfo'] ?? '') === 'buyer' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Покупателем</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="corporate_governance_cfo" value="seller" 
                                       <?php echo ($_POST['corporate_governance_cfo'] ?? '') === 'seller' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Текущими акционерами</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="radio" name="corporate_governance_cfo" value="unanimous" 
                                       <?php echo ($_POST['corporate_governance_cfo'] ?? '') === 'unanimous' ? 'checked' : ''; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Единогласно</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Вопросы, требующие единогласного решения участников совета директоров:</label>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[charter]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['charter']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Изменение устава и уставного капитала</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[budget]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['budget']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Утверждение бюджета / бизнес-плана</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[dividends]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['dividends']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Распределение чистой прибыли и дивидендная политика</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[major_transactions]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['major_transactions']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Совершение любых сделок на сумму свыше <input type="number" name="major_transaction_threshold" value="<?php echo htmlspecialchars($_POST['major_transaction_threshold'] ?? '10'); ?>" style="width: 100px; padding: 4px; border: 1px solid #ddd; border-radius: 3px; margin: 0 5px;"> млн руб., за исключением сделок, условия которых во всех существенных аспектах утверждены в рамках бюджета Общества</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[real_estate]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['real_estate']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Совершение любых сделок с недвижимостью</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[ip]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['ip']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Совершение любых сделок с интеллектуальной собственностью</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[litigation]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['litigation']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Вопросы, связанные с участием в судебных и арбитражных процессах (в случае если сумма требований превышает <input type="number" name="litigation_threshold" value="<?php echo htmlspecialchars($_POST['litigation_threshold'] ?? '5'); ?>" style="width: 100px; padding: 4px; border: 1px solid #ddd; border-radius: 3px; margin: 0 5px;"> млн руб.)</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[executive_compensation]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['executive_compensation']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Утверждение условий трудовых договоров с работниками, годовое вознаграждение которых до вычета налогов превышает или может превысить <input type="number" name="executive_compensation_threshold" value="<?php echo htmlspecialchars($_POST['executive_compensation_threshold'] ?? '3'); ?>" style="width: 100px; padding: 4px; border: 1px solid #ddd; border-radius: 3px; margin: 0 5px;"> млн руб.</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[subsidiaries]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['subsidiaries']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Принятие решений об участии в уставных капиталах иных компаний (включая создание дочерних обществ, приобретение и отчуждение акций и долей)</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[debt]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['debt']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Совершение любых сделок, влекущих возникновение финансовой задолженности (включая займы, кредиты, векселя) на сумму свыше <input type="number" name="debt_threshold" value="<?php echo htmlspecialchars($_POST['debt_threshold'] ?? '10'); ?>" style="width: 100px; padding: 4px; border: 1px solid #ddd; border-radius: 3px; margin: 0 5px;"> млн руб.</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[guarantees]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['guarantees']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Предоставление любых обеспечений по обязательствам третьих лиц (включая гарантии, поручительства и иные обеспечительные сделки)</span>
                            </label>
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="unanimous_decisions_list[financing]" value="1" 
                                       <?php echo isset($_POST['unanimous_decisions_list']['financing']) ? 'checked' : 'checked'; ?>
                                       style="margin-right: 8px; width: 18px; height: 18px;">
                                <span>Предоставление финансирования третьим лицам в любой форме</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Раздел 6: Преимущественное право -->
                <div class="form-section" style="margin-bottom: 40px;">
                    <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: #1a1a1a; border-bottom: 2px solid #667eea; padding-bottom: 10px;">
                        6. Преимущественное право
                    </h2>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox" name="preemptive_right" value="1" 
                                   <?php echo isset($_POST['preemptive_right']) ? 'checked' : 'checked'; ?>
                                   style="margin-right: 8px; width: 18px; height: 18px;">
                            <span>В случае, если одна из Сторон намерена продать свои доли/акции Актива третьему лицу, другие Стороны пользуются правом преимущественной покупки долей/акций</span>
                        </label>
                    </div>
                </div>

                <!-- Кнопки действий -->
                <div style="display: flex; gap: 15px; margin-top: 40px; padding-top: 30px; border-top: 2px solid #e9ecef;">
                    <button type="submit" name="save_draft" class="btn btn-secondary" style="padding: 12px 24px; background: #6c757d; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer;">
                        Сохранить черновик
                    </button>
                    <button type="submit" name="submit" class="btn btn-primary" style="padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">
                        Отправить Term Sheet
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .required-field {
            border-left: 3px solid #667eea;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 15px;
            }
            
            .form-section {
                padding: 20px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
        }
    </style>

    <script>
        // Автоматически скрываем сообщение о сохранении черновика
        document.addEventListener('DOMContentLoaded', function() {
            const draftMessage = document.getElementById('draft-saved-message');
            if (draftMessage) {
                setTimeout(function() {
                    draftMessage.style.opacity = '0';
                    draftMessage.style.transform = 'translateY(-10px)';
                    setTimeout(function() {
                        draftMessage.style.display = 'none';
                    }, 500);
                }, 3000);
            }

            // Обработка чекбоксов типа сделки
            const dealTypeCheckboxes = document.querySelectorAll('.deal-type-checkbox');
            dealTypeCheckboxes.forEach(function(checkbox) {
                checkbox.addEventListener('change', function() {
                    if (this.value === 'sale') {
                        document.getElementById('sale-percent-container').style.display = this.checked ? 'block' : 'none';
                    } else if (this.value === 'investment') {
                        document.getElementById('investment-amount-container').style.display = this.checked ? 'block' : 'none';
                        document.getElementById('investment-purpose-container').style.display = this.checked ? 'block' : 'none';
                    }
                });
            });

            // Установка значения deal_type для валидации
            const form = document.getElementById('term-sheet-form');
            
            // Обработка нажатия на кнопку "Сохранить черновик"
            const saveDraftBtn = form.querySelector('button[name="save_draft"]');
            if (saveDraftBtn) {
                saveDraftBtn.addEventListener('click', function(e) {
                    document.getElementById('save_draft_flag').value = '1';
                    // Удаляем кнопку submit из формы, чтобы она не отправилась
                    const submitBtn = form.querySelector('button[name="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        setTimeout(() => { submitBtn.disabled = false; }, 100);
                    }
                });
            }
            
            // Обработка нажатия на кнопку "Отправить Term Sheet"
            const submitBtn = form.querySelector('button[name="submit"]');
            if (submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    document.getElementById('save_draft_flag').value = '0';
                    // Удаляем кнопку save_draft из формы, чтобы она не отправилась
                    if (saveDraftBtn) {
                        saveDraftBtn.disabled = true;
                        setTimeout(() => { saveDraftBtn.disabled = false; }, 100);
                    }
                });
            }
            
            form.addEventListener('submit', function(e) {
                const checkedDealTypes = Array.from(document.querySelectorAll('.deal-type-checkbox:checked')).map(cb => cb.value);
                if (checkedDealTypes.length > 0) {
                    // Создаем скрытое поле для валидации
                    let hiddenInput = document.querySelector('input[name="deal_type"]');
                    if (!hiddenInput) {
                        hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'deal_type';
                        form.appendChild(hiddenInput);
                    }
                    hiddenInput.value = checkedDealTypes.join(',');
                }
            });
        });

        let buyerIndex = <?php echo count($_POST['buyers']); ?>;
        let sellerIndex = <?php echo count($_POST['sellers']); ?>;
        let assetIndex = <?php echo count($_POST['assets']); ?>;
        let closingConditionIndex = <?php echo count($_POST['closing_conditions']); ?>;

        function addBuyer() {
            const container = document.getElementById('buyers-container');
            const newItem = document.createElement('div');
            newItem.className = 'buyer-item';
            newItem.style.cssText = 'background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;';
            newItem.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">Название ЮЛ</label>
                        <input type="text" name="buyers[${buyerIndex}][name]" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН</label>
                        <input type="text" name="buyers[${buyerIndex}][inn]" class="form-control" pattern="\\d{10}|\\d{12}">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ФИО ФЛ <span style="color: #666; font-size: 12px;">(если покупатель - физ. лицо)</span></label>
                        <input type="text" name="buyers[${buyerIndex}][individual_name]" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН ФЛ</label>
                        <input type="text" name="buyers[${buyerIndex}][individual_inn]" class="form-control" pattern="\\d{12}">
                    </div>
                </div>
                <button type="button" class="btn-remove" onclick="removeBuyer(${buyerIndex})" style="margin-top: 10px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
            `;
            container.appendChild(newItem);
            buyerIndex++;
        }

        function removeBuyer(index) {
            const item = document.querySelector(`.buyer-item input[name="buyers[${index}][name]"]`)?.closest('.buyer-item');
            if (item) {
                item.remove();
            }
        }

        function addSeller() {
            const container = document.getElementById('sellers-container');
            const newItem = document.createElement('div');
            newItem.className = 'seller-item';
            newItem.style.cssText = 'background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;';
            newItem.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 10px;">
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">Название ЮЛ</label>
                        <input type="text" name="sellers[${sellerIndex}][name]" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН</label>
                        <input type="text" name="sellers[${sellerIndex}][inn]" class="form-control" pattern="\\d{10}|\\d{12}">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ФИО ФЛ <span style="color: #666; font-size: 12px;">(если продавец - физ. лицо)</span></label>
                        <input type="text" name="sellers[${sellerIndex}][individual_name]" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН ФЛ</label>
                        <input type="text" name="sellers[${sellerIndex}][individual_inn]" class="form-control" pattern="\\d{12}">
                    </div>
                </div>
                <button type="button" class="btn-remove" onclick="removeSeller(${sellerIndex})" style="margin-top: 10px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
            `;
            container.appendChild(newItem);
            sellerIndex++;
        }

        function removeSeller(index) {
            const item = document.querySelector(`.seller-item input[name="sellers[${index}][name]"]`)?.closest('.seller-item');
            if (item) {
                item.remove();
            }
        }

        function addAsset() {
            const container = document.getElementById('assets-container');
            const newItem = document.createElement('div');
            newItem.className = 'asset-item';
            newItem.style.cssText = 'background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;';
            newItem.innerHTML = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">Название ЮЛ</label>
                        <input type="text" name="assets[${assetIndex}][name]" class="form-control">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; margin-bottom: 5px;">ИНН</label>
                        <input type="text" name="assets[${assetIndex}][inn]" class="form-control" pattern="\\d{10}|\\d{12}">
                    </div>
                </div>
                <button type="button" class="btn-remove" onclick="removeAsset(${assetIndex})" style="margin-top: 10px; padding: 5px 10px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
            `;
            container.appendChild(newItem);
            assetIndex++;
        }

        function removeAsset(index) {
            const item = document.querySelector(`.asset-item input[name="assets[${index}][name]"]`)?.closest('.asset-item');
            if (item) {
                item.remove();
            }
        }

        function addClosingCondition() {
            const container = document.getElementById('closing-conditions-container');
            const newItem = document.createElement('div');
            newItem.className = 'closing-condition-item';
            newItem.style.cssText = 'margin-bottom: 15px; display: flex; align-items: center; gap: 10px;';
            newItem.innerHTML = `
                <input type="text" name="closing_conditions[condition_${closingConditionIndex}]" placeholder="Введите условие" 
                       style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                <button type="button" onclick="removeClosingCondition('condition_${closingConditionIndex}')" 
                        style="padding: 8px 12px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer;">Удалить</button>
            `;
            container.appendChild(newItem);
            closingConditionIndex++;
        }

        function removeClosingCondition(key) {
            const item = document.querySelector(`input[name="closing_conditions[${key}]"]`)?.closest('.closing-condition-item');
            if (item) {
                item.remove();
            }
        }
    </script>
</body>
</html>

