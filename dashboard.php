<?php
/**
 * Личный кабинет продавца
 * 
 * Функциональность:
 * - Просмотр статистики по анкетам
 * - Список всех анкет пользователя с фильтрацией по статусу
 * - Переход к созданию новой анкеты
 * - Переход к настройкам профиля
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

/**
 * Проверка авторизации пользователя
 * Если пользователь не авторизован, происходит редирект на страницу входа
 */
if (!isLoggedIn()) {
    redirectToLogin();
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

// Проверяем и добавляем поле welcome_shown, если его нет
ensureUsersWelcomeField();

// Проверяем, нужно ли показывать приветственное окно
$showWelcomeModal = false;

// Принудительный показ через параметр URL (для тестирования)
if (isset($_GET['force_welcome'])) {
    $showWelcomeModal = true;
    error_log("Welcome modal forced via URL parameter");
} else {
    // Проверяем значение в БД
    try {
        $pdo = getDBConnection();
        $effectiveUserId = getEffectiveUserId();
        $stmt = $pdo->prepare("SELECT welcome_shown FROM users WHERE id = ?");
        $stmt->execute([$effectiveUserId]);
        $userData = $stmt->fetch();
        
        error_log("User welcome_shown value: " . var_export($userData, true));
        
        // Показываем окно, если:
        // 1. Поле не существует (NULL) - для старых пользователей
        // 2. Поле равно 0 - для новых пользователей
        if (!isset($userData['welcome_shown']) || $userData['welcome_shown'] == 0 || $userData['welcome_shown'] === null) {
            $showWelcomeModal = true;
            error_log("Welcome modal will be shown (welcome_shown is 0 or null)");
        } else {
            error_log("Welcome modal will NOT be shown (welcome_shown = " . $userData['welcome_shown'] . ")");
        }
    } catch (PDOException $e) {
        error_log("Error checking welcome_shown: " . $e->getMessage());
        // При ошибке лучше показать окно, чем молчать
        $showWelcomeModal = true;
        error_log("Welcome modal will be shown due to error");
    }
}

/**
 * Получение всех анкет текущего пользователя из базы данных
 * Анкеты сортируются по дате создания (новые первыми)
 */
try {
    $pdo = getDBConnection();
    $effectiveUserId = getEffectiveUserId();
    $stmt = $pdo->prepare("
        SELECT id, asset_name, status, created_at, updated_at, submitted_at 
        FROM seller_forms 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$effectiveUserId]);
    $forms = $stmt->fetchAll();
    
    // Загружаем информацию о модерации тизеров для каждой формы
    ensurePublishedTeasersTable();
    foreach ($forms as &$form) {
        $moderationStmt = $pdo->prepare("
            SELECT 
                moderation_status,
                created_at,
                moderated_at,
                published_at,
                moderation_notes
            FROM published_teasers
            WHERE seller_form_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $moderationStmt->execute([$form['id']]);
        $moderationInfo = $moderationStmt->fetch();
        $form['teaser_moderation'] = $moderationInfo ?: null;
    }
    unset($form);
} catch (PDOException $e) {
    error_log("Error fetching forms: " . $e->getMessage());
    $forms = [];
}

$activeForms = array_values(array_filter($forms, fn($f) => $f['status'] !== 'draft'));
$draftForms = array_values(array_filter($forms, fn($f) => $f['status'] === 'draft'));

/**
 * Определение активной анкеты для отображения инструментов
 * Приоритет:
 * 1. form_id из URL параметра (если указан и принадлежит пользователю)
 * 2. Последняя отправленная анкета (submitted/review/approved)
 * 3. Последняя анкета по дате обновления
 */
$selectedFormId = isset($_GET['form_id']) ? (int)$_GET['form_id'] : null;
$selectedForm = null;

if ($selectedFormId) {
    if (isModerator() && !isImpersonating()) {
        // Модераторы (не в режиме impersonation) могут просматривать DCF для любой анкеты
        $stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ?");
        $stmt->execute([$selectedFormId]);
        $selectedForm = $stmt->fetch();
    } else {
        // Обычные пользователи или модераторы в режиме impersonation - только свои анкеты
        foreach ($forms as $form) {
            if ($form['id'] == $selectedFormId) {
                $selectedForm = $form;
                break;
            }
        }
    }
}

// Если анкета не выбрана или не найдена, выбираем последнюю отправленную
if (!$selectedForm) {
    $selectedForm = !empty($activeForms) ? $activeForms[0] : (!empty($forms) ? $forms[0] : null);
}

/**
 * Получение всех Term Sheet текущего пользователя из базы данных
 */
try {
    // Проверяем существование таблицы перед запросом
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS term_sheet_forms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            buyer_name VARCHAR(500) DEFAULT NULL,
            buyer_inn VARCHAR(20) DEFAULT NULL,
            seller_name VARCHAR(500) DEFAULT NULL,
            seller_inn VARCHAR(20) DEFAULT NULL,
            asset_name VARCHAR(500) DEFAULT NULL,
            asset_inn VARCHAR(20) DEFAULT NULL,
            deal_type VARCHAR(255) DEFAULT NULL,
            deal_share_percent DECIMAL(5,2) DEFAULT NULL,
            investment_amount DECIMAL(15,2) DEFAULT NULL,
            agreement_duration INT DEFAULT 3,
            exclusivity ENUM('yes', 'no') DEFAULT 'no',
            applicable_law VARCHAR(255) DEFAULT 'российское право',
            corporate_governance_ceo VARCHAR(255) DEFAULT NULL,
            corporate_governance_cfo VARCHAR(255) DEFAULT NULL,
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
    
    $effectiveUserId = getEffectiveUserId();
    $stmt = $pdo->prepare("
        SELECT id, buyer_name, seller_name, asset_name, status, created_at, updated_at, submitted_at 
        FROM term_sheet_forms 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$effectiveUserId]);
    $termSheets = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching term sheets: " . $e->getMessage());
    $termSheets = [];
}

/**
 * Маппинг статусов анкет для отображения
 * Каждый статус имеет текстовое название и цвет для визуального отображения
 */
$statusLabels = [
    'draft' => 'Черновик',
    'submitted' => 'Отправлена',
    'review' => 'На проверке',
    'approved' => 'Одобрена',
    'rejected' => 'Отклонена'
];

$statusColors = [
    'draft' => '#86868B',
    'submitted' => '#007AFF',
    'review' => '#FF9500',
    'approved' => '#34C759',
    'rejected' => '#FF3B30'
];

/**
 * Вспомогательные функции для DCF
 */

/**
 * Определяет единицы измерения из строки
 * Поддерживает: "тыс. руб.", "млн. руб.", "тыс руб", "млн руб" и их варианты
 * 
 * @param string $unit Строка с единицами измерения
 * @return string 'thousands' для тысяч, 'millions' для миллионов, 'unknown' если не определено
 */
function detectUnit(string $unit): string {
    $unit = mb_strtolower(trim($unit));
    if (empty($unit)) {
        return 'unknown';
    }
    // Проверяем на наличие "тыс" (тысячи)
    if (preg_match('/тыс/', $unit)) {
        return 'thousands';
    }
    // Проверяем на наличие "млн" (миллионы)
    if (preg_match('/млн/', $unit)) {
        return 'millions';
    }
    return 'unknown';
}

/**
 * Конвертирует значение в миллионы рублей с учетом единиц измерения
 * Если значение в тысячах, делит на 1000; если в миллионах, оставляет как есть
 * 
 * @param mixed $value Значение для конвертации
 * @param string $unit Единицы измерения ('thousands', 'millions', 'unknown')
 * @return float Значение в миллионах рублей
 */
function convertToMillions($value, string $unit): float {
    $numValue = dcf_to_float($value);
    if ($numValue == 0) {
        return 0.0;
    }
    
    switch ($unit) {
        case 'thousands':
            // Конвертируем из тысяч в миллионы (делим на 1000)
            return $numValue / 1000.0;
        case 'millions':
            // Уже в миллионах, возвращаем как есть
            return $numValue;
        case 'unknown':
        default:
            // Если единицы не определены, предполагаем миллионы (для обратной совместимости)
            return $numValue;
    }
}

/**
 * Преобразует значение в число с плавающей точкой
 * Обрабатывает различные форматы: null, пустые строки, числа, строки с пробелами и запятыми
 * 
 * @param mixed $value Значение для преобразования
 * @return float Преобразованное число (0.0 если значение невалидно)
 */
function dcf_to_float($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    // Нормализация: удаление пробелов и замена запятой на точку
    $normalized = str_replace([' ', ' '], '', (string)$value);
    $normalized = str_replace(',', '.', $normalized);
    return is_numeric($normalized) ? (float)$normalized : 0.0;
}

/**
 * Преобразует массив строк в ассоциативный массив по метрикам
 * Используется для быстрого доступа к данным по названию метрики
 * 
 * @param array|null $rows Массив строк с полем 'metric'
 * @return array Ассоциативный массив [метрика => строка данных]
 */
function dcf_rows_by_metric(?array $rows): array {
    $result = [];
    foreach ($rows ?? [] as $row) {
        if (!empty($row['metric'])) {
            $result[$row['metric']] = $row;
        }
    }
    return $result;
}

/**
 * Строит временной ряд значений из строки данных
 * Преобразует данные из формата с ключами (fact_2022, budget_2025 и т.д.) в временной ряд
 * Конвертирует все значения в миллионы рублей с учетом единиц измерения
 * 
 * @param array $row Строка данных с ключами периодов и полем 'unit'
 * @param array $order Маппинг ключей периодов на метки (например, 'fact_2022' => '2022')
 * @return array Временной ряд [метка => значение в миллионах рублей]
 */
function dcf_build_series(array $row, array $order): array {
    $series = [];
    // Определяем единицы измерения из строки данных
    $unitStr = $row['unit'] ?? '';
    $unit = detectUnit($unitStr);
    
    foreach ($order as $key => $label) {
        $value = $row[$key] ?? 0;
        // Конвертируем значение в миллионы рублей с учетом единиц
        $series[$label] = convertToMillions($value, $unit);
    }
    return $series;
}

/**
 * Унификация значений (поддержка новых ключей 2022_fact и legacy fact_2022)
 * Позволяет использовать как старый формат (fact_2022), так и новый (2022_fact)
 * 
 * @param array $row Строка данных
 * @param array $keys Массив ключей для проверки (в порядке приоритета)
 * @return string Первое найденное непустое значение или пустая строка
 */
function pickValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return '';
}

/**
 * Конвертирует финансовые данные из нового формата (ключи: revenue, cost_of_sales и т.д.)
 * в старый формат (массив строк с полем 'metric')
 * Обеспечивает обратную совместимость с существующей логикой DCF
 * 
 * @param array $financial Финансовые данные в новом или старом формате
 * @return array Финансовые данные в старом формате (массив строк с 'metric')
 */
function convertFinancialRows(array $financial): array
{
    if (empty($financial)) {
        return [];
    }

    // Если данные уже в старом формате, возвращаем как есть
    $first = reset($financial);
    if (is_array($first) && isset($first['metric'])) {
        return $financial;
    }

    $map = [
        'revenue' => 'Выручка',
        'cost_of_sales' => 'Себестоимость продаж',
        'commercial_expenses' => 'Коммерческие расходы',
        'management_expenses' => 'Управленческие расходы',
        'sales_profit' => 'Прибыль от продаж',
        'depreciation' => 'Амортизация',
        'fixed_assets_acquisition' => 'Приобретение основных средств',
    ];

    $fields = [
        'unit'         => ['unit'],
        'fact_2022'    => ['fact_2022', '2022_fact'],
        'fact_2023'    => ['fact_2023', '2023_fact'],
        'fact_2024'    => ['fact_2024', '2024_fact'],
        'fact_2025'    => ['fact_2025', '2025_fact', '2025_budget'],
        'budget_2026'  => ['budget_2026', '2026_budget'],
    ];

    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($financial[$key]) || !is_array($financial[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        foreach ($fields as $legacyKey => $aliases) {
            $row[$legacyKey] = pickValue($financial[$key], $aliases);
        }
        $result[] = $row;
    }

    return $result;
}

/**
 * Конвертирует балансовые данные из нового формата (ключи: fixed_assets, inventory и т.д.)
 * в старый формат (массив строк с полем 'metric')
 * Обеспечивает обратную совместимость с существующей логикой DCF
 * 
 * @param array $balance Балансовые данные в новом или старом формате
 * @return array Балансовые данные в старом формате (массив строк с 'metric')
 */
function convertBalanceRows(array $balance): array
{
    if (empty($balance)) {
        return [];
    }

    // Если данные уже в старом формате, возвращаем как есть
    $first = reset($balance);
    if (is_array($first) && isset($first['metric'])) {
        return $balance;
    }

    $map = [
        'fixed_assets' => 'Основные средства',
        'inventory'    => 'Запасы',
        'receivables'  => 'Дебиторская задолженность',
        'payables'     => 'Кредиторская задолженность',
        'loans'        => 'Кредиты и займы',
        'cash'         => 'Денежные средства',
        'net_assets'   => 'Чистые активы',
    ];

    $fields = [
        'unit'         => ['unit'],
        'fact_2022'    => ['fact_2022', '2022_fact'],
        'fact_2023'    => ['fact_2023', '2023_fact'],
        'fact_2024'    => ['fact_2024', '2024_fact'],
        'fact_2025'    => ['fact_2025', '2025_fact', '2025_q3_fact'],
    ];

    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($balance[$key]) || !is_array($balance[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        foreach ($fields as $legacyKey => $aliases) {
            $row[$legacyKey] = pickValue($balance[$key], $aliases);
        }
        $result[] = $row;
    }

    return $result;
}

/**
 * Извлекает финансовые и балансовые данные из формы
 * Приоритет: сначала проверяет старые поля (financial_results, balance_indicators),
 * затем data_json (новый формат хранения всех данных формы)
 * 
 * @param array $form Данные формы из базы данных
 * @return array Массив [финансовые данные, балансовые данные] в унифицированном формате
 */
function extractFinancialAndBalance(array $form): array
{
    // Пытаемся получить данные из старых полей
    $financial = json_decode($form['financial_results'] ?? '[]', true);
    $balance   = json_decode($form['balance_indicators'] ?? '[]', true);

    // Если старые поля пусты, пытаемся получить из data_json (новый формат)
    if (empty($form['data_json']) === false) {
        $decoded = json_decode($form['data_json'], true);
        if (empty($financial) && isset($decoded['financial']) && is_array($decoded['financial'])) {
            $financial = $decoded['financial'];
        }
        if (empty($balance) && isset($decoded['balance']) && is_array($decoded['balance'])) {
            $balance = $decoded['balance'];
        }
    }

    // Конвертируем в унифицированный формат (старый формат с полем 'metric')
    $financial = convertFinancialRows($financial);
    $balance   = convertBalanceRows($balance);

    return [$financial, $balance];
}

/**
 * Генерирует псевдослучайное число на основе seed и offset
 * Гарантирует детерминированность: одинаковые seed и offset дают одинаковый результат
 * Используется для добавления небольших случайных вариаций в расчеты DCF
 * 
 * @param string $seed Строка-ключ для генерации (обычно название актива + ID формы)
 * @param int $offset Смещение для получения разных значений при одном seed
 * @param float $min Минимальное значение
 * @param float $max Максимальное значение
 * @return float Псевдослучайное число в диапазоне [min, max]
 */
function stableRandFloat(string $seed, int $offset, float $min, float $max): float
{
    $hash = crc32($seed . '|' . $offset);
    $normalized = fmod(abs(sin($hash + $offset * 12.9898)), 1);
    return $min + ($max - $min) * $normalized;
}

/**
 * Ограничивает значение заданными границами
 * 
 * @param float $value Значение для ограничения
 * @param float $min Минимальное значение
 * @param float $max Максимальное значение
 * @return float Значение в диапазоне [min, max]
 */
function clampFloat(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

/**
 * Генерирует SVG иконку для элемента hero блока тизера.
 * 
 * @param string $iconType Тип иконки (segment, location, people, brand, online, share, goal, default)
 * @return string SVG код иконки
 */
function getTeaserChipIcon(string $iconType): string
{
    $icons = [
        'segment' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'location' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 10C21 17 12 23 12 23C12 23 3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 13C13.6569 13 15 11.6569 15 10C15 8.34315 13.6569 7 12 7C10.3431 7 9 8.34315 9 10C9 11.6569 10.3431 13 12 13Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'people' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M17 21V19C17 17.9391 16.5786 16.9217 15.8284 16.1716C15.0783 15.4214 14.0609 15 13 15H5C3.93913 15 2.92172 15.4214 2.17157 16.1716C1.42143 16.9217 1 17.9391 1 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 7C9 9.20914 7.20914 11 5 11C2.79086 11 1 9.20914 1 7C1 4.79086 2.79086 3 5 3C7.20914 3 9 4.79086 9 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 21V19C22.9993 18.1137 22.7044 17.2528 22.1614 16.5523C21.6184 15.8519 20.8581 15.3516 20 15.13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 3.13C16.8604 3.35031 17.623 3.85071 18.1676 4.55232C18.7122 5.25392 19.0078 6.11683 19.0078 7.005C19.0078 7.89318 18.7122 8.75608 18.1676 9.45769C17.623 10.1593 16.8604 10.6597 16 10.88" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'brand' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'online' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 8V12L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.93 6.5L5.34 7.91" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.07 6.5L18.66 7.91" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.5 3.93L7.91 5.34" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5 3.93L16.09 5.34" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3.93 17.5L5.34 16.09" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M20.07 17.5L18.66 16.09" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M6.5 20.07L7.91 18.66" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M17.5 20.07L16.09 18.66" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'share' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 8C19.6569 8 21 6.65685 21 5C21 3.34315 19.6569 2 18 2C16.3431 2 15 3.34315 15 5C15 5.12549 15.0077 5.24919 15.0227 5.37063L8.08261 9.79837C7.54305 9.29264 6.80891 9 6 9C4.34315 9 3 10.3431 3 12C3 13.6569 4.34315 15 6 15C6.80891 15 7.54305 14.7074 8.08261 14.2016L15.0227 18.6294C15.0077 18.7508 15 18.8745 15 19C15 20.6569 16.3431 22 18 22C19.6569 22 21 20.6569 21 19C21 17.3431 19.6569 16 18 16C17.1911 16 16.457 16.2926 15.9174 16.7984L8.97727 12.3706C8.99231 12.2492 9 12.1255 9 12C9 11.8745 8.99231 11.7508 8.97727 11.6294L15.9174 7.20163C16.457 7.70736 17.1911 8 18 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'goal' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        'default' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    ];
    
    return $icons[$iconType] ?? $icons['default'];
}

/**
 * Удаляет упоминания вида «M&A платформа» из текста.
 * Используется для предотвращения появления служебных описаний в тизере.
 * 
 * Обрабатывает различные варианты написания:
 * - M&A платформа, M&A-платформа
 * - HTML-сущности (M&amp;A)
 * - Кириллические варианты (М&А)
 * - Английские варианты (M&A platform)
 * 
 * @param string $text Исходный текст
 * @return string Текст без упоминаний M&A платформы
 */
function removeMaPlatformPhrase(string $text): string
{
    if ($text === '') {
        return $text;
    }

    $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Список фраз для удаления
    $phrases = [
        'M&A платформа',
        'M&A-платформа',
        'M&A platform',
        'M&amp;A платформа',
        'M&amp;A-платформа',
        'M&amp;A platform',
        'М&A платформа',
        'М&A-платформа',
        'М&amp;A платформа',
        'М&amp;A-платформа',
    ];

    // Удаление точных совпадений
    foreach ($phrases as $phrase) {
        $decoded = str_ireplace($phrase, '', $decoded);
    }

    // Удаление через регулярные выражения для более гибкого поиска
    $decoded = preg_replace('/M\s*&(?:amp;)?\s*A\s*-?\s*платформ[аы]/iu', '', $decoded);
    $decoded = preg_replace('/М\s*&(?:amp;)?\s*А\s*-?\s*платформ[аы]/iu', '', $decoded);
    $decoded = preg_replace('/M\s*&(?:amp;)?\s*A\s*-?\s*platform[aы]?/iu', '', $decoded);

    // Нормализация пробелов
    $decoded = trim(preg_replace('/\s+/u', ' ', $decoded));

    return $decoded;
}

/**
 * Строит полную DCF-модель на основе последней отправленной анкеты пользователя.
 * Возвращает не только итоговые показатели, но и полный набор параметров/предупреждений
 * для отображения в личном кабинете.
 * 
 * Алгоритм:
 * 1. Извлечение и нормализация финансовых и балансовых данных
 * 2. Расчет фактических показателей за 2022-2024 годы
 * 3. Расчет темпов роста на основе исторических данных
 * 4. Построение прогноза на 5 лет (P1-P5) с учетом факта 2025
 * 5. Расчет FCFF (Free Cash Flow to Firm) для каждого прогнозного периода
 * 6. Дисконтирование FCFF к текущей стоимости
 * 7. Расчет Terminal Value по модели Гордона
 * 8. Расчет Enterprise Value и Equity Value
 * 
 * @param array $form Данные формы из базы данных
 * @return array Массив с результатами расчета DCF или ошибкой
 */
function calculateUserDCF(array $form): array {
    // Параметры модели по умолчанию
    // WACC рассчитывается с помощью ИИ на основе отраслевой принадлежности компании
    $calculatedWACC = calculateWACCWithAI($form);
    $defaults = [
        'wacc' => $calculatedWACC,   // Средневзвешенная стоимость капитала (рассчитывается ИИ: 18-22%)
        'tax_rate' => 0.25,          // Ставка налога на прибыль (25%)
        'perpetual_growth' => 0.04,  // Темп бессрочного роста (4%)
        'vat_rate' => 0.20,          // Ставка НДС (20%) — будет переопределена по анкете
    ];
    
    // Логирование вычисленного WACC для отладки
    error_log("DCF Calculation: WACC = " . ($calculatedWACC * 100) . "% для формы ID " . ($form['id'] ?? 'unknown'));

    // Определяем ставку НДС из анкеты (with_vat / without_vat)
    $vatFlag = $form['financial_results_vat'] ?? null;
    if (empty($vatFlag) && !empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        $vatFlag = $decoded['financial_results_vat'] ?? $vatFlag;
    }
    if ($vatFlag === 'without_vat') {
        $defaults['vat_rate'] = 0.0;   // Если указано "без НДС", не очищаем
    } elseif ($vatFlag === 'with_vat') {
        $defaults['vat_rate'] = 0.20;  // Стандартная ставка 20%
    }

    /**
     * Очищает значение от НДС.
     * Формула: значение_без_НДС = значение_с_НДС / (1 + ставка_НДС)
     * 
     * @param float|null $value Значение с НДС
     * @param float $vatRate Ставка НДС (по умолчанию 20%)
     * @return float|null Значение без НДС
     */
    $removeVAT = function ($value, float $vatRate = 0.20) {
        if ($value === null || $value <= 0) {
            return $value;
        }
        return $value / (1 + $vatRate);
    };

    /**
     * Очищает массив значений от НДС.
     * 
     * @param array $values Массив значений с НДС
     * @param float $vatRate Ставка НДС
     * @return array Массив значений без НДС
     */
    $removeVATFromArray = function (array $values, float $vatRate = 0.20) use ($removeVAT): array {
        $result = [];
        foreach ($values as $key => $value) {
            $result[$key] = $removeVAT($value, $vatRate);
        }
        return $result;
    };

    // Маппинг ключей периодов из базы данных на метки для временных рядов
    $periodMap = [
        'fact_2022'    => '2022',    // Факт 2022 года
        'fact_2023'    => '2023',    // Факт 2023 года
        'fact_2024'    => '2024',    // Факт 2024 года
        'fact_2025'    => '2025',    // Факт 2025 года
        'budget_2026'  => '2026',    // Бюджет 2026 года
    ];

    // Извлечение финансовых и балансовых данных из формы
    list($financial, $balance) = extractFinancialAndBalance($form);
    if (!$financial || !$balance) {
        return ['error' => 'Недостаточно финансовых данных для построения модели.'];
    }

    // Преобразование в ассоциативные массивы для быстрого доступа по метрикам
    $finRows = dcf_rows_by_metric($financial);
    $balRows = dcf_rows_by_metric($balance);

    // Проверка наличия обязательных метрик
    $requiredMetrics = ['Выручка', 'Себестоимость продаж', 'Коммерческие расходы'];
    foreach ($requiredMetrics as $metric) {
        if (!isset($finRows[$metric])) {
            return ['error' => 'Не заполнены обязательные строки финансовой таблицы (выручка/расходы).'];
        }
    }

    // Построение временных рядов для финансовых показателей
    $revenueSeries   = dcf_build_series($finRows['Выручка'], $periodMap);
    $cogsSeries      = dcf_build_series($finRows['Себестоимость продаж'], $periodMap);
    $commercialSeries= dcf_build_series($finRows['Коммерческие расходы'], $periodMap);
    $adminSeries     = isset($finRows['Управленческие расходы']) ? dcf_build_series($finRows['Управленческие расходы'], $periodMap) : [];
    $deprSeries      = isset($finRows['Амортизация']) ? dcf_build_series($finRows['Амортизация'], $periodMap) : [];

    // Построение временных рядов для балансовых показателей
    $balanceSeries = [];
    foreach ($balRows as $metric => $row) {
        $balanceSeries[$metric] = dcf_build_series($row, $periodMap);
    }

    // Определение структуры таблицы: фактические годы и прогнозные периоды
    $factYears = ['2022', '2023', '2024', '2025'];   // Фактические годы для анализа (включая 2025 факт)
    $forecastLabels = ['P1', 'P2', 'P3', 'P4', 'P5']; // Прогнозные периоды (5 лет)
    
    // Формирование структуры колонок для отображения
    $columns = [];
    foreach ($factYears as $label) {
        $columns[] = ['key' => $label, 'label' => $label, 'type' => 'fact'];
    }
    foreach ($forecastLabels as $label) {
        $columns[] = ['key' => $label, 'label' => $label, 'type' => 'forecast'];
    }
    $columns[] = ['key' => 'TV', 'label' => 'TV', 'type' => 'tv']; // Terminal Value

    // Проверка наличия выручки за последний фактический год
    $lastFactLabel = '2025';
    if (($revenueSeries[$lastFactLabel] ?? 0) <= 0) {
        return ['error' => 'Укажите выручку минимум за три последних года (включая 2024).'];
    }

    // Инициализация массива для хранения фактических данных
    $factData = [
        'revenue' => [],      // Выручка
        'cogs' => [],         // Себестоимость продаж (Cost of Goods Sold)
        'commercial' => [],   // Коммерческие расходы
        'admin' => [],        // Административные расходы
        'commercial_admin' => [], // Коммерческие и административные расходы (объединенные)
        'depr' => [],         // Амортизация
        'operating_profit' => [], // Прибыль от продаж (= EBIT = Выручка - Себестоимость - Коммерческие - Административные - Амортизация)
    ];

    // Расчет фактических показателей за каждый год
    foreach ($factYears as $year) {
        $factData['revenue'][$year]    = $revenueSeries[$year] ?? 0;
        $factData['cogs'][$year]       = $cogsSeries[$year] ?? 0;
        $factData['commercial'][$year] = $commercialSeries[$year] ?? 0;
        $factData['admin'][$year]      = $adminSeries[$year] ?? 0;
        $factData['depr'][$year]       = $deprSeries[$year] ?? 0;
        
        // Коммерческие и административные расходы (объединенные)
        $factData['commercial_admin'][$year] = $factData['commercial'][$year] + $factData['admin'][$year];
        
        // Прибыль от продаж = Выручка - Себестоимость - Коммерческие расходы - Административные расходы - Амортизация
        $factData['operating_profit'][$year] = $factData['revenue'][$year] 
            - $factData['cogs'][$year] 
            - $factData['commercial'][$year] 
            - $factData['admin'][$year]
            - $factData['depr'][$year];
    }

    // ОЧИСТКА НДС: Применяется к факту перед расчётами рентабельности и долей расходов
    // Очищаем выручку и все расходы от НДС
    $vatRate = $defaults['vat_rate'];
    $factData['revenue'] = $removeVATFromArray($factData['revenue'], $vatRate);
    $factData['cogs'] = $removeVATFromArray($factData['cogs'], $vatRate);
    $factData['commercial'] = $removeVATFromArray($factData['commercial'], $vatRate);
    $factData['admin'] = $removeVATFromArray($factData['admin'], $vatRate);
    // Пересчитываем объединенные расходы после очистки
    foreach ($factYears as $year) {
        $factData['commercial_admin'][$year] = $factData['commercial'][$year] + $factData['admin'][$year];
        // Пересчитываем прибыль от продаж после очистки НДС
        $factData['operating_profit'][$year] = $factData['revenue'][$year] 
            - $factData['cogs'][$year] 
            - $factData['commercial'][$year] 
            - $factData['admin'][$year]
            - $factData['depr'][$year];
    }
    
    // Очищаем балансовые показатели от НДС (AR/AP для расчета NWC)
    if (isset($balanceSeries['Дебиторская задолженность'])) {
        $balanceSeries['Дебиторская задолженность'] = $removeVATFromArray($balanceSeries['Дебиторская задолженность'], $vatRate);
    }
    if (isset($balanceSeries['Кредиторская задолженность'])) {
        $balanceSeries['Кредиторская задолженность'] = $removeVATFromArray($balanceSeries['Кредиторская задолженность'], $vatRate);
    }

    // Расчет фактических темпов роста выручки (год к году)
    $factGrowth = [];
    $prevRevenue = null;
    foreach ($factYears as $year) {
        $current = $factData['revenue'][$year];
        if ($prevRevenue !== null && abs($prevRevenue) > 1e-6) {
            // Темп роста = (Текущая выручка - Предыдущая выручка) / Предыдущая выручка
            $factGrowth[$year] = ($current - $prevRevenue) / $prevRevenue;
        } else {
            $factGrowth[$year] = null; // Для первого года рост не рассчитывается
        }
        $prevRevenue = $current;
    }
    
    // Расчет среднего темпа роста и темпа роста за последний год
    $growthValues = array_values(array_filter($factGrowth, fn($value) => $value !== null));
    $gAvg = !empty($growthValues) ? array_sum($growthValues) / count($growthValues) : 0.05; // Средний темп роста
    $gLastFact = $factGrowth[$lastFactLabel] ?? 0.05; // Темп роста за последний фактический год (2024)

    // Генерация seed для детерминированных случайных значений
    $seedKey = ($form['asset_name'] ?? '') . '|' . ($form['id'] ?? '0');
    
    // Определение "якорных" точек темпа роста для прогнозных периодов
    // Логика основана на среднем историческом темпе роста:
    // - При отрицательном росте: постепенное восстановление к 4%
    // - При низком росте: умеренное ускорение
    // - При высоком росте: постепенное замедление к долгосрочному уровню 4%
    $growthAnchors = [];
    if ($gAvg <= -0.20) {
        // Сильно отрицательный рост: восстановление через P3, P4
        $growthAnchors[2] = 0.022;
        $growthAnchors[3] = 0.0315;
        $growthAnchors[4] = 0.04;
    } elseif ($gAvg <= 0) {
        // Отрицательный или нулевой рост: восстановление через P4
        $growthAnchors[3] = 0.0525;
        $growthAnchors[4] = 0.04;
    } elseif ($gAvg <= 0.1275) {
        // Умеренный рост: поддержание высокого темпа в P1, затем замедление
        $growthAnchors[0] = 0.1275;
        $growthAnchors[3] = 0.066;
        $growthAnchors[4] = 0.04;
    } else {
        // Высокий рост: постепенное замедление
        $growthAnchors[3] = 0.066;
        $growthAnchors[4] = 0.04;
    }
    // Гарантируем, что P5 всегда имеет долгосрочный темп роста 4%
    if (!isset($growthAnchors[4])) {
        $growthAnchors[4] = 0.04;
    }

    // Определение темпа роста для P1
    // P1 должен быть выше темпа роста за последний год, но не более чем на 10 п.п.
    $p1Candidate = $growthAnchors[0] ?? clampFloat($gAvg, -0.20, 0.35);
    $p1Candidate = max($p1Candidate, $gLastFact + 0.0001); // Минимум: последний факт + 0.01%
    $p1Candidate = min($p1Candidate, $gLastFact + 0.10);   // Максимум: последний факт + 10%
    if (abs($p1Candidate - $gLastFact) < 0.0001) {
        // Если кандидат слишком близок к последнему факту, добавляем минимальный шаг
        $p1Candidate = $gLastFact + 0.005;
    }
    $growthAnchors[0] = $p1Candidate;

    // Интерполяция темпов роста для всех прогнозных периодов
    // Используется линейная интерполяция между якорными точками
    $forecastGrowth = array_fill(0, 5, null);
    for ($i = 0; $i < 5; $i++) {
        // Если для периода уже есть якорная точка, используем её
        if (isset($growthAnchors[$i])) {
            $forecastGrowth[$i] = $growthAnchors[$i];
            continue;
        }
        
        // Ищем ближайшую якорную точку слева (предыдущую)
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (isset($growthAnchors[$j])) {
                $prev = [$j, $growthAnchors[$j]];
                break;
            }
        }
        
        // Ищем ближайшую якорную точку справа (следующую)
        $next = null;
        for ($j = $i + 1; $j < 5; $j++) {
            if (isset($growthAnchors[$j])) {
                $next = [$j, $growthAnchors[$j]];
                break;
            }
        }
        
        // Линейная интерполяция между предыдущей и следующей точками
        if ($prev && $next && $next[0] !== $prev[0]) {
            $ratio = ($i - $prev[0]) / ($next[0] - $prev[0]);
            $forecastGrowth[$i] = $prev[1] + ($next[1] - $prev[1]) * $ratio;
        } elseif ($prev) {
            // Если есть только предыдущая точка, используем её значение
            $forecastGrowth[$i] = $prev[1];
        } elseif ($next) {
            // Если есть только следующая точка, используем её значение
            $forecastGrowth[$i] = $next[1];
        } else {
            // Если нет якорных точек, используем кандидата для P1
            $forecastGrowth[$i] = $p1Candidate;
        }
    }

    // Добавление небольших случайных вариаций для реалистичности модели
    foreach ($forecastGrowth as $idx => $value) {
        $forecastGrowth[$idx] = clampFloat(
            $value + stableRandFloat($seedKey, $idx, -0.002, 0.002), // ±0.2% вариация
            -0.30,  // Минимальный темп роста: -30%
            0.40    // Максимальный темп роста: +40%
        );
    }
    
    // Гарантируем монотонное убывание темпов роста (реалистичный сценарий)
    for ($i = 1; $i < count($forecastGrowth); $i++) {
        if ($forecastGrowth[$i] > $forecastGrowth[$i - 1]) {
            // Если текущий период имеет больший рост, чем предыдущий, снижаем его
            $forecastGrowth[$i] = $forecastGrowth[$i - 1] - 0.003;
        }
    }
    
    // Финальная проверка ограничений для P1
    $forecastGrowth[0] = max($forecastGrowth[0], $gLastFact + 0.0001);
    $forecastGrowth[0] = min($forecastGrowth[0], $gLastFact + 0.10);
    
    // Принудительно устанавливаем темп роста P5 = 4% (последний год перед TV)
    // Это гарантирует плавный переход к бессрочному росту в Terminal Value
    $forecastGrowth[4] = 0.04;

    // Обработка бюджета для P1: используем budget_2026 (следующий год после последнего факта)
    // Это позволяет учитывать планы компании на ближайший прогнозный год
    $budgetRevenue = $revenueSeries['2026'] ?? null; // Бюджет на 2026 год (P1)
    $lastFactRevenue = $factData['revenue'][$lastFactLabel] ?? 0;
    $hasBudgetOverride = $budgetRevenue !== null && $budgetRevenue > 0;
    
    // ОЧИСТКА НДС: Очищаем бюджет от НДС перед использованием
    if ($hasBudgetOverride) {
        $budgetRevenue = $removeVAT($budgetRevenue, $vatRate);
    }
    
    // Если есть бюджет 2026, пересчитываем темп роста P1 на основе бюджета (уже без НДС)
    // Темп роста рассчитывается от последнего факта (2025) к бюджету 2026
    if ($hasBudgetOverride && $lastFactRevenue > 0) {
        $forecastGrowth[0] = ($budgetRevenue - $lastFactRevenue) / $lastFactRevenue;
    }

    // Расчет прогнозной выручки для каждого периода
    $forecastRevenue = [];
    $prevRevenue = $lastFactRevenue; // Начинаем с последнего фактического года (2025)
    foreach ($forecastLabels as $index => $label) {
        if ($index === 0 && $hasBudgetOverride) {
            // Для P1: если есть бюджет 2026, используем его напрямую (уже без НДС)
            $prevRevenue = $budgetRevenue;
        } else {
            // Для остальных периодов: применяем темп роста к предыдущему периоду
            $prevRevenue = $prevRevenue * (1 + ($forecastGrowth[$index] ?? 0));
        }
        $forecastRevenue[$label] = max(0, $prevRevenue); // Выручка не может быть отрицательной
    }
    
    // ОЧИСТКА НДС: Применяется к прогнозу перед построением модели
    // Прогнозная выручка уже без НДС (рассчитана от очищенной факт-выручки)
    // Но на всякий случай убеждаемся, что все значения без НДС

    /**
     * Вычисляет долю показателя относительно базы (например, себестоимость к выручке)
     * Использует среднее значение за фактические годы, если значения стабильны
     * Если есть выбросы (отклонение >10%), использует последнее значение
     * 
     * @param array $values Значения показателя по годам
     * @param array $bases Базовые значения (например, выручка) по годам
     * @param array $years Список годов для анализа
     * @param float $fallback Значение по умолчанию, если данных нет
     * @return float Доля показателя (0.0 - 1.0)
     */
    $computeShare = function (array $values, array $bases, array $years, float $fallback) {
        $ratios = [];
        $lastRatio = null;
        foreach ($years as $year) {
            $base = $bases[$year] ?? 0;
            if ($base > 0) {
                $ratio = ($values[$year] ?? 0) / $base;
                $ratios[] = $ratio;
                $lastRatio = $ratio;
            }
        }
        if (empty($ratios)) {
            return $fallback;
        }
        $avg = array_sum($ratios) / count($ratios);
        // Проверка на выбросы: если есть отклонение >10% от среднего, используем последнее значение
        foreach ($ratios as $ratio) {
            if (abs($ratio - $avg) >= 0.10) {
                return $lastRatio ?? $avg;
            }
        }
        return $avg;
    };

    // Расчет долей себестоимости и коммерческих расходов от выручки
    $cogsShare = $computeShare($factData['cogs'], $factData['revenue'], $factYears, 0.6);
    $commercialShare = $computeShare($factData['commercial'], $factData['revenue'], $factYears, 0.12);

    // Расчет прогнозной себестоимости и коммерческих расходов
    // Используем исторические доли от выручки
    $forecastCogs = [];
    $forecastCommercial = [];
    foreach ($forecastLabels as $label) {
        $forecastCogs[$label] = $forecastRevenue[$label] * $cogsShare;
        $forecastCommercial[$label] = $forecastRevenue[$label] * $commercialShare;
    }

    // Проверка наличия административных расходов в исторических данных
    $adminExists = ($factData['admin']['2022'] ?? 0) > 0 || ($factData['admin']['2023'] ?? 0) > 0 || ($factData['admin']['2024'] ?? 0) > 0;
    $adminForecast = [];
    $commercialAdminForecast = []; // Объединенные коммерческие и административные расходы
    
    // Прогноз инфляции для административных расходов (если они есть)
    $inflationPath = [0.091, 0.055, 0.045, 0.04, 0.04]; // Снижающаяся инфляция

    if ($adminExists) {
        // Если административные расходы есть в истории, прогнозируем их с учетом инфляции
        $prevAdmin = $factData['admin'][$lastFactLabel] ?? 0;
        foreach ($forecastLabels as $idx => $label) {
            $prevAdmin *= (1 + $inflationPath[$idx]); // Индексация на инфляцию
            $adminForecast[$label] = $prevAdmin;
            // Объединенные коммерческие и административные расходы
            $commercialAdminForecast[$label] = $forecastCommercial[$label] + $adminForecast[$label];
        }
    } else {
        // Если административных расходов нет, используем только коммерческие расходы
        foreach ($forecastLabels as $label) {
            $adminForecast[$label] = 0;
            $commercialAdminForecast[$label] = $forecastCommercial[$label];
        }
    }

    // Получение стоимости основных средств на конец последнего фактического года
    $osLastFact = $balanceSeries['Основные средства'][$lastFactLabel] ?? null;
    if ($osLastFact === null || $osLastFact <= 0) {
        return ['error' => 'Не заполнены данные по основным средствам (баланс).'];
    }

    // Прогноз амортизации и поддерживающего CAPEX
    // Логика: амортизация = 10% от стоимости ОС, поддерживающий CAPEX = 50% от амортизации
    // Это обеспечивает поддержание основных средств на текущем уровне
    $deprForecast = [];
    $supportCapex = [];
    $osTrend = [];
    $prevOS = $osLastFact;
    foreach ($forecastLabels as $label) {
        $dep = 0.10 * $prevOS;        // Амортизация: 10% от стоимости ОС
        $capex = 0.5 * $dep;          // Поддерживающий CAPEX: 50% от амортизации
        $currentOS = $prevOS + $capex; // Новая стоимость ОС = старая + CAPEX
        $deprForecast[$label] = $dep;
        $supportCapex[$label] = $capex;
        $osTrend[$label] = $currentOS;
        $prevOS = $currentOS;
    }

    /**
     * Распределение амортизации 50/50 между себестоимостью и коммерческими/административными расходами
     * Амортизация включается в расходы для расчета операционной прибыли
     * Также распределяется между отдельными компонентами для корректного расчета NWC
     */
    foreach ($forecastLabels as $label) {
        $deprHalf = $deprForecast[$label] * 0.5; // 50% амортизации
        $forecastCogs[$label] += $deprHalf; // Добавляем 50% амортизации к себестоимости
        
        // Распределяем 50% амортизации между коммерческими и административными расходами
        // Если есть административные расходы, распределяем пропорционально их долям
        if ($adminExists && $commercialAdminForecast[$label] > 0) {
            $commercialRatio = $forecastCommercial[$label] / ($forecastCommercial[$label] + $adminForecast[$label]);
            $adminRatio = 1 - $commercialRatio;
            $forecastCommercial[$label] += $deprHalf * $commercialRatio;
            $adminForecast[$label] += $deprHalf * $adminRatio;
        } else {
            // Если административных расходов нет, вся амортизация идет в коммерческие расходы
            $forecastCommercial[$label] += $deprHalf;
        }
        // Обновляем объединенные коммерческие и административные расходы
        $commercialAdminForecast[$label] = $forecastCommercial[$label] + $adminForecast[$label];
    }

    // Расчет "Прибыли от продаж" (которая математически соответствует EBIT) и налога на прибыль
    $operatingProfitForecast = [];
    $taxForecast = [];
    foreach ($forecastLabels as $label) {
        // Прибыль от продаж = Выручка - Себестоимость (с учетом амортизации) - Коммерческие и административные расходы (с учетом амортизации)
        // Амортизация уже включена в расходы, поэтому отдельно не вычитается
        $operatingProfitForecast[$label] = $forecastRevenue[$label] 
            - $forecastCogs[$label] 
            - $commercialAdminForecast[$label];
        // Налог на прибыль = Прибыль от продаж * Ставка налога (только если Прибыль от продаж > 0)
        $taxForecast[$label] = max(0, $operatingProfitForecast[$label]) * $defaults['tax_rate'];
    }

    // Расчет коэффициентов оборотного капитала (NWC - Net Working Capital)
    // Используем последние 2 года для более точной оценки
    $tailYears = array_slice($factYears, -2);
    $factCostBase = [];
    foreach ($factYears as $year) {
        // База для расчета кредиторской задолженности: все операционные расходы
        $factCostBase[$year] = ($factData['cogs'][$year] ?? 0) + ($factData['commercial'][$year] ?? 0) + ($factData['admin'][$year] ?? 0);
    }
    
    // Коэффициенты оборотного капитала (доли от соответствующих баз):
    $avgArRatio = $computeShare($balanceSeries['Дебиторская задолженность'] ?? [], $factData['revenue'], $tailYears, 0.15); // ДЗ к выручке
    $avgInvRatio = $computeShare($balanceSeries['Запасы'] ?? [], $factData['cogs'], $tailYears, 0.12); // Запасы к себестоимости
    $avgApRatio = $computeShare($balanceSeries['Кредиторская задолженность'] ?? [], $factCostBase, $tailYears, 0.09); // КЗ к операционным расходам

    // Расчет фактического оборотного капитала (NWC) за исторические годы
    // NWC = Дебиторская задолженность + Запасы - Кредиторская задолженность
    $factNwc = [];
    foreach ($factYears as $year) {
        $ar = $balanceSeries['Дебиторская задолженность'][$year] ?? 0;
        $inv = $balanceSeries['Запасы'][$year] ?? 0;
        $ap = $balanceSeries['Кредиторская задолженность'][$year] ?? 0;
        $factNwc[$year] = $ar + $inv - $ap;
    }
    $nwcLastFact = $factNwc[$lastFactLabel] ?? 0;

    // Прогноз оборотного капитала и его изменений
    $nwcForecast = [];
    $deltaNwcForecast = [];
    foreach ($forecastLabels as $index => $label) {
        // Прогноз компонентов NWC на основе коэффициентов
        $ar = $forecastRevenue[$label] * $avgArRatio; // ДЗ = Выручка * Коэффициент ДЗ
        $inv = $forecastCogs[$label] * $avgInvRatio;  // Запасы = Себестоимость * Коэффициент запасов
        $apBase = $forecastCogs[$label] + $forecastCommercial[$label] + $adminForecast[$label];
        $ap = $apBase * $avgApRatio; // КЗ = Операционные расходы * Коэффициент КЗ
        
        $nwcForecast[$label] = $ar + $inv - $ap;
        
        // Изменение NWC: для P1 - относительно последнего факта, для остальных - относительно предыдущего периода
        if ($index === 0) {
            $deltaNwcForecast[$label] = $nwcForecast[$label] - $nwcLastFact;
        } else {
            $prevLabel = $forecastLabels[$index - 1];
            $deltaNwcForecast[$label] = $nwcForecast[$label] - $nwcForecast[$prevLabel];
        }
    }

    // Расчет FCFF (Free Cash Flow to Firm) - свободного денежного потока компании
    // FCFF = Прибыль от продаж - Налог на прибыль + Амортизация - Поддерживающий CAPEX - Изменение NWC
    $fcffForecast = [];
    foreach ($forecastLabels as $label) {
        $fcffForecast[$label] = $operatingProfitForecast[$label]
            - $taxForecast[$label]
            + $deprForecast[$label]  // Амортизация добавляется обратно
            - $supportCapex[$label]
            - $deltaNwcForecast[$label];
    }

    // Расчет временных коэффициентов для дисконтирования
    // Учитываем, что P1 может начаться не с начала года
    $currentDate = new DateTime();
    $currentMonth = (int)$currentDate->format('n');
    $currentDay = (int)$currentDate->format('j');
    $elapsedFraction = clampFloat((($currentMonth - 1) + ($currentDay / 30)) / 12, 0, 0.99); // Доля прошедшего года
    $remainingFraction = 1 - $elapsedFraction; // Доля оставшегося года
    $stubFraction = clampFloat((12 - $currentMonth) / 12, 0, 1); // Доля года до конца (для дисконтирования)

    // Корректировка FCFF для P1: учитываем только оставшуюся часть года
    $fcffDisplay = $fcffForecast;
    $fcffDisplay[$forecastLabels[0]] = $fcffForecast[$forecastLabels[0]] * $remainingFraction;

    $discountFactors = [];
    $discountedCf = [];
    $pvSum = 0;
    foreach ($forecastLabels as $index => $label) {
        // Period t for discounting: P1 is at stubFraction years, P2 at 1+stubFraction, etc.
        // For P1 (index=0): t = stubFraction (remaining part of current year)
        // For P2 (index=1): t = 1 + stubFraction (end of next year)
        // For P3 (index=2): t = 2 + stubFraction (end of year after next), etc.
        $t = $index + $stubFraction;
        $df = 1 / pow(1 + $defaults['wacc'], $t);
        $discountFactors[$label] = $df;
        $discountedCf[$label] = $fcffDisplay[$label] * $df;
        $pvSum += $discountedCf[$label];
    }

    // Terminal Value calculation using Gordon Growth Model
    // TV = FCF(n) * (1 + g) / (WACC - g)
    // where FCF(n) is the FCF of the last forecast year, g is perpetual growth rate
    $terminalFcff = end($fcffForecast);
    
    // Ensure WACC > perpetual_growth for the formula to work
    $wacc = $defaults['wacc'];
    $perpetualGrowth = $defaults['perpetual_growth'];
    if ($wacc <= $perpetualGrowth) {
        // If WACC <= growth, set growth to WACC - 0.01 to avoid division by zero
        $perpetualGrowth = max(0, $wacc - 0.01);
    }
    
    // Calculate Terminal Value at the end of the last forecast year
    $terminalValue = $terminalFcff * (1 + $perpetualGrowth) / ($wacc - $perpetualGrowth);
    
    // Discount TV to present value
    // TV is at the end of the last forecast period (same moment as last P period)
    // If we have 5 periods (P1-P5), P5 is discounted at (4 + stubFraction)
    // TV should be discounted at the same moment: (count - 1 + stubFraction)
    $terminalPeriod = (count($forecastLabels) - 1) + $stubFraction;
    $terminalDf = 1 / pow(1 + $wacc, $terminalPeriod);
    $terminalPv = $terminalValue * $terminalDf;
    $discountFactors['TV'] = $terminalDf;
    $discountedCf['TV'] = $terminalPv;

    // Расчет итоговых показателей стоимости
    $debt = $balanceSeries['Кредиты и займы'][$lastFactLabel] ?? 0; // Долг на конец последнего факта
    $cash = $balanceSeries['Денежные средства'][$lastFactLabel] ?? 0; // Денежные средства на конец последнего факта
    
    // Enterprise Value (EV) = Текущая стоимость FCFF + Текущая стоимость Terminal Value
    $enterpriseValue = $pvSum + $terminalPv;
    
    // Equity Value = Enterprise Value - Долг + Денежные средства
    $equityValue = $enterpriseValue - $debt + $cash;

    $buildValues = function (array $factValues, array $forecastValues, $tvValue = null) use ($factYears, $forecastLabels) {
        $values = [];
        foreach ($factYears as $year) {
            $values[$year] = $factValues[$year] ?? null;
        }
        foreach ($forecastLabels as $label) {
            $values[$label] = $forecastValues[$label] ?? null;
        }
        $values['TV'] = $tvValue;
        return $values;
    };

    $nullFact = array_fill_keys($factYears, null);
    $nullForecast = array_fill_keys($forecastLabels, null);

    $forecastGrowthAssoc = array_combine($forecastLabels, $forecastGrowth);
    $factTax = [];
    foreach ($factYears as $year) {
        $factTax[$year] = max(0, $factData['operating_profit'][$year] ?? 0) * $defaults['tax_rate'];
    }

    // Функция для проверки наличия данных в фактических периодах
    $hasFactData = function (array $factValues) use ($factYears): bool {
        foreach ($factYears as $year) {
            $value = $factValues[$year] ?? null;
            if ($value !== null && abs($value) > 1e-6) {
                return true;
            }
        }
        return false;
    };

    // Построение строк таблицы
    $rows = [];
    
    // Выручка - всегда показываем
    $rows[] = [
        'label' => 'Выручка',
        'format' => 'money',
        'is_expense' => false,
        'values' => $buildValues($factData['revenue'], $forecastRevenue),
    ];
    
    // Темп роста - всегда показываем
    $rows[] = [
        'label' => 'Темп роста, %',
        'format' => 'percent',
        'italic' => true,
        'values' => $buildValues(
            $factGrowth + [$factYears[0] => null],
            $forecastGrowthAssoc
        ),
    ];
    
    // Себестоимость - показываем только если есть данные
    if ($hasFactData($factData['cogs'])) {
        $rows[] = [
            'label' => 'Себестоимость*',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['cogs'], $forecastCogs),
        ];
    }
    
    // Коммерческие и административные расходы (объединенные) - показываем только если есть данные
    if ($hasFactData($factData['commercial_admin'])) {
        $rows[] = [
            'label' => 'Коммерческие и административные расходы*',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['commercial_admin'], $commercialAdminForecast),
        ];
    }
    
    // Прибыль от продаж - всегда показываем (рассчитывается)
    $rows[] = [
        'label' => 'Прибыль от продаж',
        'format' => 'money',
        'is_expense' => false,
        'values' => $buildValues($factData['operating_profit'], $operatingProfitForecast),
    ];
    
    // Налог на прибыль - всегда показываем
    $rows[] = [
        'label' => 'Налог на прибыль',
        'format' => 'money',
        'is_expense' => true,
        'values' => $buildValues($factTax, $taxForecast),
    ];
    
    // Амортизация - показываем только если есть данные в факте или всегда в прогнозе
    $factDeprForDisplay = [];
    foreach ($factYears as $year) {
        $factDeprForDisplay[$year] = $hasFactData($factData['depr']) ? ($factData['depr'][$year] ?? null) : null;
    }
    $rows[] = [
        'label' => 'Амортизация',
        'format' => 'money',
        'is_expense' => false,
        'values' => $buildValues($factDeprForDisplay, $deprForecast),
    ];
    
    // Поддерживающий CAPEX - всегда показываем (только в прогнозе)
    $rows[] = [
        'label' => 'Поддерживающий CAPEX',
        'format' => 'money',
        'is_expense' => true,
        'values' => $buildValues($nullFact, $supportCapex),
    ];
    
    // ΔNWC - всегда показываем (только в прогнозе)
    $rows[] = [
        'label' => 'ΔNWC',
        'format' => 'money',
        'is_expense' => true,
        'values' => $buildValues($nullFact, $deltaNwcForecast),
    ];
    
    // FCFF - всегда показываем
    $rows[] = [
        'label' => 'FCFF',
        'format' => 'money',
        'is_expense' => false,
        'star_columns' => [$forecastLabels[0]],
        'values' => $buildValues($nullFact, $fcffDisplay, $terminalValue), // TV включен в строку FCFF
    ];
    
    // Фактор дисконтирования - всегда показываем
    $rows[] = [
        'label' => 'Фактор дисконтирования',
        'format' => 'decimal',
        'values' => $buildValues($nullFact, $discountFactors, $discountFactors['TV']),
    ];
    
    // Discounted FCFF - всегда показываем
    $rows[] = [
        'label' => 'Discounted FCFF',
        'format' => 'money',
        'is_expense' => false,
        'values' => $buildValues($nullFact, $discountedCf, $terminalPv),
    ];

    $warnings = [];
    // Убрано нерелевантное замечание о корректировке P1 относительно темпа 2024 года
    // P1 теперь соответствует 2026П, сравнение с темпом роста 2024 года не актуально
    if (abs($forecastGrowth[0] - $gLastFact) > 0.10) {
        $warnings[] = 'Отклонение P1 от фактического темпа роста ограничено 10 п.п. согласно регламенту.';
    }

    return [
        'columns' => $columns,
        'rows' => $rows,
        'wacc' => $defaults['wacc'],
        'perpetual_growth' => $defaults['perpetual_growth'],
        'footnotes' => [
            '*с учетом амортизации'
        ],
        'warnings' => $warnings,
        'ev_breakdown' => [
            'ev' => $enterpriseValue,
            'debt' => $debt,
            'cash' => $cash,
            'equity' => $equityValue,
            'terminal_value' => $terminalValue,
            'terminal_pv' => $terminalPv,
            'discounted_sum' => $pvSum,
        ],
    ];
}

$latestForm = null;
$dcfData = null;
$dcfSourceStatus = null;
$savedTeaserHtml = null;
$savedTeaserTimestamp = null;
$savedInvestorHtml = null;
$savedInvestorTimestamp = null;
$isStartup = false; // Инициализация для предотвращения ошибок

// Загружаем полные данные выбранной анкеты для работы с инструментами
$latestForm = null;
if ($selectedForm) {
    // Если $selectedForm уже загружен с полными данными (например, для модератора),
    // используем его напрямую, иначе загружаем из БД
    if (isset($selectedForm['data_json']) || isset($selectedForm['financial_results'])) {
        // Уже загружены полные данные
        $latestForm = $selectedForm;
    } else {
        // Нужно загрузить полные данные
        if (isModerator() && !isImpersonating()) {
            // Модераторы (не в режиме impersonation) могут загружать любые анкеты
            $latestFormStmt = $pdo->prepare("
    SELECT *
    FROM seller_forms
                WHERE id = ?
            ");
            $latestFormStmt->execute([$selectedForm['id']]);
        } else {
            // Обычные пользователи или модераторы в режиме impersonation - только свои анкеты
            $effectiveUserId = getEffectiveUserId();
            $latestFormStmt = $pdo->prepare("
                SELECT *
                FROM seller_forms
                WHERE id = ? AND user_id = ?
            ");
            $latestFormStmt->execute([$selectedForm['id'], $effectiveUserId]);
        }
        $latestForm = $latestFormStmt->fetch();
    }
}

/**
 * Проверяет, заполнены ли все обязательные поля анкеты для генерации тизера
 * 
 * @param array $form Данные анкеты из БД
 * @return array ['valid' => bool, 'missing_fields' => array] Результат проверки
 */
function validateFormForTeaser(array $form): array
{
    $requiredFields = [
        'company_inn',
        'asset_name',
        'deal_share_range',
        'deal_goal',
        'asset_disclosure',
        'company_description',
        'presence_regions',
        'products_services',
        'main_clients',
        'sales_share',
        'personnel_count',
        'financial_results_vat',
        'financial_source',
    ];
    
    $missingFields = [];
    
    // Извлекаем данные из data_json или из отдельных полей
    $formData = [];
    if (!empty($form['data_json'])) {
        $decoded = json_decode($form['data_json'], true);
        if (is_array($decoded)) {
            $formData = $decoded;
        }
    }
    
    // Проверяем каждое обязательное поле
    foreach ($requiredFields as $field) {
        $value = $formData[$field] ?? $form[$field] ?? null;
        
        // Проверяем, что значение не пустое
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            $missingFields[] = $field;
        }
    }
    
    // Проверяем наличие финансовых данных (хотя бы за один период)
    $hasFinancialData = false;
    if (!empty($form['financial_results'])) {
        $financialData = json_decode($form['financial_results'], true);
        if (is_array($financialData) && !empty($financialData)) {
            // Проверяем, есть ли хотя бы один период с данными
            foreach ($financialData as $key => $value) {
                if (is_array($value) && !empty($value)) {
                    // Проверяем наличие хотя бы одного непустого значения
                    foreach ($value as $v) {
                        if ($v !== null && $v !== '' && $v !== 0) {
                            $hasFinancialData = true;
                            break 2;
                        }
                    }
                } elseif (!is_array($value) && $value !== null && $value !== '' && $value !== 0) {
                    $hasFinancialData = true;
                    break;
                }
            }
        }
    }
    
    if (!$hasFinancialData) {
        $missingFields[] = 'financial_results';
    }
    
    return [
        'valid' => empty($missingFields),
        'missing_fields' => $missingFields
    ];
}

if ($latestForm) {
    $dcfSourceStatus = $latestForm['status'];
    $dcfData = calculateUserDCF($latestForm);
    
    // Определяем тип компании для условного отображения блоков
    $companyType = $latestForm['company_type'] ?? null;
    $isStartup = ($companyType === 'startup');
    
    // Проверяем заполненность полей для генерации тизера
    $teaserValidation = validateFormForTeaser($latestForm);
    
    // Получаем информацию о модерации тизера
    $teaserModerationInfo = null;
    try {
        ensurePublishedTeasersTable();
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT 
                moderation_status,
                created_at,
                moderated_at,
                published_at,
                moderation_notes
            FROM published_teasers
            WHERE seller_form_id = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$latestForm['id']]);
        $teaserModerationInfo = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching teaser moderation info: " . $e->getMessage());
    }

    $savedHeroDescription = null;
    if (!empty($latestForm['data_json'])) {
        $teaserDecoded = json_decode($latestForm['data_json'], true);
        if (is_array($teaserDecoded)) {
            if (!empty($teaserDecoded['teaser_snapshot']['html'])) {
                $savedTeaserHtml = $teaserDecoded['teaser_snapshot']['html'];
                $savedTeaserTimestamp = $teaserDecoded['teaser_snapshot']['generated_at'] ?? null;
            }
            if (!empty($teaserDecoded['investor_snapshot']['html'])) {
                $savedInvestorHtml = $teaserDecoded['investor_snapshot']['html'];
                $savedInvestorTimestamp = $teaserDecoded['investor_snapshot']['generated_at'] ?? null;
            }
            // Извлекаем сгенерированное ИИ описание для hero блока
            if (!empty($teaserDecoded['teaser_snapshot']['hero_description'])) {
                $savedHeroDescription = trim((string)$teaserDecoded['teaser_snapshot']['hero_description']);
            }
        }
    }
    
    // Генерируем hero_description сразу после расчета DCF, если его еще нет
    if (empty($savedHeroDescription) && !isset($dcfData['error']) && defined('TOGETHER_API_KEY') && !empty(TOGETHER_API_KEY)) {
        // Подключаем функции из generate_teaser.php для генерации описания
        // Используем output buffering, чтобы избежать вывода JSON из generate_teaser.php
        if (!function_exists('generateHeroDescription')) {
            ob_start();
            try {
                // Определяем флаг, чтобы generate_teaser.php не выполнял основной код
                define('TEASER_FUNCTIONS_ONLY', true);
                $generateTeaserPath = __DIR__ . '/generate_teaser.php';
                if (file_exists($generateTeaserPath)) {
                    // Временно перехватываем header, чтобы избежать отправки JSON
                    $originalHeaders = headers_list();
                    include $generateTeaserPath;
                }
            } catch (Throwable $e) {
                error_log('Failed to load generate_teaser functions: ' . $e->getMessage());
            }
            ob_end_clean();
        }
        
        // Генерируем описание, если функция доступна
        if (function_exists('generateHeroDescription')) {
            try {
                $generatedDescription = generateHeroDescription($latestForm, TOGETHER_API_KEY);
                if (!empty($generatedDescription)) {
                    $savedHeroDescription = $generatedDescription;
                    // Обновляем данные формы, чтобы получить актуальный hero_description
                    $latestFormStmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ?");
                    $latestFormStmt->execute([$latestForm['id']]);
                    $latestForm = $latestFormStmt->fetch();
                    if ($latestForm && !empty($latestForm['data_json'])) {
                        $teaserDecoded = json_decode($latestForm['data_json'], true);
                        if (is_array($teaserDecoded) && !empty($teaserDecoded['teaser_snapshot']['hero_description'])) {
                            $savedHeroDescription = trim((string)$teaserDecoded['teaser_snapshot']['hero_description']);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('Hero description generation error in dashboard: ' . $e->getMessage());
            }
        }
    }
} else {
    // Если анкета не выбрана, инициализируем пустые данные
    $dcfData = ['error' => 'Выберите анкету для просмотра инструментов.'];
    $teaserValidation = ['valid' => false, 'missing_fields' => []];
}

// Если мы в режиме API (для generate_teaser.php), не выводим HTML
if (!defined('DCF_API_MODE') || !DCF_API_MODE) {
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - SmartBizSell.ru</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/dashboard.php">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Manrope:wght@400;500;600;700&family=Space+Grotesk:wght@500;600&display=swap" rel="stylesheet">
    <style>
        /* Стили для мобильного меню */
        @media (max-width: 768px) {
            .nav-toggle.active span:nth-child(1) {
                transform: rotate(45deg) translate(5px, 5px);
            }
            .nav-toggle.active span:nth-child(2) {
                opacity: 0;
            }
            .nav-toggle.active span:nth-child(3) {
                transform: rotate(-45deg) translate(7px, -6px);
            }
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            padding: 40px 0;
            color: white;
            margin-bottom: 40px;
            margin-top: 80px;
        }
        
        @media (max-width: 768px) {
            .dashboard-header {
                margin-top: 70px;
            }
        }
        .dashboard-header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .dashboard-header h1 {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .dashboard-header p {
            opacity: 0.9;
            font-size: 16px;
        }
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 40px;
            position: relative;
        }
        
        /* Табы для переключения между анкетами */
        .forms-tabs {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.15), 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 2px solid rgba(102, 126, 234, 0.2);
            position: relative;
            overflow: hidden;
        }
        .forms-tabs::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667EEA 0%, #764BA2 50%, #667EEA 100%);
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .forms-tabs__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
            position: relative;
            z-index: 1;
        }
        .forms-tabs__header h2 {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 28px !important;
            font-weight: 800 !important;
            letter-spacing: -0.5px;
            margin: 0 !important;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .forms-tabs__header h2::before {
            content: '📊';
            font-size: 32px;
            -webkit-text-fill-color: initial;
            background: none;
        }
        .forms-tabs__list {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            overflow-x: auto;
            padding-bottom: 4px;
            position: relative;
            z-index: 1;
        }
        .forms-tabs__tab {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 24px;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-radius: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .forms-tabs__tab:hover {
            border-color: #667EEA;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.08) 100%);
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.2);
        }
        .forms-tabs__tab.active {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            border-color: transparent;
            color: white;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4), 0 2px 8px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }
        .forms-tabs__tab-name {
            font-weight: 600;
        }
        .forms-tabs__tab-status {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .forms-tabs__tab.active .forms-tabs__tab-status {
            background: rgba(255, 255, 255, 0.3) !important;
        }
        
        .forms-tabs__tab-rejection-note {
            word-wrap: break-word;
            overflow-wrap: break-word;
            display: block;
        }
        
        @media (max-width: 768px) {
            .forms-tabs {
                padding: 24px 20px;
                border-radius: 16px;
            }
            .forms-tabs__header {
                flex-direction: column;
                align-items: flex-start;
            }
            .forms-tabs__header h2 {
                font-size: 24px !important;
            }
            .forms-tabs__header h2::before {
                font-size: 28px;
            }
            .forms-tabs__list {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .forms-tabs__tab {
                padding: 12px 20px;
                font-size: 14px;
            }
        }
        
        /* Навигация между блоками */
        .dashboard-nav {
            position: -webkit-sticky;
            position: sticky;
            top: 80px;
            z-index: 100;
            background: transparent;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 16px 24px;
            margin-bottom: 32px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(20px);
            background: rgba(255, 255, 255, 0.25);
            will-change: transform;
        }
        
        .dashboard-nav__list {
            display: flex;
            gap: 8px;
            list-style: none;
            margin: 0;
            padding: 0;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .dashboard-nav__item {
            margin: 0;
        }
        
        .dashboard-nav__link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s ease;
            border: 2px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(10px);
        }
        
        .dashboard-nav__link:hover {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .dashboard-nav__link.active {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-color: rgba(255, 255, 255, 0.3);
        }
        
        .dashboard-nav__icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .dashboard-nav__icon svg {
            width: 100%;
            height: 100%;
            fill: currentColor;
            transition: transform 0.3s ease;
        }
        
        .dashboard-nav__link:hover .dashboard-nav__icon svg {
            transform: scale(1.1);
        }
        
        .dashboard-nav__link.active .dashboard-nav__icon svg {
            transform: scale(1.05);
        }
        
        .dashboard-nav__text {
            display: inline;
        }
        
        @media (max-width: 768px) {
            .dashboard-nav {
                position: -webkit-sticky !important;
                position: sticky !important;
                top: 70px !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 1000 !important;
                padding: 12px 8px !important;
                margin-bottom: 24px !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                border-radius: 0 !important;
                border-left: none !important;
                border-right: none !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
                /* Убеждаемся, что навигация остается видимой */
                will-change: transform;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1) !important;
                background: rgba(255, 255, 255, 0.3) !important;
                backdrop-filter: blur(20px) !important;
                border: 1px solid transparent !important;
                /* Навигация видна сразу на мобильных */
                opacity: 1 !important;
                visibility: visible !important;
                transform: translateY(0) !important;
                transition: none !important;
            }
            
            /* Класс nav-visible больше не нужен, но оставляем для совместимости */
            .dashboard-nav.nav-visible {
                opacity: 1 !important;
                visibility: visible !important;
                transform: translateY(0) !important;
            }
            
            /* Убираем отрицательные отступы у родительского контейнера, если они есть */
            .dashboard-container {
                overflow-x: visible;
            }
            
            /* Навигация теперь sticky, поэтому не нужны большие отступы */
            /* Отступы для элементов после навигации минимальны, так как навигация sticky */
            .dashboard-nav ~ .dcf-card,
            .dashboard-nav ~ .teaser-section {
                margin-top: 0 !important;
                padding-top: 0 !important;
            }
            
            /* Убираем лишние отступы */
            .dashboard-actions + .dashboard-nav ~ *,
            .dashboard-stats + .dashboard-nav ~ * {
                margin-top: 0 !important;
            }
            
            /* Специальный отступ для первого блока после навигации */
            .dashboard-nav + .dcf-card,
            .dashboard-nav + .teaser-section {
                margin-top: 0 !important;
                padding-top: 0 !important;
            }
            
            /* Универсальный отступ для любого элемента после навигации */
            .dashboard-nav + * {
                margin-top: 0 !important;
            }
            
            /* Дополнительный отступ для элементов, которые могут быть перекрыты при прокрутке */
            .dashboard-container > .dashboard-nav ~ * {
                scroll-margin-top: 90px;
            }
            
            .dashboard-nav__list {
                gap: 8px;
                overflow-x: auto;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none; /* Firefox */
                -ms-overflow-style: none; /* IE and Edge */
                padding: 0 12px;
                scroll-snap-type: x proximity;
            }
            
            .dashboard-nav__list::-webkit-scrollbar {
                display: none; /* Chrome, Safari, Opera */
            }
            
            .dashboard-nav__item {
                flex-shrink: 0;
                scroll-snap-align: start;
            }
            
            .dashboard-nav__link {
                padding: 10px 18px;
                font-size: 13px;
                white-space: nowrap;
                min-width: fit-content;
                display: inline-flex;
                border-radius: 4px !important; /* Прямоугольная форма для мобильных */
            }
            
            .dashboard-nav__icon {
                font-size: 14px;
                flex-shrink: 0;
            }
            
            /* Показываем текст на мобильных устройствах (768px и меньше) */
            .dashboard-nav__text {
                display: inline;
                margin-left: 8px;
                font-size: 11px; /* Уменьшенный размер шрифта для мобильных */
            }
            
            /* Индикатор прокрутки для мобильных */
            .dashboard-nav__list::after {
                content: '';
                flex-shrink: 0;
                width: 20px;
                height: 1px;
            }
        }
        
        @media (max-width: 480px) {
            .dashboard-nav {
                position: fixed;
                top: 70px;
                left: 0;
                right: 0;
                z-index: 100;
                padding: 10px 4px;
                will-change: transform;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            }
            
            .dashboard-nav__list {
                gap: 6px;
                padding: 0 8px;
            }
            
            .dashboard-nav__link {
                padding: 8px 14px;
                font-size: 12px;
                border-radius: 4px !important; /* Прямоугольная форма для очень маленьких экранов */
            }
            
            .dashboard-nav__icon {
                width: 16px;
                height: 16px;
            }
            
            /* Показываем текст на мобильных устройствах */
            .dashboard-nav__text {
                display: inline;
                margin-left: 6px;
                font-size: 10px; /* Еще меньший размер для очень маленьких экранов */
            }
            
            .dashboard-nav__link {
                padding: 10px 12px;
                border-radius: 4px !important; /* Прямоугольная форма вместо круглой */
                aspect-ratio: auto; /* Убираем квадратную форму */
                justify-content: center;
            }
        }
        
        /* Стили для приветственного модального окна */
        .welcome-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
        }
        
        .welcome-modal-overlay.active {
            display: flex;
        }
        
        .welcome-modal {
            background: white;
            border-radius: 20px;
            max-width: 640px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: modalFadeIn 0.3s ease-out;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        .welcome-modal__header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            padding: 28px 32px;
            border-radius: 20px 20px 0 0;
        }
        
        .welcome-modal__logo {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        
        .welcome-modal__lead {
            margin: 0;
            font-size: 16px;
            line-height: 1.6;
            opacity: 0.95;
        }
        
        .welcome-modal__content {
            padding: 32px;
        }
        
        .welcome-modal__title {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 16px;
            color: #1d1d1f;
        }
        
        .welcome-modal__text {
            margin: 0 0 16px;
            font-size: 15px;
            line-height: 1.7;
            color: #3c3c43;
        }
        
        .welcome-modal__steps {
            list-style: none;
            padding: 0;
            margin: 0 0 24px;
        }
        
        .welcome-modal__steps li {
            background: #f7f8fd;
            border: 1px solid #e6e8f2;
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }
        
        .welcome-modal__steps strong {
            display: block;
            margin-bottom: 4px;
            font-weight: 700;
            color: #1d1d1f;
            font-size: 15px;
        }
        
        .welcome-modal__steps li {
            font-size: 14px;
            line-height: 1.6;
            color: #3c3c43;
        }
        
        .welcome-modal__benefits {
            background: #f0f4ff;
            border-left: 4px solid #667EEA;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
        }
        
        .welcome-modal__benefits strong {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            color: #1d1d1f;
        }
        
        .welcome-modal__button {
            width: 100%;
            padding: 16px 24px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 6px 18px rgba(102, 126, 234, 0.28);
        }
        
        .welcome-modal__button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        
        .welcome-modal__button:active {
            transform: translateY(0);
        }
        
        .welcome-modal__note {
            font-size: 13px;
            color: #6b7280;
            margin-top: 16px;
            text-align: center;
        }
        
        .welcome-modal__note a {
            color: #667EEA;
            text-decoration: none;
        }
        
        .welcome-modal__note a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 640px) {
            .welcome-modal {
                max-width: 100%;
                border-radius: 16px;
            }
            
            .welcome-modal__header,
            .welcome-modal__content {
                padding: 22px 20px;
            }
            
            .welcome-modal__title {
                font-size: 20px;
            }
        }
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .dashboard-actions {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: white;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }
        .btn-secondary:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .btn-investor {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 220px;
        }
        .forms-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 18px;
        }
        .table-row {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 16px;
            align-items: center;
            transition: background 0.2s ease;
        }
        .table-row:hover {
            background: var(--bg-secondary);
        }
        .table-row:last-child {
            border-bottom: none;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        .ev-label-short {
            display: none;
        }
        .ev-label-full {
            display: inline;
        }
        @media (max-width: 768px) {
            body, html {
                overflow-x: hidden;
                width: 100%;
                max-width: 100vw;
                box-sizing: border-box;
                position: relative;
            }
            * {
                box-sizing: border-box;
            }
            /* Разрешаем прокрутку только для обертки таблицы */
            .dcf-card {
                position: relative;
            }
            .dcf-table-wrapper {
                position: relative;
                left: 0;
                right: 0;
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
            }
            .dcf-card {
                overflow-x: visible !important;
            }
            .dashboard-header {
                padding: 24px 0;
                margin-top: 60px;
                margin-bottom: 24px;
            }
            .dashboard-header h1 {
                font-size: 24px;
            }
            .dashboard-header p {
                font-size: 14px;
            }
            .dashboard-container {
                padding: 0 16px 24px;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow-x: visible;
            }
            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-bottom: 24px;
            }
            .stat-card {
                padding: 16px;
            }
            .stat-value {
                font-size: 24px;
            }
            .stat-label {
                font-size: 12px;
            }
            .dashboard-actions {
                flex-direction: column;
                gap: 12px;
                margin-bottom: 24px;
            }
            .dashboard-actions .btn {
                width: 100%;
                text-align: center;
                touch-action: manipulation !important;
                -webkit-tap-highlight-color: rgba(102, 126, 234, 0.3) !important;
                min-height: 48px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            .table-row {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 16px;
            }
            .table-header {
                padding: 16px;
                font-size: 16px;
            }
            .forms-table {
                border-radius: 12px;
            }
            .dcf-card {
                padding: 16px;
                margin-top: 24px;
                border-radius: 12px;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow-x: hidden;
                overflow-y: visible;
            }
            .dcf-card h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }
            /* Кнопка PDF на мобильных - размещаем сверху */
            .dcf-card > div[style*="display:flex"] {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }
            .dcf-card .btn-export-pdf {
                width: 100% !important;
                padding: 14px 20px !important;
                font-size: 15px !important;
                order: -1 !important; /* Кнопка сверху */
                margin-bottom: 8px;
            }
            .dcf-card > div[style*="display:flex"] > div:first-child {
                width: 100%;
            }
            .dcf-print-hint {
                display: block;
                margin-top: 8px;
                font-size: 12px;
                opacity: 0.7;
            }
            .dcf-card__actions {
                flex-direction: column;
                gap: 8px;
            }
            .dcf-card__actions .btn {
                width: 100%;
            }
            .dcf-params-strip {
                flex-direction: column;
                gap: 8px;
                font-size: 12px;
            }
            /* Обертка для горизонтальной прокрутки таблиц DCF */
            .dcf-card {
                overflow-x: visible;
                overflow-y: visible;
                max-width: 100%;
                width: 100%;
                box-sizing: border-box;
            }
            .dcf-card > div {
                max-width: 100%;
                width: 100%;
                overflow-x: visible;
                overflow-y: visible;
                box-sizing: border-box;
            }
            .dcf-table-wrapper {
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
                margin: 0 -16px;
                padding: 0 16px 8px 16px;
                width: calc(100% + 32px);
                max-width: 100vw;
                position: relative;
                box-sizing: content-box;
                display: block;
            }
            /* Стилизация скроллбара для таблицы */
            .dcf-table-wrapper::-webkit-scrollbar {
                height: 8px;
            }
            .dcf-table-wrapper::-webkit-scrollbar-track {
                background: rgba(0,0,0,0.05);
                border-radius: 4px;
                margin: 0 4px;
            }
            .dcf-table-wrapper::-webkit-scrollbar-thumb {
                background: rgba(0,0,0,0.2);
                border-radius: 4px;
            }
            .dcf-table-wrapper::-webkit-scrollbar-thumb:hover {
                background: rgba(0,0,0,0.3);
            }
            /* Убеждаемся, что таблица может быть шире контейнера */
            .dcf-table-wrapper .dcf-table--full {
                display: table;
                width: auto;
                min-width: 500px;
            }
            .dcf-table {
                min-width: 500px;
                font-size: 11px;
                width: auto;
                display: table;
                table-layout: auto;
            }
            .dcf-table--full {
                width: auto;
                min-width: 500px;
                display: table;
                table-layout: auto;
            }
            .dcf-table th,
            .dcf-table td {
                padding: 6px 4px;
            }
            .dcf-table th:not(:first-child),
            .dcf-table td:not(:first-child) {
                white-space: nowrap;
                font-size: 10px;
            }
            .dcf-table--full th:first-child {
                width: 100px;
                min-width: 100px;
                max-width: 100px;
                position: sticky;
                left: 0;
                z-index: 10;
                background: rgba(245,247,250,0.98);
                box-shadow: 2px 0 4px rgba(0,0,0,0.05);
                white-space: normal;
                word-wrap: break-word;
                line-height: 1.2;
                font-size: 10px;
                padding: 6px 4px;
            }
            .dcf-table--full td:first-child {
                position: sticky;
                left: 0;
                z-index: 9;
                background: white;
                box-shadow: 2px 0 4px rgba(0,0,0,0.05);
                white-space: normal;
                word-wrap: break-word;
                line-height: 1.2;
                font-size: 10px;
                padding: 6px 4px;
                width: 100px;
                min-width: 100px;
                max-width: 100px;
            }
            .dcf-table--full tr:nth-child(even) td:first-child {
                background: rgba(248,250,252,0.98);
            }
            .dcf-table--ev {
                font-size: 10px;
                width: 100%;
                margin-bottom: 0;
                border-collapse: collapse;
                table-layout: fixed; /* Фиксированная ширина колонок */
            }
            .dcf-table--ev td {
                padding: 2px 4px;
                line-height: 1.15;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .dcf-table--ev td:first-child {
                font-size: 10px;
                width: 50%;
                padding-right: 2px;
                max-width: 50%;
            }
            .dcf-table--ev td:last-child {
                font-size: 11px;
                font-weight: 500;
                text-align: right;
                width: 50%;
                padding-left: 2px;
                word-break: break-word;
                overflow-wrap: break-word;
            }
            .dcf-table--ev tr:last-child td {
                font-weight: 600;
                font-size: 12px;
                padding-top: 4px;
                padding-bottom: 4px;
                border-top: 2px solid var(--border-color);
            }
            .dcf-table--ev tr:last-child td:first-child {
                width: 45%;
            }
            .dcf-table--ev tr:last-child td:last-child {
                width: 55%;
            }
            /* Убираем обертку для таблицы EV на мобильных, чтобы она была видна полностью */
            .dcf-table-wrapper--ev {
                overflow-x: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100%;
                max-width: 100%;
            }
            /* Улучшаем читаемость таблицы EV */
            .dcf-table--ev tr {
                border-bottom: 1px solid rgba(0,0,0,0.05);
            }
            .dcf-table--ev tr:last-child {
                border-bottom: none;
            }
            /* Компактные карточки для EV показателей */
            .dcf-table-wrapper--ev {
                width: 100%;
                max-width: 100%;
            }
            .dcf-table--ev,
            .dcf-table--ev tbody {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                width: 100%;
            }
            .dcf-table--ev {
                border-collapse: collapse;
                font-size: 11px;
            }
            .dcf-table--ev tr {
                display: flex;
                flex-direction: column;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                border: 1px solid var(--border-color);
                border-radius: 10px;
                padding: 6px 8px;
                background: white;
                box-shadow: var(--shadow-sm);
                min-width: 0; /* Позволяет сжиматься */
            }
            .dcf-table--ev tr + tr {
                margin-top: 8px;
            }
            .dcf-table--ev td {
                width: 100% !important;
                padding: 0 !important;
                border: none !important;
                text-align: left !important;
                line-height: 1.2;
                min-width: 0; /* Позволяет сжиматься */
                overflow: hidden;
            }
            .dcf-table--ev td:first-child {
                font-size: 10px;
                color: var(--text-secondary);
                white-space: normal;
                word-wrap: break-word;
                overflow-wrap: break-word;
                max-width: 100%;
            }
            .dcf-table--ev td:last-child {
                margin-top: 4px;
                font-size: 14px;
                font-weight: 600;
                color: var(--text-primary);
                white-space: normal;
                word-wrap: break-word;
                overflow-wrap: break-word;
                text-align: left !important;
                max-width: 100%;
            }
            .dcf-table--ev tr:last-child td:last-child {
                font-size: 15px;
            }
            .ev-label-short {
                display: inline;
            }
            .ev-label-full {
                display: none;
            }
            /* Компактные отступы для DCF карточки */
            .dcf-footnote {
                font-size: 11px;
                margin-top: 8px;
                margin-bottom: 12px;
                line-height: 1.5;
            }
            /* Улучшаем отображение кнопок в таблице форм */
            .table-row > div:last-child {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .table-row .btn {
                width: 100%;
                padding: 10px 16px;
                font-size: 13px;
            }
            /* Дополнительные улучшения для мобильной версии */
            .dashboard-header-content {
                padding: 0 16px;
            }
            .empty-state {
                padding: 40px 16px;
            }
            .empty-state-icon {
                font-size: 48px;
            }
            .empty-state h3 {
                font-size: 18px;
            }
        }
        @media (max-width: 375px) {
            .dcf-card {
                padding: 12px;
            }
            .dcf-table-wrapper {
                margin: 0;
                padding: 0;
                width: 100%;
                max-width: 100%;
            }
            .dcf-table {
                min-width: 450px;
                font-size: 10px;
            }
            .dcf-table th,
            .dcf-table td {
                padding: 5px 3px;
            }
            .dcf-table--full th:first-child {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
                font-size: 9px;
            }
            .dcf-table--full td:first-child {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
                font-size: 9px;
            }
            .dcf-table th:not(:first-child),
            .dcf-table td:not(:first-child) {
                font-size: 9px;
            }
            .dcf-table--ev tr {
                padding: 5px 6px !important;
            }
            .dcf-table--ev td:first-child {
                font-size: 9px !important;
            }
            .dcf-table--ev td:last-child {
                font-size: 12px !important;
            }
            .dcf-table--ev tr:last-child td:last-child {
                font-size: 13px !important;
            }
        }
        .dcf-card {
            margin-top: 48px;
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }
        .dcf-card h2 {
            margin-top: 0;
            font-size: 24px;
            margin-bottom: 16px;
        }
        .dcf-card__actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .btn-export-pdf {
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            background: white;
        }
        .btn-export-pdf:hover {
            background: rgba(102, 126, 234, 0.05);
        }
        .dcf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .dcf-table th,
        .dcf-table td {
            border: 1px solid var(--border-color);
            padding: 12px;
            text-align: left;
        }
        .dcf-table th {
            background: rgba(245,247,250,0.8);
            font-weight: 600;
        }
        .dcf-table--full th:first-child {
            width: 220px;
        }
        .dcf-table--full td {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .dcf-table--full td:first-child {
            text-align: left;
            font-weight: 500;
            color: var(--text-primary);
        }
        .dcf-col-fact {
            background: rgba(248,250,252,0.6);
        }
        .dcf-col-forecast {
            background: rgba(255,255,255,0.8);
        }
        .dcf-col-tv {
            background: rgba(20,184,166,0.08);
        }
        .dcf-cell-tv {
            font-weight: 600;
        }
        .dcf-params-strip {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .dcf-footnote {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: -12px;
            margin-bottom: 16px;
        }
        .dcf-table--ev {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 0;
        }
        .dcf-table--ev td {
            border: none;
            padding: 6px 12px;
            text-align: right;
        }
        .dcf-table--ev td:first-child {
            text-align: left;
            font-weight: 500;
            color: var(--text-primary);
        }
        @media print {
            @page {
                size: A4;
                margin: 6mm;
            }
            /* Prevent empty pages */
            body.print-teaser {
                height: auto !important;
                overflow: visible !important;
            }
            body.print-teaser * {
                page-break-after: avoid !important;
                page-break-before: avoid !important;
            }
            body.print-teaser .teaser-section {
                page-break-after: auto !important;
                height: auto !important;
                max-height: none !important;
                page-break-inside: avoid !important;
            }
            /* Prevent orphaned elements */
            body.print-teaser .teaser-hero,
            body.print-teaser .teaser-grid {
                page-break-inside: avoid !important;
            }
            /* Ensure no empty pages */
            body.print-teaser .teaser-section:empty {
                display: none !important;
            }
            body.print-dcf * {
                visibility: hidden !important;
            }
            body.print-dcf #dcf-card,
            body.print-dcf #dcf-card * {
                visibility: visible !important;
            }
            body.print-dcf #dcf-card {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
            }
            body.print-teaser * {
                visibility: hidden !important;
            }
            body.print-teaser #teaser-section,
            body.print-teaser #teaser-section * {
                visibility: visible !important;
            }
            body.print-teaser #teaser-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
                background: #fff;
                padding: 6px 10px !important;
                margin: 0 !important;
                font-size: 9px;
                max-height: 100vh;
                overflow: hidden;
            }
            body.print-teaser [data-print-exclude] {
                display: none !important;
            }
            /* Optimize teaser hero for print */
            body.print-teaser .teaser-hero {
                padding: 6px 8px !important;
                margin-bottom: 4px !important;
                border-radius: 4px !important;
                gap: 4px !important;
                grid-template-columns: 1fr !important;
            }
            body.print-teaser .teaser-hero__content h3 {
                font-size: 12px !important;
                margin-bottom: 1px !important;
                line-height: 1.15 !important;
            }
            body.print-teaser .teaser-hero__description {
                font-size: 8px !important;
                line-height: 1.25 !important;
                margin: 1px 0 3px !important;
            }
            body.print-teaser .teaser-hero__tags {
                gap: 4px !important;
                margin-bottom: 4px !important;
            }
            body.print-teaser .teaser-chip {
                padding: 4px 8px !important;
                font-size: 8px !important;
                min-width: auto !important;
                gap: 6px !important;
            }
            body.print-teaser .teaser-chip__icon {
                width: 20px !important;
                height: 20px !important;
            }
            body.print-teaser .teaser-chip__icon svg {
                width: 12px !important;
                height: 12px !important;
            }
            body.print-teaser .teaser-chip__value {
                font-size: 9px !important;
            }
            body.print-teaser .teaser-chip__label {
                font-size: 7px !important;
            }
            body.print-teaser .teaser-hero__stats {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 4px !important;
            }
            body.print-teaser .teaser-stat {
                padding: 4px 6px !important;
                border-radius: 4px !important;
            }
            body.print-teaser .teaser-stat span {
                font-size: 7px !important;
            }
            body.print-teaser .teaser-stat strong {
                font-size: 11px !important;
                margin-top: 1px !important;
            }
            body.print-teaser .teaser-stat small {
                font-size: 7px !important;
                margin-top: 1px !important;
            }
            body.print-teaser .teaser-hero__status {
                margin-top: 4px !important;
            }
            body.print-teaser .teaser-status {
                font-size: 8px !important;
                padding-left: 12px !important;
            }
            body.print-teaser .teaser-status::before {
                width: 5px !important;
                height: 5px !important;
            }
            /* Optimize teaser header */
            body.print-teaser .teaser-header {
                margin-bottom: 4px !important;
            }
            body.print-teaser .teaser-header h2 {
                font-size: 12px !important;
                margin-bottom: 1px !important;
            }
            body.print-teaser .teaser-header p {
                font-size: 9px !important;
                margin: 0 !important;
            }
            body.print-teaser .teaser-actions {
                display: none !important;
            }
            /* Optimize teaser result */
            body.print-teaser .teaser-result {
                padding: 4px !important;
                border-radius: 4px !important;
                margin-top: 4px !important;
            }
            /* Optimize teaser grid for compact layout */
            body.print-teaser .teaser-grid {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 3px !important;
                margin-top: 3px !important;
                page-break-inside: avoid !important;
            }
            body.print-teaser .teaser-grid .teaser-card[data-variant="overview"] {
                grid-column: 1 / -1 !important;
            }
            body.print-teaser .teaser-grid .teaser-card[data-variant="chart"] {
                grid-column: span 1 !important;
            }
            /* Optimize teaser cards */
            body.print-teaser .teaser-card {
                padding: 5px 6px !important;
                border-radius: 3px !important;
                margin: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            body.print-teaser .teaser-card::before {
                display: none !important;
            }
            body.print-teaser .teaser-card__icon {
                width: 18px !important;
                height: 18px !important;
                font-size: 10px !important;
                margin-bottom: 3px !important;
                border-radius: 3px !important;
            }
            body.print-teaser .teaser-card h3 {
                font-size: 9px !important;
                margin: 0 0 1px !important;
                line-height: 1.15 !important;
            }
            body.print-teaser .teaser-card__subtitle {
                font-size: 7px !important;
                margin: 0 0 2px !important;
            }
            body.print-teaser .teaser-card p,
            body.print-teaser .teaser-card li {
                font-size: 7px !important;
                line-height: 1.2 !important;
                margin: 0 !important;
            }
            body.print-teaser .teaser-card ul {
                gap: 2px !important;
            }
            body.print-teaser .teaser-card ul li {
                padding-left: 10px !important;
                line-height: 1.25 !important;
            }
            body.print-teaser .teaser-card ul li::before {
                width: 3px !important;
                height: 3px !important;
                top: 4px !important;
            }
            body.print-teaser .teaser-card__footer {
                font-size: 7px !important;
                margin-top: 3px !important;
            }
            /* Optimize chart card - ensure it renders properly */
            body.print-teaser .teaser-chart-card {
                min-height: 80px !important;
                max-height: 85px !important;
                height: auto !important;
                page-break-inside: avoid;
            }
            /* ApexCharts for print - compact version */
            body.print-teaser .teaser-chart {
                min-height: 60px !important;
                max-height: 70px !important;
                height: 70px !important;
                padding: 2px !important;
                margin: 0 !important;
            }
            body.print-teaser .teaser-chart__note {
                font-size: 5px !important;
                margin-top: 1px !important;
                display: none !important;
            }
            body.print-teaser .teaser-card[data-variant="chart"] {
                padding: 3px 4px !important;
                margin: 0 !important;
            }
            body.print-teaser .teaser-card[data-variant="chart"] h3 {
                font-size: 7px !important;
                margin: 0 0 1px 0 !important;
            }
            body.print-teaser .apexcharts-canvas,
            body.print-teaser .apexcharts-canvas * {
                visibility: visible !important;
            }
            body.print-teaser .apexcharts-svg {
                height: 60px !important;
            }
            /* Hide decorative elements */
            body.print-teaser .teaser-section::before,
            body.print-teaser .teaser-section::after {
                display: none !important;
            }
            body.print-teaser .teaser-progress {
                display: none !important;
            }
        }
        .dcf-source-note {
            font-size: 13px;
            color: var(--text-secondary);
            margin: -8px 0 16px;
        }
        .dcf-source-note strong {
            color: var(--text-primary);
        }
        .dcf-source-note--warning {
            color: #ad6800;
        }
        .dcf-print-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .warnings {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            background: #fff7e6;
            border: 1px solid #ffe2a8;
            color: #ad6800;
        }
        .teaser-section {
            margin-top: 40px;
            padding: 44px;
            border-radius: 32px;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.06);
            background: linear-gradient(180deg, #ffffff 0%, #f7f8fb 100%);
            box-shadow: 0 15px 45px rgba(15,23,42,0.06);
        }
        .teaser-section,
        .teaser-section p,
        .teaser-section li,
        .teaser-section .teaser-status,
        .teaser-section .teaser-card__footer,
        .teaser-section .btn {
            font-family: 'Manrope', 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .teaser-section::before,
        .teaser-section::after {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(99,102,241,0.25), transparent 65%);
            filter: blur(20px);
            opacity: 0.5;
        }
        .teaser-section::before {
            inset: auto auto -120px -80px;
        }
        .teaser-section::after {
            inset: -140px -60px auto auto;
        }
        .teaser-section--investors {
            margin-top: 28px;
            background: linear-gradient(180deg, #fdfdfd 0%, #f3f6fb 100%);
            color: var(--text-primary);
            border: 1px solid rgba(15, 23, 42, 0.08);
        }
        .teaser-section--investors::before,
        .teaser-section--investors::after {
            display: none;
        }
        .teaser-section--investors .teaser-header p {
            color: var(--text-secondary);
        }
        .teaser-section--investors .investor-controls {
            border: 1px dashed rgba(99, 102, 241, 0.25);
            background: rgba(255, 255, 255, 0.9);
        }
        .teaser-section--investors .investor-result {
            margin-top: 20px;
        }
        .teaser-section--investors .investor-card {
            background: rgba(255, 255, 255, 0.95);
            border-color: rgba(99, 102, 241, 0.35);
            color: var(--text-primary);
        }
        .teaser-header {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }
        .teaser-header h2 {
            margin: 0;
            font-size: 28px;
            font-family: 'Space Grotesk', 'Manrope', 'Inter', sans-serif;
            letter-spacing: 0.01em;
        }
        .teaser-header p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 15px;
        }
        .teaser-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 12px;
        }
        .teaser-status {
            min-height: 24px;
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 16px;
        }
        .teaser-progress {
            height: 8px;
            border-radius: 999px;
            background: rgba(99,102,241,0.15);
            position: relative;
            overflow: hidden;
            margin-bottom: 18px;
            margin-top: 18px;
            opacity: 0;
            transform: translateY(4px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            display: block;
        }
        .teaser-progress.is-visible {
            opacity: 1;
            transform: translateY(0);
            display: block !important;
        }
        .teaser-progress__bar {
            position: absolute;
            inset: 0;
            width: 0%;
            background: linear-gradient(90deg, #6366f1, #a855f7);
            transition: width 0.3s ease;
            height: 100%;
            border-radius: 999px;
        }
        .term-sheet-result {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: rgba(0, 0, 0, 0.2) transparent;
        }
        .term-sheet-result::-webkit-scrollbar {
            height: 8px;
        }
        .term-sheet-result::-webkit-scrollbar-track {
            background: transparent;
        }
        .term-sheet-result::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }
        .term-sheet-result::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }
        .term-sheet-result .term-sheet-document {
            max-width: 1200px !important;
            width: 100% !important;
        }
        
        /* Мобильная верстка для Term Sheet */
        @media (max-width: 768px) {
            .term-sheet-controls {
                flex-direction: column !important;
                align-items: stretch !important;
                position: relative !important;
                z-index: 10 !important;
            }
            .term-sheet-controls .btn,
            .term-sheet-controls a.btn {
                width: 100% !important;
                padding: 14px 20px !important;
                font-size: 15px !important;
                touch-action: manipulation !important;
                -webkit-tap-highlight-color: rgba(16, 185, 129, 0.3) !important;
                position: relative !important;
                z-index: 1 !important;
                cursor: pointer !important;
                user-select: none !important;
                -webkit-user-select: none !important;
            }
            #generate-term-sheet-btn {
                min-height: 48px !important;
                display: block !important;
            }
            .term-sheet-controls a[href="term_sheet_word.php"] {
                min-height: 48px !important;
                display: block !important;
                text-align: center !important;
            }
            .term-sheet-result {
                margin: 0 -20px;
                padding: 0 20px;
            }
            .term-sheet-result .term-sheet-document {
                padding: 24px 16px !important;
                border-radius: 16px !important;
                font-size: 14px !important;
                line-height: 1.6 !important;
            }
            .term-sheet-result .term-sheet-document > div:first-child {
                margin-bottom: 32px !important;
                padding-bottom: 24px !important;
            }
            .term-sheet-result .term-sheet-document h1 {
                font-size: 24px !important;
                margin-bottom: 8px !important;
            }
            .term-sheet-result .term-sheet-document > div:first-child p {
                font-size: 14px !important;
            }
            .term-sheet-result .term-sheet-document table {
                font-size: 13px !important;
                width: 100% !important;
                display: table !important;
                table-layout: fixed !important;
            }
            .term-sheet-result .term-sheet-document table tr {
                display: table-row !important;
            }
            .term-sheet-result .term-sheet-document table td {
                padding: 12px 8px !important;
                font-size: 13px !important;
                line-height: 1.5 !important;
                word-wrap: break-word !important;
                overflow-wrap: break-word !important;
            }
            .term-sheet-result .term-sheet-document table td:first-child {
                width: 30% !important;
                min-width: 80px;
                padding-right: 12px !important;
                font-size: 12px !important;
                font-weight: 600 !important;
            }
            .term-sheet-result .term-sheet-document table td:last-child {
                width: 70% !important;
            }
            .term-sheet-result .term-sheet-document h3 {
                font-size: 15px !important;
                margin-top: 12px !important;
                margin-bottom: 6px !important;
            }
            .term-sheet-result .term-sheet-document p {
                font-size: 13px !important;
                line-height: 1.6 !important;
                margin-bottom: 10px !important;
            }
            .term-sheet-result .term-sheet-document > div:last-child {
                margin-top: 32px !important;
                padding-top: 24px !important;
                font-size: 12px !important;
            }
            .term-sheet-result .term-sheet-document > div:last-child p {
                font-size: 12px !important;
                margin-bottom: 6px !important;
            }
        }
        
        @media (max-width: 480px) {
            .term-sheet-result {
                margin: 0 -16px;
                padding: 0 16px;
            }
            .term-sheet-result .term-sheet-document {
                padding: 20px 12px !important;
                border-radius: 12px !important;
                font-size: 13px !important;
            }
            .term-sheet-result .term-sheet-document h1 {
                font-size: 20px !important;
            }
            .term-sheet-result .term-sheet-document > div:first-child p {
                font-size: 13px !important;
            }
            .term-sheet-result .term-sheet-document table {
                font-size: 12px !important;
                width: 100% !important;
                display: table !important;
                table-layout: fixed !important;
            }
            .term-sheet-result .term-sheet-document table td {
                padding: 10px 6px !important;
                font-size: 12px !important;
                word-wrap: break-word !important;
                overflow-wrap: break-word !important;
            }
            .term-sheet-result .term-sheet-document table td:first-child {
                width: 35% !important;
                font-size: 11px !important;
                padding-right: 8px !important;
                font-weight: 600 !important;
            }
            .term-sheet-result .term-sheet-document table td:last-child {
                width: 65% !important;
            }
            .term-sheet-result .term-sheet-document h3 {
                font-size: 14px !important;
            }
            .term-sheet-result .term-sheet-document p {
                font-size: 12px !important;
            }
        }
        .teaser-result {
            background: rgba(255,255,255,0.85);
            border: 1px dashed rgba(99,102,241,0.35);
            border-radius: 18px;
            padding: 18px;
            min-height: 80px;
            box-shadow: inset 0 0 25px rgba(15,23,42,0.03);
        }
        /* Стили для режима редактирования тизера */
        .teaser-result.teaser-edit-mode {
            border: 2px solid rgba(99,102,241,0.5);
            background: rgba(255,255,255,0.95);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.1), inset 0 0 25px rgba(15,23,42,0.03);
        }
        .teaser-editable {
            position: relative;
            outline: none;
            transition: all 0.2s ease;
            border-radius: 4px;
            padding: 2px 4px;
            margin: -2px -4px;
        }
        .teaser-editable:hover {
            background: rgba(99,102,241,0.08);
            box-shadow: 0 0 0 2px rgba(99,102,241,0.2);
        }
        .teaser-editable:focus {
            background: rgba(99,102,241,0.12);
            box-shadow: 0 0 0 2px rgba(99,102,241,0.4);
            outline: none;
        }
        .teaser-editable::before {
            content: "✎";
            position: absolute;
            left: -20px;
            top: 2px;
            font-size: 12px;
            color: rgba(99,102,241,0.6);
            opacity: 0;
            transition: opacity 0.2s ease;
            pointer-events: none;
        }
        .teaser-edit-mode .teaser-editable:hover::before {
            opacity: 1;
        }
        .teaser-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 22px;
            position: relative;
            z-index: 1;
        }
        .teaser-grid .teaser-card[data-variant="overview"] {
            grid-column: 1 / -1;
        }
        .teaser-grid .teaser-card[data-variant="chart"] {
            grid-column: span 2;
        }
        .teaser-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 22px;
            padding: 22px;
            background: rgba(255,255,255,0.92);
            box-shadow: 0 15px 40px rgba(15,23,42,0.08);
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(6px);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .teaser-card::before {
            content: "";
            position: absolute;
            inset: 0;
            opacity: 0.18;
            background: linear-gradient(135deg, #a855f7, #6366f1);
            transition: opacity 0.3s ease;
        }
        .teaser-card[data-variant="overview"]::before { background: linear-gradient(135deg, #a855f7, #6366f1); }
        .teaser-card[data-variant="profile"]::before { background: linear-gradient(135deg, #14b8a6, #22d3ee); }
        .teaser-card[data-variant="products"]::before { background: linear-gradient(135deg, #f97316, #facc15); }
        .teaser-card[data-variant="market"]::before { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }
        .teaser-card[data-variant="financial"]::before { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .teaser-card[data-variant="highlights"]::before { background: linear-gradient(135deg, #f43f5e, #fb7185); }
        .teaser-card[data-variant="deal"]::before { background: linear-gradient(135deg, #10b981, #22c55e); }
        .teaser-card[data-variant="next"]::before { background: linear-gradient(135deg, #0ea5e9, #14b8a6); }
        .teaser-card[data-variant="chart"]::before { background: linear-gradient(135deg, #818cf8, #6366f1); }
        .teaser-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 45px rgba(15,23,42,0.12);
        }
        .teaser-card:hover::before {
            opacity: 0.32;
        }
        .teaser-card > * {
            position: relative;
            z-index: 1;
        }
        .teaser-card h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: var(--text-primary);
            font-family: 'Space Grotesk', 'Manrope', 'Inter', sans-serif;
            letter-spacing: 0.01em;
        }
        .teaser-card__subtitle {
            margin: 0 0 12px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .teaser-card ul {
            margin: 0;
            padding: 0;
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .teaser-card ul li {
            position: relative;
            padding-left: 18px;
            line-height: 1.45;
        }
        .teaser-card ul li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 7px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #6366f1;
            opacity: 0.7;
        }
        .teaser-card__footer {
            margin-top: 16px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .teaser-card__icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.5);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 14px;
            color: #4338ca;
            box-shadow: inset 0 0 10px rgba(99,102,241,0.15);
        }
        .teaser-spinner {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
        }
        .teaser-spinner:before {
            content: "";
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-top-color: rgba(102, 126, 234, 0.9);
            animation: teaser-spin 0.8s linear infinite;
            display: inline-block;
        }
        @keyframes teaser-spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            .teaser-section {
                padding: 20px;
            }
            .teaser-grid {
                grid-template-columns: 1fr;
            }
            .teaser-grid .teaser-card[data-variant="chart"] {
                grid-column: auto;
            }
        }
        .teaser-chart-card {
            padding: 0;
        }
        .teaser-chart {
            position: relative;
            border: 1px solid rgba(99, 102, 241, 0.15);
            border-radius: 18px;
            background: rgba(99, 102, 241, 0.03);
            width: 100%;
            min-height: 260px;
            padding: 8px 8px 0;
        }
        .teaser-chart .apexcharts-canvas {
            margin: 0 auto;
        }
        .teaser-chart__note {
            margin-top: 6px;
            font-size: 11px;
            color: var(--text-secondary);
            text-align: center;
        }
        .investor-section {
            margin-top: 32px;
            padding: 32px;
            background: #fff;
            border-radius: 28px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 25px 55px rgba(15,23,42,0.12);
        }
        .investor-section__intro {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }
        .investor-section__intro h3 {
            margin: 0 0 6px;
            font-size: 24px;
            letter-spacing: -0.01em;
            font-family: 'Space Grotesk', 'Manrope', 'Inter', sans-serif;
        }
        .investor-section__intro p {
            margin: 0;
            color: var(--text-secondary);
        }
        .investor-section__count {
            font-size: 14px;
            font-weight: 600;
            color: var(--primary-color);
            padding: 6px 14px;
            background: rgba(99,102,241,0.12);
            border-radius: 999px;
        }
        .investor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        .investor-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 20px;
            padding: 20px;
            background: rgba(248,250,252,0.95);
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .investor-card[data-source="ai"] {
            background: rgba(241,245,255,0.95);
            border-color: rgba(99,102,241,0.3);
        }
        .investor-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(15,23,42,0.15);
        }
        .investor-card__head h4 {
            margin: 0;
            font-size: 18px;
        }
        .investor-card__badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 999px;
            background: rgba(14, 165, 233, 0.15);
            color: #0369a1;
            margin-top: 6px;
        }
        .investor-card__focus {
            margin: 0;
            color: var(--text-secondary);
            line-height: 1.45;
        }
        .investor-card__check {
            margin: 0;
            font-weight: 600;
            color: var(--text-primary);
        }
        .investor-card__reason {
            margin: 0;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .investor-card__actions {
            margin-top: auto;
        }
        .btn-investor-send {
            width: 100%;
            border: 1px solid rgba(15, 23, 42, 0.2);
            background: #fff;
            color: var(--text-primary);
            font-weight: 600;
        }
        .btn-investor-send:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .btn-investor-send.is-sent {
            background: rgba(34,197,94,0.2);
            border-color: rgba(34,197,94,0.6);
            color: #15803d;
        }
        .investor-controls {
            margin-top: 20px;
            padding: 20px;
            border: 1px dashed rgba(99,102,241,0.35);
            border-radius: 18px;
            background: rgba(255,255,255,0.85);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .investor-status {
            min-height: 18px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .investor-progress {
            height: 6px;
            border-radius: 999px;
            background: rgba(99,102,241,0.15);
            overflow: hidden;
            opacity: 0;
            transform: translateY(4px);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .investor-progress.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .investor-progress__bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #0ea5e9, #6366f1);
            transition: width 0.3s ease;
        }
        .investor-result {
            margin-top: 18px;
        }
        /* --- Modern teaser visuals --- */
        :root {
            --teaser-night: #f7f8fb;
            --teaser-card-surface: #ffffff;
            --teaser-stroke: rgba(15, 23, 42, 0.08);
            --teaser-highlight: #6366f1;
            --teaser-highlight-alt: #0ea5e9;
            --teaser-text: #0f172a;
            --teaser-muted: rgba(71, 85, 105, 0.9);
        }
        .teaser-section {
            color: var(--teaser-text);
            background: radial-gradient(circle at 0% -20%, rgba(99,102,241,0.14), transparent 55%),
                        radial-gradient(circle at 100% 0%, rgba(14,165,233,0.12), transparent 45%),
                        var(--teaser-night);
            border: 1px solid rgba(15,23,42,0.05);
            box-shadow: 0 18px 55px rgba(15,23,42,0.08);
        }
        .teaser-section::before,
        .teaser-section::after {
            width: 420px;
            height: 420px;
            opacity: 0.15;
            filter: blur(30px);
        }
        .teaser-section::before {
            background: radial-gradient(circle, rgba(99, 102, 241, 0.4), transparent 70%);
        }
        .teaser-section::after {
            background: radial-gradient(circle, rgba(14, 165, 233, 0.3), transparent 70%);
        }
        .teaser-header h2 {
            color: var(--teaser-text);
            font-size: 30px;
        }
        .teaser-header p {
            color: var(--teaser-muted);
        }
        .teaser-actions .btn {
            padding: 12px 24px;
            font-size: 14px;
            border-radius: 999px;
        }
        .teaser-actions .btn-primary {
            background: linear-gradient(120deg, var(--teaser-highlight), var(--teaser-highlight-alt));
            border: none;
            box-shadow: 0 18px 35px rgba(99,102,241,0.25);
            color: #fff;
        }
        .teaser-actions .btn-primary:hover {
            box-shadow: 0 22px 46px rgba(99,102,241,0.3);
            transform: translateY(-2px);
        }
        .teaser-actions .btn-secondary {
            border-color: rgba(99,102,241,0.25);
            background: rgba(255,255,255,0.9);
            color: var(--teaser-text);
        }
        .teaser-actions .btn-secondary:hover {
            border-color: rgba(99,102,241,0.4);
            color: var(--teaser-highlight);
        }
        /* Стили для кнопок редактирования */
        #teaser-edit-controls {
            display: none;
            flex-direction: row;
            gap: 8px;
            margin-top: 8px;
        }
        #teaser-edit-controls.show {
            display: flex;
        }
        .teaser-hero {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 28px;
            border: 1px solid var(--teaser-stroke);
            border-radius: 28px;
            padding: 28px 32px;
            background: linear-gradient(135deg, #ffffff, rgba(247,248,251,0.7));
            box-shadow: 0 16px 36px rgba(15,23,42,0.08);
            margin-bottom: 28px;
            align-items: flex-start;
        }
        .teaser-hero__content {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .teaser-hero__content h3 {
            margin: 0;
            font-size: 26px;
            font-family: 'Space Grotesk', 'Manrope', 'Inter', sans-serif;
            letter-spacing: 0.01em;
        }
        .teaser-hero__description {
            font-size: 15px;
            line-height: 1.6;
            color: var(--teaser-muted);
            margin: 4px 0 12px;
            max-width: 640px;
        }
        .teaser-hero__tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .teaser-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(99,102,241,0.1) 0%, rgba(139,92,246,0.08) 100%);
            border: 1.5px solid rgba(99,102,241,0.2);
            min-width: 120px;
            color: var(--teaser-muted);
            font-size: 11px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(99,102,241,0.08);
        }
        .teaser-chip:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99,102,241,0.15);
            border-color: rgba(99,102,241,0.3);
        }
        .teaser-chip__icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(99,102,241,0.15) 0%, rgba(139,92,246,0.12) 100%);
            flex-shrink: 0;
        }
        .teaser-chip__icon svg {
            width: 14px;
            height: 14px;
            stroke: var(--teaser-text);
            opacity: 0.8;
        }
        .teaser-chip__content {
            display: flex;
            flex-direction: column;
            gap: 1px;
            flex: 1;
            min-width: 0;
        }
        .teaser-chip__label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--teaser-muted);
            opacity: 0.8;
        }
        .teaser-chip__value {
            color: var(--teaser-text);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
            word-break: break-word;
        }
        .teaser-hero__stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
            padding: 12px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(99,102,241,0.05) 0%, rgba(139,92,246,0.03) 100%);
            border: 2px solid rgba(99,102,241,0.15);
            box-shadow: 0 8px 24px rgba(99,102,241,0.1), inset 0 1px 0 rgba(255,255,255,0.8);
            margin-top: 8px;
            width: 100%;
            max-width: 100%;
        }
        .teaser-stat {
            padding: 16px 24px;
            border-radius: 16px;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255,255,255,0.95) 100%);
            border: 1.5px solid rgba(99,102,241,0.2);
            box-shadow: 0 4px 16px rgba(99,102,241,0.12), 0 2px 4px rgba(15,23,42,0.06);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
            flex: 1;
        }
        .teaser-stat::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, rgba(99,102,241,0.6) 0%, rgba(139,92,246,0.6) 100%);
            opacity: 0.8;
        }
        .teaser-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99,102,241,0.18), 0 4px 8px rgba(15,23,42,0.1);
            border-color: rgba(99,102,241,0.3);
        }
        .teaser-stat span {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(99,102,241,0.8);
            font-weight: 600;
        }
        .teaser-stat strong {
            display: block;
            font-size: 20px;
            margin-top: 4px;
            color: var(--teaser-text);
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .teaser-stat small {
            display: block;
            margin-top: 3px;
            font-size: 10px;
            color: var(--teaser-muted);
            opacity: 0.9;
        }
        .teaser-hero__status {
            grid-column: 1 / -1;
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .teaser-status {
            font-size: 12px;
            color: var(--teaser-muted);
            position: relative;
            padding-left: 18px;
        }
        .teaser-status::before {
            content: "";
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teaser-highlight), var(--teaser-highlight-alt));
            box-shadow: 0 0 8px rgba(99,102,241,0.35);
        }
        .teaser-progress {
            background: rgba(99,102,241,0.12);
        }
        .teaser-progress__bar {
            background: linear-gradient(120deg, var(--teaser-highlight), var(--teaser-highlight-alt));
        }
        .teaser-result {
            background: rgba(255,255,255,0.95);
            border: 1px dashed rgba(99,102,241,0.2);
            border-radius: 28px;
            padding: 24px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
            color: var(--teaser-text);
        }
        .teaser-result p {
            color: var(--teaser-muted) !important;
        }
        .teaser-grid {
            margin-top: 28px;
        }
        .teaser-section .teaser-card {
            background: var(--teaser-card-surface);
            border: 1px solid var(--teaser-stroke);
            color: var(--teaser-text);
            box-shadow: 0 14px 35px rgba(15,23,42,0.08);
        }
        .teaser-section .teaser-card::before {
            opacity: 0.1;
        }
        .teaser-section .teaser-card h3 {
            color: var(--teaser-text);
        }
        .teaser-section .teaser-card p,
        .teaser-section .teaser-card li,
        .teaser-section .teaser-card__footer {
            color: var(--teaser-muted);
        }
        .teaser-section .teaser-card ul li::before {
            content: "•";
            color: var(--teaser-highlight);
            position: absolute;
            left: 0;
        }
        .teaser-card__icon {
            width: 44px;
            height: 44px;
            border-radius: 16px;
            background: rgba(99,102,241,0.1);
            border: 1px solid rgba(99,102,241,0.25);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 12px;
            color: var(--teaser-highlight);
        }
        .investor-section {
            border: 1px solid rgba(15,23,42,0.06);
            background: linear-gradient(180deg, #ffffff 0%, #f3f6fb 100%);
            color: var(--teaser-text);
        }
        .investor-card {
            background: rgba(255,255,255,0.95);
            border: 1px solid rgba(15,23,42,0.08);
            color: var(--teaser-text);
        }
        .investor-card__focus,
        .investor-card__reason,
        .investor-card__check {
            color: var(--teaser-muted);
        }
        .investor-controls {
            border-color: rgba(99,102,241,0.25);
            background: rgba(255,255,255,0.92);
        }
        .investor-status {
            color: var(--teaser-muted);
        }
        @media (max-width: 1024px) {
            .teaser-hero {
                grid-template-columns: 1fr;
            }
            .teaser-hero__stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .teaser-section {
                padding: 28px 20px;
            }
            .teaser-hero {
                padding: 24px;
                border-radius: 24px;
            }
            .teaser-actions .btn {
                width: 100%;
            }
            .teaser-hero__stats {
                grid-template-columns: 1fr !important;
            }
            
            /* Мобильная версия блока "Определение цены" */
            #price-determination {
                margin-top: 32px !important;
            }
            
            #price-determination > div:first-child {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 16px !important;
            }
            
            #price-determination h2 {
                font-size: 22px !important;
                margin-bottom: 8px !important;
            }
            
            #price-determination > div:first-child > div:first-child > p {
                font-size: 13px !important;
                margin-top: 6px !important;
            }
            
            #calculate-multiplier-btn {
                width: 100% !important;
                padding: 14px 20px !important;
                font-size: 15px !important;
            }
            
            #multiplier-valuation-result {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            #multiplier-valuation-result > div {
                min-width: 0;
            }
            
            #final-price-section {
                margin-top: 24px !important;
                padding-top: 24px !important;
            }
            
            #final-price-section h3 {
                font-size: 18px !important;
                margin-bottom: 12px !important;
            }
            
            #final-price-section > div:first-child > div:first-child {
                padding: 16px !important;
            }
            
            #final-price-section > div:first-child > div:first-child p {
                font-size: 16px !important;
                line-height: 1.5 !important;
            }
            
            #final-price-section > div:last-child {
                max-width: 100% !important;
            }
            
            #final-price-section label {
                font-size: 16px !important;
                margin-bottom: 10px !important;
            }
            
            #final-price-section > div:last-child > div:first-child {
                flex-direction: row !important;
                gap: 10px !important;
                align-items: flex-start !important;
            }
            
            #final-price-input {
                flex: 3 !important;
                min-width: 0 !important;
                padding: 16px 20px !important;
                font-size: 18px !important;
            }
            
            #confirm-price-btn {
                flex: 1 !important;
                min-width: 0 !important;
                padding: 16px 16px !important;
                font-size: 15px !important;
                white-space: nowrap !important;
            }
            
            #final-price-updated-at {
                font-size: 12px !important;
                margin-top: 10px !important;
            }
            
            /* Адаптивные стили для результатов расчета мультипликатора */
            #multiplier-valuation-result {
                overflow-x: visible;
            }
            
            #multiplier-valuation-result > div {
                padding: 16px !important;
            }
            
            /* Диапазон оценки */
            #multiplier-valuation-result > div > div:first-child {
                padding: 16px !important;
                margin-bottom: 20px !important;
            }
            
            #multiplier-valuation-result > div > div:first-child > div:nth-child(2) {
                font-size: 20px !important;
                line-height: 1.3 !important;
                word-break: break-word;
            }
            
            #multiplier-valuation-result > div > div:first-child > div:last-child {
                font-size: 12px !important;
                margin-top: 10px !important;
            }
            
            /* Сектор */
            #multiplier-valuation-result > div > div:nth-child(2) {
                margin-bottom: 16px !important;
                padding-bottom: 16px !important;
            }
            
            #multiplier-valuation-result > div > div:nth-child(2) > div:last-child {
                font-size: 16px !important;
            }
            
            /* Мультипликаторы */
            #multiplier-valuation-result > div > div:nth-child(3) > div:last-child {
                flex-direction: column !important;
                gap: 10px !important;
            }
            
            #multiplier-valuation-result > div > div:nth-child(3) > div:last-child > div {
                min-width: 100% !important;
                flex: 1 1 100% !important;
                padding: 12px 14px !important;
            }
            
            /* Детали расчета */
            #multiplier-valuation-result > div > div:nth-child(4) > div:last-child {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
            }
            
            /* Итоговая стоимость */
            #multiplier-valuation-result > div > div:nth-child(5) {
                padding: 16px !important;
                margin-bottom: 16px !important;
            }
            
            #multiplier-valuation-result > div > div:nth-child(5) > div:nth-child(2) {
                font-size: 22px !important;
            }
            
            /* Финансовые показатели */
            #multiplier-valuation-result > div > div:last-child {
                margin-top: 20px !important;
                padding-top: 20px !important;
            }
            
            #multiplier-valuation-result > div > div:last-child > div:last-child {
                grid-template-columns: 1fr !important;
                gap: 12px !important;
            }
            
            #multiplier-valuation-result > div > div:last-child > div:last-child > div {
                padding: 14px !important;
            }
            
            #multiplier-valuation-result > div > div:last-child > div:last-child > div > div:last-child {
                font-size: 18px !important;
            }
            
            #multiplier-valuation-result > div > div:last-child > div:last-child > div > div:last-child > span {
                font-size: 12px !important;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1"></script>
</head>
<body>
    <?php if (isModerator() && isImpersonating()): 
        $impersonatedUserId = getImpersonatedUserId();
        $impersonatedUser = null;
        if ($impersonatedUserId) {
            try {
                $impersonatedUserStmt = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
                $impersonatedUserStmt->execute([$impersonatedUserId]);
                $impersonatedUser = $impersonatedUserStmt->fetch();
            } catch (PDOException $e) {
                error_log("Error fetching impersonated user: " . $e->getMessage());
            }
        }
    ?>
    <div style="background: linear-gradient(135deg, #FF9500 0%, #FF6B00 100%); color: white; padding: 16px 20px; text-align: center; position: sticky; top: 0; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div style="flex: 1; min-width: 200px;">
                <strong>⚠️ Режим помощи клиенту</strong>
                <?php if ($impersonatedUser): ?>
                    <span style="opacity: 0.9; margin-left: 8px;">
                        Вы работаете от имени: <?php echo htmlspecialchars($impersonatedUser['email'], ENT_QUOTES, 'UTF-8'); ?>
                        <?php if (!empty($impersonatedUser['full_name'])): ?>
                            (<?php echo htmlspecialchars($impersonatedUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>)
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
            <a href="clear_impersonation.php?redirect=dashboard.php" 
               style="background: white; color: #FF9500; padding: 8px 16px; border-radius: 8px; text-decoration: none; font-weight: 600; white-space: nowrap;"
               onclick="return confirm('Выйти из режима помощи клиенту?');">
                Выйти из режима помощи
            </a>
        </div>
    </div>
    <?php endif; ?>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="index.php">Главная</a></li>
                    <li><a href="index.php#buy-business">Купить бизнес</a></li>
                    <li><a href="seller_form.php">Продать бизнес</a></li>
                    <li><a href="dashboard.php">Личный кабинет</a></li>
                    <?php if (isModerator()): ?>
                        <li><a href="moderation.php">Модерация</a></li>
                    <?php endif; ?>
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

    <div class="dashboard-header">
        <div class="dashboard-header-content">
            <h1>Личный кабинет</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>!</p>
        </div>
    </div>

    <div class="dashboard-container">
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <?php if (isset($_GET['type']) && $_GET['type'] == 'term_sheet'): ?>
                <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                    <strong>✓ Анкета Term Sheet успешно отправлена!</strong> Теперь вы можете перейти к его генерации.
                </div>
            <?php else: ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                <strong>✓ Анкета успешно отправлена!</strong> Команда SmartBizSell изучит информацию и свяжется с вами.
            </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] !== 'draft')); ?></div>
                <div class="stat-label">Заполненных анкет</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'draft')); ?></div>
                <div class="stat-label">Черновиков</div>
            </div>
        </div>

        <div class="dashboard-actions">
            <a href="seller_form.php" class="btn btn-primary">+ Создать новую анкету</a>
            <a href="#term-sheet-section" class="btn btn-primary" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); touch-action: manipulation; -webkit-tap-highlight-color: rgba(16, 185, 129, 0.3); cursor: pointer; position: relative; z-index: 1;">
                📄 Создать Term Sheet
            </a>
            <a href="profile.php" class="btn btn-secondary">Настройки профиля</a>
        </div>

        <div class="forms-table">
            <div class="table-header">Мои анкеты</div>
            
            <?php if (empty($activeForms)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>У вас пока нет отправленных анкет</h3>
                    <p>Заполните и отправьте анкету, чтобы получить материалы по сделке</p>
                    <a href="seller_form.php" class="btn btn-primary" style="margin-top: 20px;">Создать анкету</a>
                </div>
            <?php else: ?>
                <div style="padding: 0;">
                    <?php foreach ($activeForms as $form): ?>
                        <div class="table-row">
                            <div>
                                <strong><?php echo htmlspecialchars(html_entity_decode($form['asset_name'] ?: 'Без названия', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                                    Создана: <?php echo date('d.m.Y H:i', strtotime($form['created_at'])); ?>
                                </div>
                            </div>
                            <div>
                                <span class="status-badge" style="background: <?php echo $statusColors[$form['status']]; ?>; color: white;">
                                    <?php echo $statusLabels[$form['status']]; ?>
                                </span>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: var(--text-secondary);">Обновлена:</div>
                                <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['updated_at'])); ?></div>
                            </div>
                            <div>
                                <?php if ($form['submitted_at']): ?>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Отправлена:</div>
                                    <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['submitted_at'])); ?></div>
                                <?php else: ?>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Не отправлена</div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a href="view_form.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">Просмотр</a>
                                <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">Редактировать</a>
                                <a href="export_form_json.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">📥 JSON</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($draftForms)): ?>
        <div class="forms-table" style="margin-top: 32px;">
            <div class="table-header">Черновики</div>
            <div style="padding: 0;">
                <?php foreach ($draftForms as $form): ?>
                    <div class="table-row">
                    <div>
                        <strong><?php echo htmlspecialchars(html_entity_decode($form['asset_name'] ?: 'Черновик без названия', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                            Обновлён: <?php echo date('d.m.Y H:i', strtotime($form['updated_at'])); ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge" style="background: <?php echo $statusColors['draft']; ?>; color: white;">
                            <?php echo $statusLabels['draft']; ?>
                        </span>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary);">Создан:</div>
                        <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['created_at'])); ?></div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">
                            Продолжить заполнение
                        </a>
                        <a href="export_form_json.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">📥 JSON</a>
                        <button type="button" class="btn btn-danger delete-draft-btn" 
                                data-form-id="<?php echo $form['id']; ?>"
                                style="padding: 8px 16px; font-size: 12px; background: #FF3B30; color: white; border: none; border-radius: 6px; cursor: pointer;">
                            🗑️ Удалить
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Табы для переключения между анкетами -->
        <?php if (!empty($forms)): ?>
        <div class="forms-tabs" id="forms-tabs">
            <div class="forms-tabs__header">
                <h2>Активы на продажу</h2>
                <a href="seller_form.php" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">+ Создать новый актив</a>
            </div>
            <div class="forms-tabs__list" role="tablist">
                <?php foreach ($forms as $form): ?>
                    <?php
                    $isActive = $selectedForm && $selectedForm['id'] == $form['id'];
                    $formName = htmlspecialchars(html_entity_decode($form['asset_name'] ?: 'Без названия', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                    $formStatus = $form['status'] ?? 'draft';
                    
                    // Определяем статус модерации тизера
                    $teaserModeration = $form['teaser_moderation'] ?? null;
                    $teaserModerationStatus = null;
                    $teaserModerationLabel = '';
                    $teaserModerationColor = '#86868B';
                    $teaserModerationNotes = '';
                    
                    if ($teaserModeration) {
                        $teaserModerationStatus = $teaserModeration['moderation_status'] ?? null;
                        switch ($teaserModerationStatus) {
                            case 'pending':
                                $teaserModerationLabel = 'На модерации';
                                $teaserModerationColor = '#FF9500';
                                break;
                            case 'approved':
                                $teaserModerationLabel = 'Одобрен';
                                $teaserModerationColor = '#34C759';
                                break;
                            case 'rejected':
                                $teaserModerationLabel = 'Отказ';
                                $teaserModerationColor = '#FF3B30';
                                $teaserModerationNotes = $teaserModeration['moderation_notes'] ?? '';
                                break;
                            case 'published':
                                $teaserModerationLabel = 'Размещен на платформе';
                                $teaserModerationColor = '#007AFF';
                                break;
                        }
                    }
                    ?>
                    <button 
                        class="forms-tabs__tab <?php echo $isActive ? 'active' : ''; ?>" 
                        role="tab"
                        aria-selected="<?php echo $isActive ? 'true' : 'false'; ?>"
                        data-form-id="<?php echo $form['id']; ?>"
                        onclick="switchForm(<?php echo $form['id']; ?>)"
                        title="<?php if ($teaserModerationNotes): ?>Причина отказа: <?php echo htmlspecialchars($teaserModerationNotes, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>"
                    >
                        <span class="forms-tabs__tab-name"><?php echo $formName; ?></span>
                        <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-end;">
                            <span class="forms-tabs__tab-status" style="background: <?php echo $statusColors[$formStatus]; ?>;">
                                <?php echo $statusLabels[$formStatus]; ?>
                            </span>
                            <?php if ($teaserModerationStatus): ?>
                            <span class="forms-tabs__tab-status" style="background: <?php echo $teaserModerationColor; ?>; font-size: 10px; padding: 3px 8px;">
                                <?php echo htmlspecialchars($teaserModerationLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <?php if ($teaserModerationStatus === 'rejected' && $teaserModerationNotes): ?>
                            <span class="forms-tabs__tab-rejection-note" style="font-size: 9px; color: #FF3B30; max-width: 200px; text-align: right; line-height: 1.2; margin-top: 2px;">
                                <?php echo htmlspecialchars(mb_substr($teaserModerationNotes, 0, 60), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen($teaserModerationNotes) > 60 ? '...' : ''; ?>
                            </span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Блок загрузки документов актива -->
        <?php if ($selectedForm): ?>
        <?php
        // Загружаем список документов для выбранного актива
        $assetDocuments = [];
        $documentsStats = ['total_size' => 0, 'total_size_mb' => 0, 'max_size_mb' => 20, 'count' => 0];
        try {
            ensureAssetDocumentsTable();
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    file_name,
                    file_size,
                    file_type,
                    uploaded_at
                FROM asset_documents
                WHERE seller_form_id = ?
                ORDER BY uploaded_at DESC
            ");
            $stmt->execute([$selectedForm['id']]);
            $assetDocuments = $stmt->fetchAll();
            
            // Получаем статистику
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(file_size), 0) as total_size, COUNT(*) as count
                FROM asset_documents
                WHERE seller_form_id = ?
            ");
            $stmt->execute([$selectedForm['id']]);
            $stats = $stmt->fetch();
            $documentsStats = [
                'total_size' => (int)$stats['total_size'],
                'total_size_mb' => round($stats['total_size'] / 1024 / 1024, 2),
                'max_size_mb' => round(MAX_DOCUMENTS_SIZE_PER_ASSET / 1024 / 1024, 2),
                'count' => (int)$stats['count']
            ];
        } catch (PDOException $e) {
            error_log("Error loading asset documents: " . $e->getMessage());
        }
        ?>
        <div class="asset-documents-section" id="asset-documents-section" data-form-id="<?php echo $selectedForm['id']; ?>">
            <div class="asset-documents-header">
                <h2>Документы актива</h2>
                <p class="asset-documents-description">Загрузите документы, которые помогут покупателю лучше понять ваш бизнес (презентации, финансовые отчеты, фотографии и т.д.)</p>
            </div>
            
            <!-- Индикатор использования места -->
            <div class="storage-indicator">
                <div class="storage-indicator__label">
                    <span>Использовано места:</span>
                    <strong><?php echo $documentsStats['total_size_mb']; ?> МБ из <?php echo $documentsStats['max_size_mb']; ?> МБ</strong>
                </div>
                <div class="storage-indicator__bar">
                    <div class="storage-indicator__fill" style="width: <?php echo min(100, ($documentsStats['total_size'] / MAX_DOCUMENTS_SIZE_PER_ASSET) * 100); ?>%;"></div>
                </div>
            </div>
            
            <!-- Зона загрузки документов -->
            <div class="document-upload-zone" id="document-upload-zone">
                <input type="file" id="document-file-input" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.7z,.txt,.csv" multiple style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0; opacity: 0;">
                <div class="document-upload-zone__content">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="document-upload-icon">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <polyline points="17 8 12 3 7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <line x1="12" y1="3" x2="12" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p class="document-upload-zone__text">Перетащите файлы сюда или <button type="button" class="document-upload-zone__button" id="document-upload-btn">выберите файлы</button></p>
                    <p class="document-upload-zone__hint">Максимальный размер одного файла: 20 МБ. Общий объем документов: до <?php echo $documentsStats['max_size_mb']; ?> МБ</p>
                </div>
            </div>
            
            <!-- Список загруженных документов -->
            <div class="documents-list" id="documents-list">
                <?php if (empty($assetDocuments)): ?>
                    <div class="documents-empty">
                        <p>Документы не загружены</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($assetDocuments as $doc): ?>
                        <div class="document-item" data-document-id="<?php echo $doc['id']; ?>">
                            <div class="document-item__icon">
                                <?php
                                $fileExt = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                $iconClass = 'document-icon--default';
                                if (in_array($fileExt, ['pdf'])) {
                                    $iconClass = 'document-icon--pdf';
                                } elseif (in_array($fileExt, ['doc', 'docx'])) {
                                    $iconClass = 'document-icon--doc';
                                } elseif (in_array($fileExt, ['xls', 'xlsx'])) {
                                    $iconClass = 'document-icon--xls';
                                } elseif (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                    $iconClass = 'document-icon--image';
                                } elseif (in_array($fileExt, ['zip', 'rar', '7z'])) {
                                    $iconClass = 'document-icon--archive';
                                }
                                ?>
                                <div class="document-icon <?php echo $iconClass; ?>">
                                    <?php if ($iconClass === 'document-icon--image'): ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                            <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                                            <circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
                                            <path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    <?php elseif ($iconClass === 'document-icon--pdf'): ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/>
                                            <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/>
                                            <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2"/>
                                            <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2"/>
                                        </svg>
                                    <?php else: ?>
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/>
                                            <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="document-item__info">
                                <div class="document-item__name" title="<?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($doc['file_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="document-item__meta">
                                    <span><?php echo round($doc['file_size'] / 1024 / 1024, 2); ?> МБ</span>
                                    <span>•</span>
                                    <span><?php echo date('d.m.Y H:i', strtotime($doc['uploaded_at'])); ?></span>
                                </div>
                            </div>
                            <div class="document-item__actions">
                                <button type="button" class="document-item__delete" onclick="handleDocumentDelete(<?php echo $doc['id']; ?>)" title="Удалить документ">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                        <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Навигация между блоками - показывается если есть отправленная анкета или раздел Term Sheet -->
        <?php 
        // Навигация показывается если есть отправленная анкета или раздел Term Sheet
        $hasSubmittedForm = $latestForm && in_array($latestForm['status'] ?? '', ['submitted', 'review', 'approved'], true);
        // Term Sheet всегда доступен, поэтому навигация показывается всегда
        $showNavigation = true;
        ?>
        <?php if (!$latestForm && !empty($forms)): ?>
            <div class="teaser-section" style="text-align: center; padding: 60px 20px;">
                <h2 style="margin-bottom: 16px;">Выберите анкету для просмотра инструментов</h2>
                <p style="color: var(--text-secondary); margin-bottom: 24px;">Используйте вкладки выше, чтобы выбрать актив и просмотреть его DCF модель, оценку, тизер и Term Sheet.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($showNavigation && $latestForm): ?>
        <nav class="dashboard-nav" id="dashboard-nav" aria-label="Навигация по разделам">
            <ul class="dashboard-nav__list" role="list">
                <?php if ($dcfData && !$isStartup): ?>
                <li class="dashboard-nav__item" role="listitem">
                    <a href="#dcf-model" class="dashboard-nav__link" data-section="dcf-model" aria-label="Перейти к DCF модели">
                        <span class="dashboard-nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 3V21H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M7 16L12 11L16 15L21 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M21 10V3H14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="dashboard-nav__text">DCF модель</span>
                    </a>
                </li>
                <li class="dashboard-nav__item" role="listitem">
                    <a href="#price-determination" class="dashboard-nav__link" data-section="price-determination" aria-label="Перейти к определению цены">
                        <span class="dashboard-nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2V22M17 5H9.5C8.57174 5 7.6815 5.36875 7.02513 6.02513C6.36875 6.6815 6 7.57174 6 8.5C6 9.42826 6.36875 10.3185 7.02513 10.9749C7.6815 11.6312 8.57174 12 9.5 12H14.5C15.4283 12 16.3185 12.3687 16.9749 13.0251C17.6312 13.6815 18 14.5717 18 15.5C18 16.4283 17.6312 17.3185 16.9749 17.9749C16.3185 18.6312 15.4283 19 14.5 19H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="dashboard-nav__text">Определение цены</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($hasSubmittedForm): ?>
                <li class="dashboard-nav__item" role="listitem">
                    <a href="#teaser-section" class="dashboard-nav__link" data-section="teaser-section" aria-label="Перейти к AI тизеру">
                        <span class="dashboard-nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="dashboard-nav__text">AI тизер</span>
                    </a>
                </li>
                <li class="dashboard-nav__item" role="listitem">
                    <a href="#investors-section" class="dashboard-nav__link" data-section="investors-section" aria-label="Перейти к инвесторам">
                        <span class="dashboard-nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M17 21V19C17 17.9391 16.5786 16.9217 15.8284 16.1716C15.0783 15.4214 14.0609 15 13 15H5C3.93913 15 2.92172 15.4214 2.17157 16.1716C1.42143 16.9217 1 17.9391 1 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M9 11C11.2091 11 13 9.20914 13 7C13 4.79086 11.2091 3 9 3C6.79086 3 5 4.79086 5 7C5 9.20914 6.79086 11 9 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M23 21V19C22.9993 18.1137 22.7044 17.2528 22.1614 16.5523C21.6184 15.8519 20.8581 15.3516 20 15.13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 3.13C16.8604 3.35031 17.623 3.85071 18.1676 4.55232C18.7122 5.25392 19.0078 6.11683 19.0078 7.005C19.0078 7.89318 18.7122 8.75608 18.1676 9.45769C17.623 10.1593 16.8604 10.6597 16 10.88" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="dashboard-nav__text">Инвесторы</span>
                    </a>
                </li>
                <?php endif; ?>
                <li class="dashboard-nav__item" role="listitem">
                    <a href="#term-sheet-section" class="dashboard-nav__link" data-section="term-sheet-section" aria-label="Перейти к Term Sheet">
                        <span class="dashboard-nav__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M14 2V8H20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 13H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M16 17H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M10 9H9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="dashboard-nav__text">Term Sheet</span>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

        <?php if ($dcfData && !$isStartup): ?>
            <div class="dcf-card" id="dcf-model">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin-bottom:4px;">DCF Model (Доходный подход)</h2>
                    </div>
                    <button
                        type="button"
                        class="btn btn-export-pdf"
                        id="export-dcf-pdf"
                        data-asset-name="<?php echo htmlspecialchars(html_entity_decode($latestForm['asset_name'] ?? 'DCF', ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?>"
                        data-date-label="<?php echo isset($latestForm['submitted_at']) ? date('d.m.Y', strtotime($latestForm['submitted_at'])) : date('d.m.Y'); ?>"
                    >
                        Сохранить DCF в PDF
                    </button>
                </div>
                <?php if ($latestForm): ?>
                    <?php
                        $dcfAssetName = html_entity_decode($latestForm['asset_name'] ?: 'Без названия', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $dcfDate = null;
                        if (!empty($latestForm['submitted_at'])) {
                            $dcfDate = date('d.m.Y', strtotime($latestForm['submitted_at']));
                        } elseif (!empty($latestForm['updated_at'])) {
                            $dcfDate = date('d.m.Y', strtotime($latestForm['updated_at']));
                        }
                        $dcfStatusLabel = $statusLabels[$dcfSourceStatus] ?? $dcfSourceStatus ?? 'Черновик';
                        $noteClasses = 'dcf-source-note';
                        if (!in_array($dcfSourceStatus, ['submitted','review','approved'], true)) {
                            $noteClasses .= ' dcf-source-note--warning';
                        }
                    ?>
                    <p class="<?php echo $noteClasses; ?>">
                        <?php if (in_array($dcfSourceStatus, ['submitted','review','approved'], true)): ?>
                            Расчёт построен по анкете «<?php echo htmlspecialchars($dcfAssetName, ENT_QUOTES, 'UTF-8'); ?>»
                            <?php if ($dcfDate): ?>от <?php echo $dcfDate; ?><?php endif; ?>
                            (статус: <?php echo htmlspecialchars($dcfStatusLabel, ENT_QUOTES, 'UTF-8'); ?>).
                        <?php else: ?>
                            Последняя анкета «<?php echo htmlspecialchars($dcfAssetName, ENT_QUOTES, 'UTF-8'); ?>»
                            имеет статус «<?php echo htmlspecialchars($dcfStatusLabel, ENT_QUOTES, 'UTF-8'); ?>». Отправьте анкету, чтобы рассчитать модель.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (isset($dcfData['error'])): ?>
                    <div class="warnings"><?php echo htmlspecialchars($dcfData['error'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <?php
                        $columnsMeta = $dcfData['columns'] ?? [];
                        $rows = $dcfData['rows'] ?? [];
                        $evData = $dcfData['ev_breakdown'] ?? null;
                        $formatMoney = static function ($value, bool $isExpense = false): string {
                            if ($value === null) {
                                return '—';
                            }
                            $absValue = abs($value);
                            $decimals = $absValue > 0 && $absValue < 1 ? 2 : 0;
                            $formatted = number_format($absValue, $decimals, '.', ' ');
                            if ($isExpense && $value > 0) {
                                return '(' . $formatted . ')';
                            }
                            if ($isExpense && $value < 0) {
                                return '−(' . $formatted . ')';
                            }
                            return ($value < 0 ? '−' : '') . $formatted;
                        };
                        $formatPercent = static function ($value, bool $italic = false): string {
                            if ($value === null) {
                                return '—';
                            }
                            $formatted = number_format($value * 100, 2, '.', ' ') . '%';
                            return $italic ? '<em>' . $formatted . '</em>' : $formatted;
                        };
                        $formatDecimal = static function ($value): string {
                            if ($value === null) {
                                return '—';
                            }
                            return number_format($value, 4, '.', ' ');
                        };
                        $formatEvRow = static function ($value) use ($formatMoney): string {
                            if ($value === null) {
                                return '—';
                            }
                            return $formatMoney($value) . ' млн ₽';
                        };
                    ?>
                    <div class="dcf-params-strip">
                        <span>WACC: <?php echo number_format(($dcfData['wacc'] ?? 0) * 100, 2, '.', ' '); ?>%</span>
                        <span>g: <?php echo number_format(($dcfData['perpetual_growth'] ?? 0) * 100, 2, '.', ' '); ?>%</span>
                    </div>
                    <div class="dcf-table-wrapper">
                    <table class="dcf-table dcf-table--full">
                        <thead>
                            <tr>
                                <th>Показатель</th>
                                <?php foreach ($columnsMeta as $column): ?>
                                    <th class="dcf-col-<?php echo htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php foreach ($columnsMeta as $column): ?>
                                        <?php
                                            $value = $row['values'][$column['key']] ?? null;
                                            $formattedValue = '—';
                                            if ($row['format'] === 'money') {
                                                $formattedValue = $formatMoney($value, $row['is_expense'] ?? false);
                                            } elseif ($row['format'] === 'percent') {
                                                $formattedValue = $formatPercent($value, $row['italic'] ?? false);
                                            } elseif ($row['format'] === 'decimal') {
                                                $formattedValue = $formatDecimal($value);
                                            } else {
                                                $formattedValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                                            }
                                            if (!empty($row['star_columns']) && in_array($column['key'], $row['star_columns'], true) && $formattedValue !== '—') {
                                                $formattedValue .= '*';
                                            }
                                        ?>
                                        <td class="dcf-cell-<?php echo htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $formattedValue; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if (!empty($dcfData['footnotes'])): ?>
                        <p class="dcf-footnote">
                            <?php foreach ($dcfData['footnotes'] as $note): ?>
                                <?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?><br>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($evData): ?>
                        <div class="dcf-table-wrapper dcf-table-wrapper--ev">
                        <table class="dcf-table dcf-table--ev">
                            <tbody>
                                <tr>
                                    <td><span class="ev-label-full">Enterprise Value (EV)</span><span class="ev-label-short">EV</span></td>
                                    <td><?php echo $formatEvRow($evData['ev'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td>− Debt</td>
                                    <td>(<?php echo $formatMoney($evData['debt'] ?? 0); ?> млн ₽)</td>
                                </tr>
                                <tr>
                                    <td>+ Cash</td>
                                    <td><?php echo $formatEvRow($evData['cash'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><span class="ev-label-full">Equity Value</span><span class="ev-label-short">Equity</span></strong></td>
                                    <td><strong><?php echo $formatEvRow($evData['equity'] ?? null); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($dcfData['warnings'])): ?>
                        <div class="warnings">
                            <strong>Контрольные замечания:</strong>
                            <ul>
                                <?php foreach ($dcfData['warnings'] as $warning): ?>
                                    <li><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Блок "Определение цены" -->
        <?php if ($latestForm && $dcfData && !isset($dcfData['error']) && !$isStartup): ?>
            <?php
            // Получаем Equity Value из DCF для сравнения
            $dcfEquityValue = null;
            if (isset($dcfData['ev_breakdown']['equity'])) {
                $dcfEquityValue = (float)$dcfData['ev_breakdown']['equity'];
            }
            ?>
            <div class="dcf-card" id="price-determination" style="margin-top: 48px;" data-dcf-equity="<?php echo $dcfEquityValue !== null ? htmlspecialchars($dcfEquityValue, ENT_QUOTES, 'UTF-8') : ''; ?>">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom: 24px;">
                    <div>
                        <h2 style="margin-bottom:4px;">Определение цены</h2>
                        <p style="color: var(--text-secondary); margin-top: 8px; font-size: 14px;">
                            Оценка компании по методу мультипликаторов на основе сектора и финансовых показателей
                        </p>
                    </div>
                    <button 
                        type="button" 
                        class="btn btn-primary" 
                        id="calculate-multiplier-btn"
                        style="padding: 12px 24px; font-size: 16px; font-weight: 600;"
                    >
                        Рассчитать оценку по мультипликаторам
                    </button>
                </div>
                
                <div id="price-determination-content">
                    <div id="multiplier-valuation-progress" style="display: none; margin-top: 24px;">
                        <div style="background: #f0f0f0; border-radius: 4px; height: 4px; overflow: hidden;">
                            <div id="multiplier-progress-bar" style="height: 100%; background: linear-gradient(90deg, #667EEA 0%, #764BA2 100%); width: 0%; transition: width 0.3s ease;"></div>
                        </div>
                        <p style="text-align: center; margin-top: 12px; color: var(--text-secondary);">
                            Определение сектора и расчет оценки...
                        </p>
                    </div>
                    
                    <div id="multiplier-valuation-result" style="display: none; margin-top: 24px;">
                        <!-- Результаты расчета будут отображаться здесь -->
                    </div>
                    
                    <div id="final-price-section" style="display: none; margin-top: 32px; padding-top: 32px; border-top: 2px solid var(--border-color);">
                        <div style="margin-bottom: 24px;">
                            <h3 style="font-size: 20px; font-weight: 700; margin-bottom: 16px; color: var(--text-primary);">
                                Цена предложения Продавца
                            </h3>
                            <p style="color: var(--text-secondary); line-height: 1.6; margin-bottom: 24px;">
                                Оценка компании разными методами может давать различные результаты. DCF-метод учитывает будущие денежные потоки и долгосрочные перспективы развития, в то время как метод мультипликаторов основан на текущих рыночных показателях и сравнении с аналогичными компаниями в отрасли.
                            </p>
                            <div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.08) 100%); border: 2px solid rgba(102, 126, 234, 0.3); border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);">
                                <p style="color: var(--text-primary); line-height: 1.7; margin: 0; font-size: 18px; font-weight: 700; text-align: center;">
                                    Укажите цену предложения Продавца, по которой актив будет выставлен на продажу. Рекомендуем выбрать значение в пределах рассчитанного диапазона оценки.
                                </p>
                            </div>
                        </div>
                        <div style="max-width: 500px;">
                            <label for="final-price-input" style="display: block; margin-bottom: 12px; font-weight: 700; color: var(--text-primary); font-size: 18px;">
                                Цена предложения Продавца (млн ₽)
                            </label>
                            <div style="display: flex; gap: 12px; align-items: flex-start;">
                                <input 
                                    type="number" 
                                    id="final-price-input" 
                                    name="final_price" 
                                    step="0.1" 
                                    min="0"
                                    placeholder="Введите цену предложения Продавца"
                                    style="flex: 1; padding: 18px 24px; border: 2px solid rgba(102, 126, 234, 0.3); border-radius: 12px; font-size: 20px; font-weight: 600; background: white; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1); transition: all 0.3s ease;"
                                    onfocus="this.style.borderColor='rgba(102, 126, 234, 0.6)'; this.style.boxShadow='0 6px 20px rgba(102, 126, 234, 0.2)';"
                                    onblur="this.style.borderColor='rgba(102, 126, 234, 0.3)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.1)';"
                                />
                                <button
                                    type="button"
                                    id="confirm-price-btn"
                                    class="btn btn-primary"
                                    style="padding: 18px 32px; font-size: 16px; font-weight: 600; white-space: nowrap; border-radius: 12px;"
                                >
                                    Подтвердить
                                </button>
                            </div>
                            <div id="final-price-updated-at" style="margin-top: 8px; font-size: 13px; color: var(--text-secondary);">
                                <?php
                                // Отображаем дату и время последнего изменения, если они есть
                                if ($latestForm && !empty($latestForm['data_json'])) {
                                    $formDataJson = json_decode($latestForm['data_json'], true);
                                    if (is_array($formDataJson) && isset($formDataJson['final_price_updated_at'])) {
                                        $updatedAt = $formDataJson['final_price_updated_at'];
                                        $formattedDate = date('d.m.Y H:i', strtotime($updatedAt));
                                        echo 'Последнее изменение: ' . htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8');
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($latestForm): ?>
        <div class="teaser-section" id="teaser-section" data-print-scope="teaser">
            <div class="teaser-header">
                <h2>AI-тизер компании</h2>
                <p>Краткая презентация актива на основе данных анкеты и открытых источников.</p>
                <div class="teaser-actions">
                    <?php if (!$teaserValidation['valid']): ?>
                        <div style="background: linear-gradient(135deg, rgba(255, 193, 7, 0.15) 0%, rgba(255, 152, 0, 0.1) 100%); border: 2px solid rgba(255, 193, 7, 0.4); border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; box-shadow: 0 4px 12px rgba(255, 193, 7, 0.2);">
                            <div style="display: flex; align-items: flex-start; gap: 12px;">
                                <span style="font-size: 24px; flex-shrink: 0;">⚠️</span>
                                <div style="flex: 1;">
                                    <strong style="display: block; margin-bottom: 8px; color: var(--text-primary); font-size: 16px;">Анкета не полностью заполнена</strong>
                                    <p style="margin: 0; color: var(--text-secondary); font-size: 14px; line-height: 1.6;">
                                        Для генерации тизера необходимо заполнить все обязательные поля. 
                                        <a href="seller_form.php?form_id=<?php echo $latestForm['id']; ?>" style="color: #667EEA; text-decoration: underline; font-weight: 600;">Заполните анкету</a>.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" id="generate-teaser-btn" <?php echo (!$teaserValidation['valid'] && !$savedTeaserHtml) ? 'disabled style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>
                        <?php echo $savedTeaserHtml ? 'Обновить тизер' : 'Создать тизер'; ?>
                    </button>
                    <button type="button" class="btn btn-secondary" id="export-teaser-pdf" <?php echo $savedTeaserHtml ? '' : 'disabled'; ?>>
                        Сохранить тизер в PDF
                    </button>
                    <?php if ($savedTeaserHtml && $teaserValidation['valid'] && !empty($latestForm)): ?>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <button type="button" class="btn btn-secondary" id="edit-teaser-btn" style="display: inline-flex;">
                                Редактировать тизер
                            </button>
                            <div id="teaser-edit-controls" style="display: none; gap: 8px; flex-direction: row;">
                                <button type="button" class="btn btn-primary" id="save-teaser-edits-btn" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);">
                                    Сохранить изменения
                                </button>
                                <button type="button" class="btn btn-secondary" id="cancel-teaser-edits-btn">
                                    Отменить
                    </button>
                            </div>
                            <button type="button" class="btn btn-primary" id="submit-teaser-moderation" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%);">
                                Отправить на модерацию
                            </button>
                            <?php if ($teaserModerationInfo): ?>
                                <div id="teaser-moderation-info" style="font-size: 13px; color: var(--text-secondary); line-height: 1.5;">
                                    <?php
                                    $statusLabels = [
                                        'pending' => 'На модерации',
                                        'approved' => 'Утвержден',
                                        'rejected' => 'Отклонен',
                                        'published' => 'Опубликован'
                                    ];
                                    $statusColors = [
                                        'pending' => '#FF9500',
                                        'approved' => '#34C759',
                                        'rejected' => '#FF3B30',
                                        'published' => '#007AFF'
                                    ];
                                    $status = $teaserModerationInfo['moderation_status'];
                                    $statusLabel = $statusLabels[$status] ?? $status;
                                    $statusColor = $statusColors[$status] ?? '#86868B';
                                    
                                    // Определяем дату для отображения
                                    $displayDate = null;
                                    if ($status === 'published' && $teaserModerationInfo['published_at']) {
                                        $displayDate = $teaserModerationInfo['published_at'];
                                    } elseif ($status === 'approved' && $teaserModerationInfo['moderated_at']) {
                                        $displayDate = $teaserModerationInfo['moderated_at'];
                                    } elseif ($status === 'rejected' && $teaserModerationInfo['moderated_at']) {
                                        $displayDate = $teaserModerationInfo['moderated_at'];
                                    } elseif ($teaserModerationInfo['created_at']) {
                                        $displayDate = $teaserModerationInfo['created_at'];
                                    }
                                    
                                    if ($displayDate):
                                        $dateTime = new DateTime($displayDate);
                                        $formattedDate = $dateTime->format('d.m.Y H:i');
                                    ?>
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span style="font-weight: 600;">Статус:</span>
                                            <span style="color: <?php echo $statusColor; ?>; font-weight: 600;"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span style="margin: 0 4px;">•</span>
                                            <span>Отправлено: <?php echo htmlspecialchars($formattedDate, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <?php if ($status === 'rejected' && !empty($teaserModerationInfo['moderation_notes'])): ?>
                                            <div style="margin-top: 4px; padding: 8px 12px; background: rgba(255, 59, 48, 0.1); border-radius: 8px; border-left: 3px solid #FF3B30;">
                                                <strong style="color: #FF3B30; font-size: 12px;">Причина отклонения:</strong>
                                                <div style="color: var(--text-primary); font-size: 12px; margin-top: 4px;"><?php echo htmlspecialchars($teaserModerationInfo['moderation_notes'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div id="teaser-moderation-info" style="font-size: 13px; color: var(--text-secondary); display: none;"></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
                // Создаем промежуточную переменную с маскированными данными для тизера
                // Эта переменная НЕ сохраняется в анкету, используется только для отображения
                $heroCompanyName = null;
                if (!empty($latestForm['data_json'])) {
                    // Подключаем функции для работы с маскированными данными
                    if (!function_exists('buildTeaserPayload') || !function_exists('buildMaskedTeaserPayload')) {
                        define('TEASER_FUNCTIONS_ONLY', true);
                        require_once __DIR__ . '/generate_teaser.php';
                    }
                    
                    // Создаем маскированные данные на лету (не сохраняем в анкету)
                    $formPayload = buildTeaserPayload($latestForm);
                    $maskedPayload = buildMaskedTeaserPayload($formPayload);
                    $heroCompanyName = trim((string)($maskedPayload['asset_name'] ?? ''));
                }
                
                // Если не удалось создать маскированные данные, используем исходное название
                if ($heroCompanyName === null || $heroCompanyName === '') {
                $heroCompanyName = trim((string)($latestForm['asset_name'] ?? ''));
                }
                
                $heroCompanyName = removeMaPlatformPhrase($heroCompanyName);
                if ($heroCompanyName === '') {
                    $heroCompanyName = 'Ваш проект';
                }
                // Используем сгенерированное ИИ описание, если оно есть, иначе берем из анкеты
                if (!empty($savedHeroDescription)) {
                    $heroDescription = $savedHeroDescription;
                } else {
                    $heroDescription = trim((string)($latestForm['company_description'] ?? $latestForm['additional_info'] ?? 'Подготовьте краткое описание, чтобы сделать тизер ещё выразительнее.'));
                    $heroDescription = removeMaPlatformPhrase($heroDescription);
                    if (mb_strlen($heroDescription) > 220) {
                        $heroDescription = mb_substr($heroDescription, 0, 220) . '…';
                    }
                }
                $heroIndustry = removeMaPlatformPhrase(trim((string)($latestForm['products_services'] ?? '')));
                $heroRegion = removeMaPlatformPhrase(trim((string)($latestForm['presence_regions'] ?? '')));
                $heroGoal = removeMaPlatformPhrase(trim((string)($latestForm['deal_goal'] ?? '')));
                // Формируем список элементов для hero блока (4-5 элементов)
                $heroChips = [];
                
                // 1. Сегмент рынка
                if ($heroIndustry !== '') {
                    $heroChips[] = [
                        'label' => 'Сегмент',
                        'value' => $heroIndustry,
                        'icon' => 'segment'
                    ];
                }
                
                // 2. География присутствия
                if ($heroRegion !== '') {
                    $heroChips[] = [
                        'label' => 'Рынки',
                        'value' => $heroRegion,
                        'icon' => 'location'
                    ];
                }
                
                // 3. Персонал
                $personnelCount = trim((string)($latestForm['personnel_count'] ?? ''));
                if ($personnelCount !== '' && $personnelCount !== '0') {
                    $heroChips[] = [
                        'label' => 'Персонал',
                        'value' => $personnelCount . ' чел.',
                        'icon' => 'people'
                    ];
                }
                
                // 4. Бренды
                $companyBrands = removeMaPlatformPhrase(trim((string)($latestForm['company_brands'] ?? '')));
                if ($companyBrands !== '') {
                    // Ограничиваем длину названий брендов
                    if (mb_strlen($companyBrands) > 30) {
                        $companyBrands = mb_substr($companyBrands, 0, 30) . '…';
                    }
                    $heroChips[] = [
                        'label' => 'Бренды',
                        'value' => $companyBrands,
                        'icon' => 'brand'
                    ];
                }
                
                // 5. Доля онлайн продаж
                $onlineShare = trim((string)($latestForm['online_sales_share'] ?? ''));
                if ($onlineShare !== '' && $onlineShare !== '0') {
                    // Убираем '%' из значения, если он там есть, чтобы избежать двойного знака
                    $onlineShare = rtrim($onlineShare, '%');
                    $heroChips[] = [
                        'label' => 'Онлайн',
                        'value' => $onlineShare . '%',
                        'icon' => 'online'
                    ];
                }
                
                // 6. Доля к продаже (если еще не набрали 5 элементов)
                if (count($heroChips) < 5) {
                    $dealShare = trim((string)($latestForm['deal_share_range'] ?? ''));
                    if ($dealShare !== '') {
                        $heroChips[] = [
                            'label' => 'Доля',
                            'value' => $dealShare,
                            'icon' => 'share'
                        ];
                    }
                }
                
                // 7. Цель сделки (если еще не набрали 5 элементов)
                if (count($heroChips) < 5 && $heroGoal !== '') {
                    $heroChips[] = [
                        'label' => 'Цель',
                        'value' => $heroGoal,
                        'icon' => 'goal'
                    ];
                }
                
                // Ограничиваем до 5 элементов максимум
                $heroChips = array_slice($heroChips, 0, 5);
                $heroStats = [];
                if (is_array($dcfData ?? null)) {
                    // Получаем выручку и прибыль P2 из DCF данных
                    $p2Revenue = null;
                    $p2Profit = null;
                    if (!empty($dcfData['rows']) && is_array($dcfData['rows'])) {
                        foreach ($dcfData['rows'] as $row) {
                            if (!isset($row['label']) || !isset($row['values']) || !is_array($row['values'])) {
                                continue;
                            }
                            // Ищем выручку - используем array_key_exists для проверки наличия ключа P2
                            // Это важно, так как isset() вернет false, если значение равно 0
                            if ($row['label'] === 'Выручка') {
                                if (array_key_exists('P2', $row['values'])) {
                                    $val = $row['values']['P2'];
                                    // Берем значение, даже если оно равно 0 (это валидное значение)
                                    // Проверяем только на null, так как 0 - это валидное число
                                    if ($val !== null && $val !== '') {
                                        $p2Revenue = (float)$val;
                                    }
                                }
                            }
                            // Ищем прибыль от продаж
                            if ($row['label'] === 'Прибыль от продаж') {
                                if (array_key_exists('P2', $row['values'])) {
                                    $val = $row['values']['P2'];
                                    // Берем значение, даже если оно равно 0 (это валидное значение)
                                    if ($val !== null && $val !== '') {
                                        $p2Profit = (float)$val;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Добавляем выручку P2 (второй период прогноза)
                    // Показываем значение, даже если оно равно 0 (это валидное значение)
                    if ($p2Revenue !== null) {
                        // Значения в DCF уже хранятся в миллионах рублей
                        // Например, если в таблице показано 4 751, это означает 4 751 млн рублей = 4.751 миллиарда рублей
                        // Поэтому используем значение как есть, без деления
                        $p2RevenueInMillions = $p2Revenue;
                        $heroStats[] = [
                            'label' => 'Выручка 2026П',
                            'value' => number_format($p2RevenueInMillions, 0, '.', ' ') . ' млн ₽',
                            'caption' => 'прогноз на 2026',
                        ];
                    }
                    
                    // Добавляем прибыль от продаж P2 в процентах (маржинальность = Прибыль / Выручка * 100%)
                    if ($p2Profit !== null && $p2Revenue !== null && $p2Revenue != 0) {
                        // Рассчитываем маржинальность в процентах
                        $marginPercent = ($p2Profit / $p2Revenue) * 100;
                        $heroStats[] = [
                            'label' => 'Маржинальность',
                            'value' => number_format($marginPercent, 1, '.', ' ') . '%',
                            'caption' => '2026П (Прибыль/Выручка)',
                        ];
                    }
                    
                    // Заменяем Temp (темп роста на горизонте 5 лет) на рост текущего года (P2) по сравнению с предыдущим (P1)
                    $p1Revenue = null;
                    $p2RevenueForGrowth = null;
                    if (!empty($dcfData['rows']) && is_array($dcfData['rows'])) {
                        foreach ($dcfData['rows'] as $row) {
                            if (isset($row['label']) && $row['label'] === 'Выручка') {
                                if (isset($row['values']['P1']) && $row['values']['P1'] !== null) {
                                    $p1Revenue = (float)$row['values']['P1'];
                                }
                                if (isset($row['values']['P2']) && $row['values']['P2'] !== null) {
                                    $p2RevenueForGrowth = (float)$row['values']['P2'];
                                }
                                break;
                            }
                        }
                    }
                    if ($p1Revenue !== null && $p2RevenueForGrowth !== null && $p1Revenue != 0) {
                        $currentYearGrowth = (($p2RevenueForGrowth - $p1Revenue) / $p1Revenue) * 100;
                        $heroStats[] = [
                            'label' => 'Темп роста',
                            'value' => number_format($currentYearGrowth, 1, '.', ' ') . '%',
                            'caption' => '2026П к 2025П',
                        ];
                    }
                    
                    // Добавляем цену: приоритет финальной цене продажи, если она указана
                    $finalPrice = null;
                    if (!empty($latestForm['data_json'])) {
                        $formDataJson = json_decode($latestForm['data_json'], true);
                        if (is_array($formDataJson) && isset($formDataJson['final_price']) && $formDataJson['final_price'] > 0) {
                            $finalPrice = (float)$formDataJson['final_price'];
                        }
                    }
                    
                    if ($finalPrice !== null && $finalPrice > 0) {
                        // Используем финальную цену продажи
                        $heroStats[] = [
                            'label' => 'Цена',
                            'value' => number_format($finalPrice, 0, '.', ' ') . ' млн ₽',
                            'caption' => 'Цена предложения Продавца',
                        ];
                    } elseif (!empty($dcfData['ev_breakdown']['ev'])) {
                        // Если финальная цена не указана, используем EV
                        $evValue = (float)$dcfData['ev_breakdown']['ev'];
                        $heroStats[] = [
                            'label' => 'Цена',
                            'value' => number_format($evValue, 0, '.', ' ') . ' млн ₽',
                            'caption' => 'Enterprise Value',
                        ];
                    }
                    
                    if (!empty($dcfData['ev_breakdown']['equity'])) {
                        $heroEquityValue = (float)$dcfData['ev_breakdown']['equity'];
                        $heroStats[] = [
                            'label' => 'Equity Value',
                            'value' => number_format($heroEquityValue, 0, '.', ' ') . ' млн ₽',
                            'caption' => 'оценка бизнеса',
                        ];
                    }
                }
                $heroRevenueValue = null;
                if (!empty($latestForm['financial_results'])) {
                    $heroFinancial = json_decode($latestForm['financial_results'], true);
                    if (is_array($heroFinancial) && isset($heroFinancial['revenue']['2024_fact']) && $heroFinancial['revenue']['2024_fact'] !== '') {
                        $cleanRevenue = preg_replace('/[^0-9\.\-]/', '', (string)$heroFinancial['revenue']['2024_fact']);
                        if ($cleanRevenue !== '' && is_numeric($cleanRevenue)) {
                            $heroRevenueValue = (float)$cleanRevenue;
                        }
                    }
                }
                if ($heroRevenueValue !== null) {
                    $heroStats[] = [
                        'label' => 'Выручка 2024',
                        'value' => number_format($heroRevenueValue, 0, '.', ' ') . ' млн ₽',
                        'caption' => 'по данным анкеты',
                    ];
                }
                // Фильтруем только те элементы, у которых значение действительно пустое (null, '', false)
                // Но оставляем значения "0", "0.0" и отрицательные, так как они валидны
                // Увеличиваем лимит до 4, чтобы поместился элемент "Цена"
                $heroStats = array_slice(array_filter($heroStats, function($item) {
                    return isset($item['value']) && $item['value'] !== '' && $item['value'] !== null;
                }), 0, 4);
                $teaserStatusText = $savedTeaserTimestamp
                    ? 'Тизер обновлён: ' . date('d.m.Y H:i', strtotime($savedTeaserTimestamp))
                    : 'Нажмите «Создать тизер», чтобы подготовить актуальную версию.';
            ?>
            <?php
            // Скрываем верхний hero блок, если есть сохраненный тизер (он уже содержит hero блок)
            // Показываем только если тизер еще не создан
            if (!$savedTeaserHtml): ?>
            <div class="teaser-hero">
                <div class="teaser-hero__content">
                    <h3><?php echo htmlspecialchars($heroCompanyName, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <p class="teaser-hero__description"><?php echo htmlspecialchars($heroDescription, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php if (!empty($heroChips)): ?>
                        <div class="teaser-hero__tags">
                            <?php foreach ($heroChips as $chip): ?>
                                <span class="teaser-chip" data-icon="<?php echo htmlspecialchars($chip['icon'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="teaser-chip__icon">
                                        <?php
                                        $iconType = $chip['icon'] ?? 'default';
                                        echo getTeaserChipIcon($iconType);
                                        ?>
                                    </span>
                                    <span class="teaser-chip__content">
                                        <span class="teaser-chip__label"><?php echo htmlspecialchars($chip['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <strong class="teaser-chip__value"><?php echo htmlspecialchars($chip['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($heroStats)): ?>
                    <div class="teaser-hero__stats">
                        <?php foreach ($heroStats as $stat): ?>
                            <div class="teaser-stat" <?php if ($stat['label'] === 'Цена'): ?>id="hero-price-stat"<?php endif; ?>>
                                <span><?php echo htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <strong id="<?php echo $stat['label'] === 'Цена' ? 'hero-price-value' : ''; ?>"><?php echo htmlspecialchars($stat['value'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($stat['caption'])): ?>
                                    <small id="<?php echo $stat['label'] === 'Цена' ? 'hero-price-caption' : ''; ?>"><?php echo htmlspecialchars($stat['caption'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="teaser-hero__status">
                    <div class="teaser-status">
                        <?php echo htmlspecialchars($teaserStatusText, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
                <!-- Статус тизера (всегда видимый, даже когда hero блок скрыт) -->
                <div class="teaser-status" id="teaser-status" style="margin-top: 16px; margin-bottom: 16px; padding: 12px; background: rgba(99,102,241,0.05); border-radius: 8px; font-size: 14px; color: var(--text-secondary);">
                    <?php 
                    if ($savedTeaserHtml && $savedTeaserTimestamp) {
                        echo 'Тизер обновлён: ' . date('d.m.Y H:i', strtotime($savedTeaserTimestamp));
                    } elseif ($savedTeaserHtml) {
                        echo 'Тизер создан';
                    } else {
                        echo 'Нажмите «Создать тизер», чтобы подготовить актуальную версию.';
                    }
                    ?>
            </div>
                <div class="teaser-progress" id="teaser-progress" aria-hidden="true">
                    <div class="teaser-progress__bar" id="teaser-progress-bar"></div>
                </div>
                <div class="teaser-result" id="teaser-result">
                    <?php if ($savedTeaserHtml): ?>
                        <?php echo $savedTeaserHtml; ?>
                    <?php else: ?>
                        <p style="color: var(--text-secondary); margin: 0;">Нажмите «Создать тизер», чтобы получить структурированный документ для инвесторов.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="teaser-section teaser-section--investors" id="investors-section" data-print-exclude>
                <div class="teaser-header">
                    <h2>Возможные инвесторы</h2>
                    <p>Подбор релевантных инвесторов на основе каталога SmartBizSell и AI-рекомендаций.</p>
                </div>
                <div
                    class="investor-controls"
                    id="investor-controls"
                    data-has-teaser="<?php echo $savedTeaserHtml ? '1' : '0'; ?>"
                    data-has-investors="<?php echo $savedInvestorHtml ? '1' : '0'; ?>"
                    style="<?php echo ($savedTeaserHtml || $savedInvestorHtml) ? '' : 'display:none;'; ?>"
                >
                    <button
                        type="button"
                        class="btn btn-primary btn-investor"
                        id="generate-investors-btn"
                        <?php echo $savedTeaserHtml ? '' : 'disabled'; ?>
                    >
                        Найти инвестора
                    </button>
                    <div class="investor-status" id="investor-status">
                        <?php if ($savedInvestorTimestamp): ?>
                            Последний подбор: <?php echo date('d.m.Y H:i', strtotime($savedInvestorTimestamp)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="investor-progress" id="investor-progress" aria-hidden="true">
                        <div class="investor-progress__bar" id="investor-progress-bar"></div>
                    </div>
                </div>
                <div class="investor-result" id="investor-result">
                    <?php if ($savedInvestorHtml): ?>
                        <?php echo $savedInvestorHtml; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Раздел Term Sheet -->
            <div class="teaser-section" id="term-sheet-section" data-print-scope="term-sheet">
                <div class="teaser-header">
                    <h2>Term Sheet</h2>
                    <p>Создайте инвестиционный меморандум с ключевыми условиями сделки для согласования с инвестором.</p>
                </div>
                
                <?php 
                // Проверяем, есть ли отправленная анкета Term Sheet для генерации через ИИ
                $hasSubmittedTermSheet = false;
                foreach ($termSheets as $ts) {
                    if (in_array($ts['status'] ?? '', ['submitted', 'review', 'approved'], true)) {
                        $hasSubmittedTermSheet = true;
                        break;
                    }
                }
                ?>
                
                <?php if ($hasSubmittedTermSheet): ?>
                    <div class="term-sheet-controls" style="margin-top: 24px; margin-bottom: 24px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                        <button
                            type="button"
                            class="btn btn-primary"
                            id="generate-term-sheet-btn"
                            style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); padding: 14px 32px; font-size: 16px; font-weight: 600;"
                        >
                            🤖 Создать Term Sheet через ИИ
                        </button>
                        <?php
                        // Проверяем, есть ли уже сгенерированный Term Sheet для кнопки скачивания
                        $stmt = $pdo->prepare("
                            SELECT data_json 
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
                        $effectiveUserId = getEffectiveUserId();
                        $stmt->execute([$effectiveUserId]);
                        $hasGeneratedTermSheet = $stmt->fetch();
                        ?>
                        <?php if ($hasGeneratedTermSheet): ?>
                            <a
                                href="term_sheet_word.php"
                                class="btn btn-secondary"
                                style="padding: 14px 32px; font-size: 16px; font-weight: 600; text-decoration: none; display: inline-block; touch-action: manipulation; -webkit-tap-highlight-color: rgba(108, 117, 125, 0.3); cursor: pointer; position: relative; z-index: 1; user-select: none; -webkit-user-select: none;"
                                download
                            >
                                📄 Скачать Word
                            </a>
                        <?php endif; ?>
                        <div class="term-sheet-progress" id="term-sheet-progress" aria-hidden="true" style="display: none; margin-top: 16px; width: 100%;">
                            <div class="term-sheet-progress__bar" id="term-sheet-progress-bar" style="height: 4px; background: #e9ecef; border-radius: 2px; overflow: hidden;">
                                <div style="height: 100%; background: linear-gradient(90deg, #10B981 0%, #059669 100%); width: 0%; transition: width 0.3s ease;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="term-sheet-result" id="term-sheet-result" style="width: 100%; max-width: 100%;">
                        <?php
                        // Проверяем, есть ли уже сгенерированный Term Sheet через ИИ
                        $stmt = $pdo->prepare("
                            SELECT data_json 
                            FROM term_sheet_forms 
                            WHERE user_id = ? 
                              AND status IN ('submitted','review','approved')
                              AND data_json IS NOT NULL
                              AND JSON_EXTRACT(data_json, '$.generated_document.html') IS NOT NULL
                            ORDER BY submitted_at DESC, updated_at DESC 
                            LIMIT 1
                        ");
                        $effectiveUserId = getEffectiveUserId();
                        $stmt->execute([$effectiveUserId]);
                        $aiTermSheet = $stmt->fetch();
                        if ($aiTermSheet && !empty($aiTermSheet['data_json'])) {
                            $termSheetData = json_decode($aiTermSheet['data_json'], true);
                            if (!empty($termSheetData['generated_document']['html'])) {
                                echo $termSheetData['generated_document']['html'];
                            }
                        }
                        ?>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($termSheets)): ?>
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(5, 150, 105, 0.03) 100%); border: 2px solid rgba(16, 185, 129, 0.15); border-radius: 20px; padding: 32px; margin-top: 24px;">
                        <div style="max-width: 800px; margin: 0 auto;">
                            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 16px; color: var(--text-primary);">Что такое Term Sheet?</h3>
                            <div style="color: var(--text-secondary); line-height: 1.8; font-size: 16px; margin-bottom: 24px;">
                                <p style="margin-bottom: 16px;">
                                    <strong>Term Sheet</strong> (лист условий сделки) — это документ, который содержит основные условия инвестиционной сделки между продавцом и инвестором. Он служит основой для дальнейшей проработки деталей и подготовки окончательных юридических документов.
                                </p>
                                <p style="margin-bottom: 16px;">
                                    Term Sheet помогает сторонам:
                                </p>
                                <ul style="margin-left: 24px; margin-bottom: 16px;">
                                    <li>Закрепить ключевые параметры сделки (оценка, структура, условия)</li>
                                    <li>Согласовать основные условия до детальной проработки</li>
                                    <li>Ускорить процесс переговоров и принятия решений</li>
                                    <li>Создать прозрачную основу для дальнейшей работы</li>
                                </ul>
                                <p>
                                    После заполнения анкеты мы подготовим профессиональный Term Sheet на основе ваших данных и лучших практик M&A сделок.
                                </p>
                            </div>
                            
                            <div style="text-align: center; margin-top: 32px;">
                                <a href="term_sheet_form.php" class="btn btn-primary" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); padding: 14px 32px; font-size: 16px; font-weight: 600;">
                                    <span>Создать Term Sheet</span>
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="margin-left: 8px; vertical-align: middle;">
                                        <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="forms-table" style="margin-top: 24px;">
                        <div class="table-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Мои Term Sheet</span>
                            <a href="term_sheet_form.php" class="btn btn-primary" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); padding: 10px 20px; font-size: 14px; font-weight: 600;">
                                + Создать новый
                            </a>
                        </div>
                        
                        <div style="padding: 0;">
                            <?php foreach ($termSheets as $ts): ?>
                                <div class="table-row">
                                    <div>
                                        <strong><?php echo htmlspecialchars($ts['asset_name'] ?: 'Без названия актива', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                                            <?php if ($ts['buyer_name']): ?>
                                                Покупатель: <?php echo htmlspecialchars($ts['buyer_name'], ENT_QUOTES, 'UTF-8'); ?><br>
                                            <?php endif; ?>
                                            <?php if ($ts['seller_name']): ?>
                                                Продавец: <?php echo htmlspecialchars($ts['seller_name'], ENT_QUOTES, 'UTF-8'); ?><br>
                                            <?php endif; ?>
                                            Создан: <?php echo date('d.m.Y H:i', strtotime($ts['created_at'])); ?>
                                            <?php if ($ts['updated_at'] && $ts['updated_at'] !== $ts['created_at']): ?>
                                                | Обновлен: <?php echo date('d.m.Y H:i', strtotime($ts['updated_at'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <?php 
                                        // Определяем статус с учетом NULL и пустых значений
                                        $tsStatus = !empty($ts['status']) ? $ts['status'] : 'draft';
                                        $statusLabel = $statusLabels[$tsStatus] ?? 'Черновик';
                                        $statusColor = $statusColors[$tsStatus] ?? '#86868B';
                                        ?>
                                        <span style="padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>;">
                                            <?php echo $statusLabel; ?>
                                        </span>
                                        <a href="term_sheet_form.php?form_id=<?php echo $ts['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 14px;">
                                            Редактировать
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="teaser-section">
                <div class="teaser-header">
                    <h2>AI-тизер компании</h2>
                    <p>Отправьте анкету, чтобы автоматически сформировать тизер.</p>
                </div>
            </div>
            
            <!-- Раздел Term Sheet (для пользователей без отправленной анкеты) -->
            <div class="teaser-section" id="term-sheet-section" data-print-scope="term-sheet">
                <div class="teaser-header">
                    <h2>Term Sheet</h2>
                    <p>Создайте инвестиционный меморандум с ключевыми условиями сделки для согласования с инвестором.</p>
                </div>
                
                <?php if (empty($termSheets)): ?>
                    <div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(5, 150, 105, 0.03) 100%); border: 2px solid rgba(16, 185, 129, 0.15); border-radius: 20px; padding: 32px; margin-top: 24px;">
                        <div style="max-width: 800px; margin: 0 auto;">
                            <h3 style="font-size: 24px; font-weight: 700; margin-bottom: 16px; color: var(--text-primary);">Что такое Term Sheet?</h3>
                            <div style="color: var(--text-secondary); line-height: 1.8; font-size: 16px; margin-bottom: 24px;">
                                <p style="margin-bottom: 16px;">
                                    <strong>Term Sheet</strong> (лист условий сделки) — это документ, который содержит основные условия инвестиционной сделки между продавцом и инвестором. Он служит основой для дальнейшей проработки деталей и подготовки окончательных юридических документов.
                                </p>
                                <p style="margin-bottom: 16px;">
                                    Term Sheet помогает сторонам:
                                </p>
                                <ul style="margin-left: 24px; margin-bottom: 16px;">
                                    <li>Закрепить ключевые параметры сделки (оценка, структура, условия)</li>
                                    <li>Согласовать основные условия до детальной проработки</li>
                                    <li>Ускорить процесс переговоров и принятия решений</li>
                                    <li>Создать прозрачную основу для дальнейшей работы</li>
                                </ul>
                                <p>
                                    После заполнения анкеты мы подготовим профессиональный Term Sheet на основе ваших данных и лучших практик M&A сделок.
                                </p>
                            </div>
                            
                            <div style="text-align: center; margin-top: 32px;">
                                <a href="term_sheet_form.php" class="btn btn-primary" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); padding: 14px 32px; font-size: 16px; font-weight: 600;">
                                    <span>Создать Term Sheet</span>
                                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="margin-left: 8px; vertical-align: middle;">
                                        <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="forms-table" style="margin-top: 24px;">
                        <div class="table-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Мои Term Sheet</span>
                            <a href="term_sheet_form.php" class="btn btn-primary" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); padding: 10px 20px; font-size: 14px; font-weight: 600;">
                                + Создать новый
                            </a>
                        </div>
                        
                        <div style="padding: 0;">
                            <?php foreach ($termSheets as $ts): ?>
                                <div class="table-row">
                                    <div>
                                        <strong><?php echo htmlspecialchars($ts['asset_name'] ?: 'Без названия актива', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                                            <?php if ($ts['buyer_name']): ?>
                                                Покупатель: <?php echo htmlspecialchars($ts['buyer_name'], ENT_QUOTES, 'UTF-8'); ?><br>
                                            <?php endif; ?>
                                            <?php if ($ts['seller_name']): ?>
                                                Продавец: <?php echo htmlspecialchars($ts['seller_name'], ENT_QUOTES, 'UTF-8'); ?><br>
                                            <?php endif; ?>
                                            Создан: <?php echo date('d.m.Y H:i', strtotime($ts['created_at'])); ?>
                                            <?php if ($ts['updated_at'] && $ts['updated_at'] !== $ts['created_at']): ?>
                                                | Обновлен: <?php echo date('d.m.Y H:i', strtotime($ts['updated_at'])); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <?php 
                                        // Определяем статус с учетом NULL и пустых значений
                                        $tsStatus = !empty($ts['status']) ? $ts['status'] : 'draft';
                                        $statusLabel = $statusLabels[$tsStatus] ?? 'Черновик';
                                        $statusColor = $statusColors[$tsStatus] ?? '#86868B';
                                        ?>
                                        <span style="padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>;">
                                            <?php echo $statusLabel; ?>
                                        </span>
                                        <a href="term_sheet_form.php?form_id=<?php echo $ts['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 14px;">
                                            Редактировать
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        /**
         * Переключение между анкетами
         * Обновляет URL с параметром form_id и перезагружает страницу
         */
        function switchForm(formId) {
            const url = new URL(window.location.href);
            url.searchParams.set('form_id', formId);
            window.location.href = url.toString();
        }
        
        // Сохраняем текущий form_id для использования в других функциях
        const currentFormId = <?php echo $selectedForm ? $selectedForm['id'] : 'null'; ?>;
        
        /**
         * Навигация между блоками личного кабинета
         * 
         * Функциональность:
         * - Плавная прокрутка к выбранному блоку
         * - Подсветка активного пункта меню при прокрутке
         * - Обновление активного пункта при клике
         * 
         * Создано: 2025-01-XX
         */
        (function() {
            const nav = document.getElementById('dashboard-nav');
            if (!nav) return;
            
            const navLinks = nav.querySelectorAll('.dashboard-nav__link');
            const sections = {};
            
            // Собираем все секции
            navLinks.forEach(link => {
                const sectionId = link.getAttribute('data-section');
                if (sectionId) {
                    const section = document.getElementById(sectionId);
                    if (section) {
                        sections[sectionId] = {
                            element: section,
                            link: link
                        };
                    }
                }
            });
            
            // Плавная прокрутка при клике
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const sectionId = this.getAttribute('data-section');
                    const section = sections[sectionId];
                    if (section) {
                        // Убираем активный класс со всех ссылок
                        navLinks.forEach(l => l.classList.remove('active'));
                        // Добавляем активный класс к текущей ссылке
                        this.classList.add('active');
                        
                        // Плавная прокрутка с учетом высоты навигации
                        const navHeight = nav.offsetHeight;
                        const sectionTop = section.element.offsetTop - navHeight - 20;
                        
                        window.scrollTo({
                            top: sectionTop,
                            behavior: 'smooth'
                        });
                    }
                });
            });
            
            // Обработка кликов по кнопкам в dashboard-actions с якорями (для Term Sheet)
            document.querySelectorAll('.dashboard-actions a[href^="#"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    if (href && href.startsWith('#')) {
                        const targetId = href.substring(1);
                        const target = document.getElementById(targetId);
                        if (target) {
                            const navHeight = nav ? nav.offsetHeight : 0;
                            const offset = navHeight + 20;
                            const targetPosition = target.getBoundingClientRect().top + window.pageYOffset - offset;
                            
                            window.scrollTo({
                                top: targetPosition,
                                behavior: 'smooth'
                            });
                            
                            // Обновляем активный пункт навигации, если он есть
                            const navLink = nav.querySelector(`[data-section="${targetId}"]`);
                            if (navLink) {
                                navLinks.forEach(l => l.classList.remove('active'));
                                navLink.classList.add('active');
                            }
                        }
                    }
                });
            });
            
            // Определение активного пункта меню при прокрутке
            const updateActiveNav = () => {
                const scrollPos = window.scrollY + nav.offsetHeight + 100;
                
                let activeSection = null;
                let maxTop = -Infinity;
                
                Object.keys(sections).forEach(sectionId => {
                    const section = sections[sectionId];
                    const sectionTop = section.element.offsetTop;
                    const sectionHeight = section.element.offsetHeight;
                    const sectionBottom = sectionTop + sectionHeight;
                    
                    // Если секция видна на экране
                    if (scrollPos >= sectionTop && scrollPos <= sectionBottom) {
                        if (sectionTop > maxTop) {
                            maxTop = sectionTop;
                            activeSection = sectionId;
                        }
                    }
                });
                
                // Если ни одна секция не видна, проверяем какая ближе всего
                if (!activeSection) {
                    Object.keys(sections).forEach(sectionId => {
                        const section = sections[sectionId];
                        const sectionTop = section.element.offsetTop;
                        if (scrollPos >= sectionTop && sectionTop > maxTop) {
                            maxTop = sectionTop;
                            activeSection = sectionId;
                        }
                    });
                }
                
                // Обновляем активный класс
                navLinks.forEach(link => {
                    const sectionId = link.getAttribute('data-section');
                    if (sectionId === activeSection) {
                        link.classList.add('active');
                    } else {
                        link.classList.remove('active');
                    }
                });
            };
            
            // Обновление при прокрутке (с throttling для производительности)
            let ticking = false;
            window.addEventListener('scroll', () => {
                if (!ticking) {
                    window.requestAnimationFrame(() => {
                        updateActiveNav();
                        ticking = false;
                    });
                    ticking = true;
                }
            });
            
            // Инициализация при загрузке
            updateActiveNav();
            
            /**
             * Показ навигации на мобильных устройствах
             * 
             * Функциональность:
             * - Навигация видна сразу после анкеты на мобильных (sticky позиционирование)
             * - Навигация всегда видна на десктопе
             * 
             * Создано: 2025-01-XX
             * Обновлено: 2025-01-XX - навигация теперь всегда видна на мобильных
             */
            const showNavOnScroll = () => {
                // Навигация теперь всегда видна (sticky позиционирование на мобильных)
                // Просто добавляем класс nav-visible для совместимости
                nav.classList.add('nav-visible');
            };
            
            // Инициализируем показ навигации
            showNavOnScroll();
        })();
        (() => {
            let teaserProgressTimer = null;
            let investorProgressTimer = null;
            let termSheetProgressTimer = null;
            let investorCtaBound = false;

            const getTeaserElements = () => ({
                teaserBtn: document.getElementById('generate-teaser-btn'),
                teaserStatus: document.getElementById('teaser-status'),
                teaserResult: document.getElementById('teaser-result'),
                teaserPrintBtn: document.getElementById('export-teaser-pdf'),
                teaserSubmitModerationBtn: document.getElementById('submit-teaser-moderation'),
                teaserSection: document.getElementById('teaser-section'),
                teaserProgress: document.getElementById('teaser-progress'),
                teaserProgressBar: document.getElementById('teaser-progress-bar'),
                investorBtn: document.getElementById('generate-investors-btn'),
                investorStatus: document.getElementById('investor-status'),
                investorResult: document.getElementById('investor-result'),
                investorControls: document.getElementById('investor-controls'),
                investorProgress: document.getElementById('investor-progress'),
                investorProgressBar: document.getElementById('investor-progress-bar'),
                termSheetBtn: document.getElementById('generate-term-sheet-btn'),
                termSheetResult: document.getElementById('term-sheet-result'),
                termSheetProgress: document.getElementById('term-sheet-progress'),
                termSheetProgressBar: document.getElementById('term-sheet-progress-bar'),
                editTeaserBtn: document.getElementById('edit-teaser-btn'),
                saveTeaserEditsBtn: document.getElementById('save-teaser-edits-btn'),
                cancelTeaserEditsBtn: document.getElementById('cancel-teaser-edits-btn'),
                teaserEditControls: document.getElementById('teaser-edit-controls'),
            });

            /**
             * Форматирует ISO дату/время в русский формат.
             * 
             * @param {string} isoString ISO строка даты/времени (например, '2025-01-15T10:30:00Z')
             * @returns {string|null} Отформатированная строка в формате 'ДД.ММ.ГГГГ, ЧЧ:ММ' или null при ошибке
             */
            const formatRuDateTime = (isoString) => {
                if (!isoString) {
                    return null;
                }
                const date = new Date(isoString);
                if (Number.isNaN(date.getTime())) {
                    return null;
                }
                return date.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                });
            };

            /**
             * Показывает прогресс-бар генерации тизера с анимацией.
             * Прогресс увеличивается случайным образом до 85%, чтобы создать эффект реальной работы.
             * 
             * @param {Object} elements Объект с элементами DOM (teaserProgress, teaserProgressBar)
             */
            const showTeaserProgress = (elements) => {
                const { teaserProgress, teaserProgressBar } = elements;
                if (!teaserProgress || !teaserProgressBar) {
                    console.warn('Teaser progress elements not found');
                    return;
                }
                // Убеждаемся, что прогресс-бар виден
                teaserProgress.style.display = 'block';
                teaserProgress.setAttribute('aria-hidden', 'false');
                teaserProgress.classList.add('is-visible');
                teaserProgressBar.style.width = '0%';
                let current = 0;
                clearInterval(teaserProgressTimer);
                teaserProgressTimer = setInterval(() => {
                    const increment = Math.random() * 7 + 3;
                    current = Math.min(current + increment, 85);
                    teaserProgressBar.style.width = current.toFixed(1) + '%';
                    if (current >= 85) {
                        clearInterval(teaserProgressTimer);
                    }
                }, 400);
            };

            /**
             * Завершает отображение прогресс-бара генерации тизера.
             * При успехе устанавливает прогресс на 100% и скрывает через 700мс.
             * При ошибке сразу скрывает прогресс-бар.
             * 
             * @param {Object} elements Объект с элементами DOM (teaserProgress, teaserProgressBar)
             * @param {boolean} success true при успешной генерации, false при ошибке
             */
            const completeTeaserProgress = (elements, success = true) => {
                const { teaserProgress, teaserProgressBar } = elements;
                if (!teaserProgress || !teaserProgressBar) {
                    return;
                }
                clearInterval(teaserProgressTimer);
                teaserProgressBar.style.width = success ? '100%' : '0%';
                setTimeout(() => {
                    teaserProgress.classList.remove('is-visible');
                    teaserProgress.setAttribute('aria-hidden', 'true');
                    teaserProgressBar.style.width = '0%';
                    // Скрываем прогресс-бар только после анимации
                    setTimeout(() => {
                        teaserProgress.style.display = 'none';
                    }, 300);
                }, success ? 700 : 0);
            };

            /**
             * Показывает прогресс-бар подбора инвесторов с анимацией.
             * Прогресс увеличивается случайным образом до 85%.
             * 
             * @param {Object} elements Объект с элементами DOM (investorProgress, investorProgressBar)
             */
            const showInvestorProgress = (elements) => {
                const { investorProgress, investorProgressBar } = elements;
                if (!investorProgress || !investorProgressBar) {
                    return;
                }
                investorProgress.setAttribute('aria-hidden', 'false');
                investorProgress.classList.add('is-visible');
                investorProgressBar.style.width = '0%';
                let current = 0;
                clearInterval(investorProgressTimer);
                investorProgressTimer = setInterval(() => {
                    const increment = Math.random() * 8 + 4;
                    current = Math.min(current + increment, 85);
                    investorProgressBar.style.width = current.toFixed(1) + '%';
                    if (current >= 85) {
                        clearInterval(investorProgressTimer);
                    }
                }, 350);
            };

            /**
             * Завершает отображение прогресс-бара подбора инвесторов.
             * При успехе устанавливает прогресс на 100% и скрывает через 600мс.
             * При ошибке сразу скрывает прогресс-бар.
             * 
             * @param {Object} elements Объект с элементами DOM (investorProgress, investorProgressBar)
             * @param {boolean} success true при успешном подборе, false при ошибке
             */
            const completeInvestorProgress = (elements, success = true) => {
                const { investorProgress, investorProgressBar } = elements;
                if (!investorProgress || !investorProgressBar) {
                    return;
                }
                clearInterval(investorProgressTimer);
                investorProgressBar.style.width = success ? '100%' : '0%';
                setTimeout(() => {
                    investorProgress.classList.remove('is-visible');
                    investorProgress.setAttribute('aria-hidden', 'true');
                    investorProgressBar.style.width = '0%';
                }, success ? 600 : 0);
            };

            /**
             * Обработчик нажатия кнопки "Сохранить тизер в PDF"
             * 
             * Функциональность:
             * - Открывает отдельную страницу teaser_pdf.php в новом окне
             * - Страница автоматически оптимизирована для печати на A4
             * - Графики инициализируются и автоматически запускается печать
             * 
             * Создано: 2025-01-XX
             */
            const handleTeaserPrint = () => {
                const { teaserPrintBtn } = getTeaserElements();
                if (!teaserPrintBtn || teaserPrintBtn.disabled) {
                    return;
                }
                // Открытие PDF страницы в новом окне
                window.open('teaser_pdf.php', '_blank');
            };

            /**
             * Обработчик генерации/обновления тизера.
             * 
             * Функциональность:
             * - Отправляет AJAX запрос на generate_teaser.php
             * - Показывает прогресс-бар во время генерации
             * - Обновляет HTML тизера в DOM
             * - Инициализирует графики ApexCharts
             * - Обрабатывает ошибки и восстанавливает состояние при неудаче
             * 
             * @async
             */
            const handleTeaserGenerate = async () => {
                const elements = getTeaserElements();
                const {
                    teaserBtn,
                    teaserStatus,
                    teaserResult,
                    teaserPrintBtn,
                    investorBtn,
                    investorStatus,
                    investorResult,
                    investorControls,
                } = elements;
                if (!teaserBtn || !teaserStatus || !teaserResult) {
                    console.error('Teaser elements not found');
                    return;
                }
                
                // Проверяем, что form_id указан
                if (!currentFormId) {
                    console.error('Form ID is not set');
                    teaserStatus.textContent = 'Ошибка: не выбран актив. Выберите актив из вкладок выше.';
                    return;
                }
                
                let teaserGenerated = false;
                teaserBtn.disabled = true;
                teaserStatus.innerHTML = '<span class="teaser-spinner">Генерируем тизер...</span>';
                teaserResult.style.opacity = '0.6';
                if (investorBtn) {
                    investorBtn.style.display = 'none';
                    investorBtn.disabled = true;
                }
                const previousInvestorHtml = investorResult ? investorResult.innerHTML : '';
                const hadInvestors = !!(investorControls && investorControls.dataset.hasInvestors === '1');
                if (investorControls) {
                    investorControls.style.display = 'none';
                    investorControls.dataset.hasInvestors = '0';
                }
                if (investorResult) {
                    investorResult.innerHTML = '';
                }
                if (investorStatus) {
                    investorStatus.textContent = '';
                }
                showTeaserProgress(elements);

                try {
                    const response = await fetch('generate_teaser.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ 
                            action: 'teaser',
                            form_id: currentFormId 
                        }),
                    });

                    // Проверяем, что ответ является валидным JSON
                    let payload;
                    const responseText = await response.text();
                    try {
                        payload = JSON.parse(responseText);
                    } catch (jsonError) {
                        console.error('Failed to parse JSON response:', jsonError);
                        console.error('Response text:', responseText.substring(0, 500));
                        throw new Error('Ошибка при обработке ответа от сервера. Попробуйте снова.');
                    }
                    
                    if (!response.ok || !payload.success) {
                        // Формируем детальное сообщение об ошибке
                        let errorMessage = payload.message || 'Не удалось создать тизер.';
                        if (payload.error) {
                            errorMessage += ' Детали: ' + payload.error;
                            if (payload.file && payload.line) {
                                errorMessage += ' (файл: ' + payload.file + ', строка: ' + payload.line + ')';
                            }
                        }
                        console.error('Teaser generation error:', payload);
                        throw new Error(errorMessage);
                    }

                    // Destroy existing charts before inserting new HTML
                    const existingCharts = document.querySelectorAll('.teaser-chart[data-chart-ready="1"]');
                    existingCharts.forEach((container) => {
                        const chartId = container.id || container.getAttribute('data-chart-id');
                        if (chartId && window.ApexCharts) {
                            const chart = ApexCharts.exec(chartId);
                            if (chart) {
                                chart.destroy();
                            }
                        }
                    });
                    
                    teaserResult.innerHTML = payload.html;
                    initTeaserCharts();
                    const formatted = formatRuDateTime(payload.generated_at);
                    teaserStatus.textContent = formatted
                        ? `Тизер обновлён: ${formatted}`
                        : 'Готово! Тизер сформирован.';
                    teaserBtn.textContent = 'Обновить тизер';
                    if (teaserPrintBtn) {
                        teaserPrintBtn.disabled = false;
                    }
                    if (investorBtn) {
                        investorBtn.style.display = 'inline-flex';
                        investorBtn.disabled = false;
                    }
                    if (investorControls) {
                        investorControls.style.display = 'flex';
                        investorControls.dataset.hasTeaser = '1';
                        investorControls.dataset.hasInvestors = '0';
                    }
                    teaserGenerated = true;
                    completeTeaserProgress(elements, true);
                    
                    // Перезагружаем страницу после успешной генерации, чтобы обновить PHP переменные
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } catch (error) {
                    console.error('Teaser generation failed', error);
                    console.error('Error details:', error);
                    // Показываем детальное сообщение об ошибке
                    let errorText = error.message || 'Ошибка генерации тизера.';
                    // Если это ошибка парсинга JSON, показываем больше информации
                    if (error.message && error.message.includes('parse JSON')) {
                        errorText += ' Проверьте консоль для деталей.';
                    }
                    teaserStatus.textContent = errorText;
                    teaserStatus.style.color = '#FF3B30';
                    completeTeaserProgress(elements, false);
                } finally {
                    teaserBtn.disabled = false;
                    teaserResult.style.opacity = '1';
                    if (!teaserStatus.textContent) {
                        teaserStatus.textContent = 'Не удалось получить статус обновления.';
                    }
                    if (!teaserGenerated && hadInvestors && investorControls) {
                        investorControls.style.display = 'flex';
                        investorControls.dataset.hasInvestors = '1';
                    }
                    if (!teaserGenerated && hadInvestors && investorBtn) {
                        investorBtn.style.display = 'inline-flex';
                        investorBtn.disabled = false;
                    }
                    if (!teaserGenerated && hadInvestors && investorResult && previousInvestorHtml) {
                        investorResult.innerHTML = previousInvestorHtml;
                    }
                    completeInvestorProgress(elements, false);
                }
            };

            /**
             * Показывает прогресс-бар генерации Term Sheet с анимацией.
             */
            const showTermSheetProgress = (elements) => {
                const { termSheetProgress, termSheetProgressBar } = elements;
                if (!termSheetProgress || !termSheetProgressBar) {
                    return;
                }
                termSheetProgress.setAttribute('aria-hidden', 'false');
                termSheetProgress.style.display = 'block';
                const bar = termSheetProgressBar.querySelector('div');
                if (bar) {
                    bar.style.width = '0%';
                }
                
                clearInterval(termSheetProgressTimer);
                let current = 0;
                termSheetProgressTimer = setInterval(() => {
                    current += Math.random() * 15;
                    if (current > 85) {
                        current = 85;
                    }
                    if (bar) {
                        bar.style.width = current.toFixed(1) + '%';
                    }
                }, 200);
            };

            /**
             * Завершает прогресс-бар генерации Term Sheet.
             */
            const completeTermSheetProgress = (elements, success = true) => {
                const { termSheetProgress, termSheetProgressBar } = elements;
                if (!termSheetProgress || !termSheetProgressBar) {
                    return;
                }
                clearInterval(termSheetProgressTimer);
                const bar = termSheetProgressBar.querySelector('div');
                if (bar) {
                    bar.style.width = success ? '100%' : '0%';
                }
                setTimeout(() => {
                    termSheetProgress.classList.remove('is-visible');
                    termSheetProgress.setAttribute('aria-hidden', 'true');
                    termSheetProgress.style.display = 'none';
                    if (bar) {
                        bar.style.width = '0%';
                    }
                }, 500);
            };

            /**
             * Обработчик генерации Term Sheet через ИИ.
             */
            const handleTermSheetGenerate = async () => {
                const elements = getTeaserElements();
                const { termSheetBtn, termSheetResult } = elements;
                if (!termSheetBtn || !termSheetResult) {
                    return;
                }
                
                termSheetBtn.disabled = true;
                termSheetBtn.textContent = 'Генерируем...';
                showTermSheetProgress(elements);
                
                try {
                    const response = await fetch('generate_term_sheet.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        let errorMessage = payload.message || 'Не удалось создать Term Sheet.';
                        
                        // Если есть информация о недостающих полях, добавляем ссылку на редактирование
                        if (payload.missing_fields && payload.missing_fields.length > 0) {
                            errorMessage += '\n\nПожалуйста, заполните недостающие поля в анкете Term Sheet.';
                            // Показываем сообщение с возможностью перейти к редактированию
                            if (confirm(errorMessage + '\n\nПерейти к редактированию анкеты?')) {
                                window.location.href = 'term_sheet_form.php';
                            }
                        } else {
                            alert(errorMessage);
                        }
                        throw new Error(errorMessage);
                    }

                    termSheetResult.innerHTML = payload.html;
                    termSheetBtn.textContent = 'Обновить Term Sheet';
                    completeTermSheetProgress(elements, true);
                    
                    // Показываем кнопку скачивания Word после успешной генерации
                    const downloadWordBtn = document.querySelector('a[href="term_sheet_word.php"]');
                    if (downloadWordBtn) {
                        downloadWordBtn.style.display = 'inline-block';
                    } else {
                        // Создаем кнопку, если её нет
                        const controls = document.querySelector('.term-sheet-controls');
                        if (controls) {
                            const newBtn = document.createElement('a');
                            newBtn.href = 'term_sheet_word.php';
                            newBtn.className = 'btn btn-secondary';
                            // Используем стили, совместимые с мобильными устройствами
                            newBtn.style.cssText = 'padding: 14px 32px; font-size: 16px; font-weight: 600; text-decoration: none; display: inline-block; touch-action: manipulation; -webkit-tap-highlight-color: rgba(108, 117, 125, 0.3); cursor: pointer; position: relative; z-index: 1;';
                            newBtn.textContent = '📄 Скачать Word';
                            newBtn.download = '';
                            // Добавляем обработчик для мобильных устройств
                            newBtn.addEventListener('touchstart', function(e) {
                                e.stopPropagation();
                            }, { passive: true });
                            newBtn.addEventListener('click', function(e) {
                                e.stopPropagation();
                            });
                            termSheetBtn.parentNode.insertBefore(newBtn, termSheetBtn.nextSibling);
                        }
                    }
                } catch (error) {
                    console.error('Term Sheet generation failed', error);
                    if (!error.message.includes('Перейти к редактированию')) {
                        alert(error.message || 'Ошибка генерации Term Sheet.');
                    }
                    completeTermSheetProgress(elements, false);
                } finally {
                    termSheetBtn.disabled = false;
                    if (termSheetBtn.textContent === 'Генерируем...') {
                        termSheetBtn.textContent = '🤖 Создать Term Sheet через ИИ';
                    }
                }
            };

            // Обработчик кнопки генерации Term Sheet
            const termSheetBtn = document.getElementById('generate-term-sheet-btn');
            if (termSheetBtn) {
                // Улучшаем touch-обработку для мобильных устройств
                termSheetBtn.style.touchAction = 'manipulation';
                termSheetBtn.style.webkitTapHighlightColor = 'rgba(16, 185, 129, 0.3)';
                termSheetBtn.style.cursor = 'pointer';
                termSheetBtn.style.userSelect = 'none';
                termSheetBtn.style.webkitUserSelect = 'none';
                
                // Используем один обработчик click, который работает и на мобильных
                termSheetBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!termSheetBtn.disabled) {
                        handleTermSheetGenerate(e);
                    }
                });
            }

            /**
             * Обработчик подбора инвесторов.
             * 
             * Функциональность:
             * - Отправляет AJAX запрос на generate_teaser.php с action='investors'
             * - Показывает прогресс-бар во время подбора
             * - Обновляет HTML списка инвесторов в DOM
             * - Обрабатывает ошибки и восстанавливает предыдущее состояние при неудаче
             * 
             * @async
             */
            const handleInvestorGenerate = async () => {
                const { investorBtn, investorStatus, investorResult, investorControls } = getTeaserElements();
                if (!investorBtn || !investorStatus || !investorResult) {
                    return;
                }
                const previousInvestorHtml = investorResult.innerHTML;
                investorBtn.disabled = true;
                investorStatus.textContent = 'Подбираем релевантных инвесторов...';
                investorResult.innerHTML = '';
                if (investorControls) {
                    investorControls.dataset.hasInvestors = '0';
                }
                showInvestorProgress(getTeaserElements());
                try {
                    const response = await fetch('generate_teaser.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ 
                            action: 'investors',
                            form_id: currentFormId 
                        }),
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'Не удалось подобрать инвесторов.');
                    }
                    investorResult.innerHTML = payload.html;
                    const formatted = formatRuDateTime(payload.generated_at);
                    investorStatus.textContent = formatted
                        ? `Последний подбор: ${formatted}`
                        : 'Подбор инвесторов обновлён.';
                    if (investorControls) {
                        investorControls.dataset.hasInvestors = '1';
                    }
                    completeInvestorProgress(getTeaserElements(), true);
                } catch (error) {
                    console.error('Investor search failed', error);
                    investorStatus.textContent = error.message || 'Ошибка подбора инвесторов.';
                    if (investorControls) {
                        investorControls.dataset.hasInvestors = '0';
                    }
                    completeInvestorProgress(getTeaserElements(), false);
                    investorResult.innerHTML = previousInvestorHtml;
                    if (previousInvestorHtml && investorControls) {
                        investorControls.dataset.hasInvestors = '1';
                    }
                } finally {
                    investorBtn.disabled = false;
                }
            };

            /**
             * Обработчик отправки тизера инвестору.
             * 
             * При клике на кнопку "Отправить тизер" рядом с инвестором:
             * - Блокирует кнопку и меняет текст на "Отправлено"
             * - Показывает сообщение в статусе тизера
             * - Восстанавливает кнопку через 3.2 секунды
             * 
             * @param {Event} event Событие клика
             */
            const handleInvestorSend = (event) => {
                const button = event.target.closest('.btn-investor-send');
                if (!button) {
                    return;
                }
                event.preventDefault();
                if (button.disabled) {
                    return;
                }
                const investorName = button.dataset.investor || 'инвестор';
                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = 'Отправлено';
                button.classList.add('is-sent');
                const { teaserStatus } = getTeaserElements();
                if (teaserStatus) {
                    teaserStatus.textContent = `Мы подготовим отправку тизера для инвестора ${investorName}.`;
                }
                setTimeout(() => {
                    button.disabled = false;
                    button.textContent = originalText;
                    button.classList.remove('is-sent');
                }, 3200);
            };

            /**
             * Инициализация функционала экспорта DCF модели в PDF.
             *
             * Требование пользователя: "Нужно просто сохранить то, что на экране"
             * Поэтому экспортируем прямо видимый блок DCF без повторного пересчёта на сервере.
             * Используем те же библиотеки, что и для AI тизера: html2canvas + jsPDF.
             * Формат: A4 Landscape, одна страница, масштаб 2x для качества.
             *
             * Создано: 2025-01-XX
             */
            const initDcfPrint = () => {
                const exportBtn = document.getElementById('export-dcf-pdf');
                const dcfCard = document.getElementById('dcf-model');
                if (!exportBtn || !dcfCard) {
                    return;
                }

                // Утилита для динамической загрузки скриптов, если они ещё не подключены
                const loadScript = (src) => new Promise((resolve, reject) => {
                    const exists = Array.from(document.scripts).some((s) => s.src === src);
                    if (exists) {
                        resolve();
                        return;
                    }
                    const script = document.createElement('script');
                    script.src = src;
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });

                // Загружаем зависимости html2canvas/jsPDF только при первом клике
                const ensurePdfDeps = async () => {
                    if (typeof html2canvas === 'undefined') {
                        await loadScript('https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
                    }
                    if (!window.jspdf) {
                        await loadScript('https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js');
                    }
                };

                const exportToPdf = async () => {
                    try {
                    exportBtn.disabled = true;
                        exportBtn.textContent = 'Формируем PDF...';

                        await ensurePdfDeps();

                        // Снимаем "скриншот" видимого блока DCF в high-DPI для качества
                        const canvas = await html2canvas(dcfCard, {
                            scale: 2,
                            useCORS: true,
                            backgroundColor: '#ffffff',
                            windowWidth: dcfCard.scrollWidth,
                            windowHeight: dcfCard.scrollHeight,
                        });

                        const imgData = canvas.toDataURL('image/png');
                        const pdf = new window.jspdf.jsPDF('l', 'mm', 'a4'); // Landscape
                        const pageWidth = pdf.internal.pageSize.getWidth();   // 297 mm
                        const pageHeight = pdf.internal.pageSize.getHeight(); // 210 mm

                        // Подгоняем пропорции, чтобы всё уместилось на одной странице
                        let imgWidth = pageWidth;
                        let imgHeight = (canvas.height * imgWidth) / canvas.width;

                        if (imgHeight > pageHeight) {
                            const scale = pageHeight / imgHeight;
                            imgWidth *= scale;
                            imgHeight = pageHeight;
                        }

                        pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                        pdf.save('dcf-model.pdf');
                    } catch (error) {
                        console.error('Error exporting DCF PDF:', error);
                        alert('Не удалось сформировать PDF. Попробуйте ещё раз.');
                    } finally {
                        exportBtn.disabled = false;
                        exportBtn.textContent = 'Сохранить DCF в PDF';
                    }
                };

                exportBtn.addEventListener('click', exportToPdf);
            };

            /**
             * Инициализация обработчика кнопки печати тизера в PDF
             * 
             * Функциональность:
             * - Находит кнопку "Сохранить тизер в PDF" в DOM
             * - Привязывает обработчик клика, который открывает teaser_pdf.php
             * 
             * Создано: 2025-01-XX
             */
            const initTeaserPrint = () => {
                const { teaserPrintBtn, teaserSection } = getTeaserElements();
                if (!teaserPrintBtn || !teaserSection) {
                    return;
                }
                teaserPrintBtn.addEventListener('click', handleTeaserPrint);
            };

            /**
             * Инициализация обработчика кнопки генерации/обновления тизера
             * 
             * Функциональность:
             * - Находит кнопку "Создать тизер" / "Обновить тизер" в DOM
             * - Привязывает обработчик клика, который отправляет AJAX запрос на generate_teaser.php
             * 
             * Создано: 2025-01-XX
             */
            /**
             * Обработчик отправки тизера на модерацию
             */
            const handleTeaserSubmitModeration = async () => {
                const { teaserSubmitModerationBtn } = getTeaserElements();
                if (!teaserSubmitModerationBtn || teaserSubmitModerationBtn.disabled) {
                    return;
                }
                
                if (!confirm('Отправить тизер на модерацию? После проверки модератором тизер будет опубликован на главной странице.')) {
                    return;
                }
                
                const originalText = teaserSubmitModerationBtn.textContent;
                teaserSubmitModerationBtn.disabled = true;
                teaserSubmitModerationBtn.textContent = 'Отправка...';
                
                try {
                    const response = await fetch('submit_teaser_moderation.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ 
                            form_id: currentFormId 
                        }),
                    });
                    
                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'Не удалось отправить тизер на модерацию.');
                    }
                    
                    // Показываем успешное сообщение
                    teaserSubmitModerationBtn.textContent = '✓ Отправлено';
                    teaserSubmitModerationBtn.style.background = 'linear-gradient(135deg, #10B981 0%, #059669 100%)';
                    
                    // Обновляем информацию о модерации
                    updateModerationInfo('pending', new Date().toISOString());
                    
                    setTimeout(() => {
                        teaserSubmitModerationBtn.textContent = originalText;
                        teaserSubmitModerationBtn.style.background = '';
                        teaserSubmitModerationBtn.disabled = false;
                        // Перезагружаем страницу для обновления данных
                        window.location.reload();
                    }, 2000);
                    
                } catch (error) {
                    console.error('Error submitting teaser for moderation:', error);
                    alert('Ошибка: ' + error.message);
                    teaserSubmitModerationBtn.disabled = false;
                    teaserSubmitModerationBtn.textContent = originalText;
                }
            };

            /**
             * Обновляет информацию о модерации тизера
             */
            const updateModerationInfo = (status, date) => {
                const infoDiv = document.getElementById('teaser-moderation-info');
                if (!infoDiv) return;
                
                const statusLabels = {
                    'pending': 'На модерации',
                    'approved': 'Утвержден',
                    'rejected': 'Отклонен',
                    'published': 'Опубликован'
                };
                const statusColors = {
                    'pending': '#FF9500',
                    'approved': '#34C759',
                    'rejected': '#FF3B30',
                    'published': '#007AFF'
                };
                
                const statusLabel = statusLabels[status] || status;
                const statusColor = statusColors[status] || '#86868B';
                
                const dateObj = new Date(date);
                const formattedDate = dateObj.toLocaleString('ru-RU', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                infoDiv.style.display = 'block';
                infoDiv.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <span style="font-weight: 600;">Статус:</span>
                        <span style="color: ${statusColor}; font-weight: 600;">${statusLabel}</span>
                        <span style="margin: 0 4px;">•</span>
                        <span>Отправлено: ${formattedDate}</span>
                    </div>
                `;
            };

            /**
             * Режим редактирования тизера
             */
            let isTeaserEditMode = false;
            let originalTeaserHtml = null;
            let editableElements = [];

            /**
             * Включает режим редактирования тизера
             */
            const enableTeaserEditMode = () => {
                const elements = getTeaserElements();
                const { teaserResult, editTeaserBtn, teaserEditControls, saveTeaserEditsBtn, cancelTeaserEditsBtn, teaserSubmitModerationBtn } = elements;
                
                if (!teaserResult) {
                    console.error('Teaser result element not found');
                    return;
                }

                // Сохраняем оригинальный HTML
                originalTeaserHtml = teaserResult.innerHTML;
                isTeaserEditMode = true;

                // Находим все редактируемые элементы
                editableElements = [];
                
                // Hero блок - описание
                const heroDescription = teaserResult.querySelector('.teaser-hero__description');
                if (heroDescription) {
                    heroDescription.contentEditable = 'true';
                    heroDescription.classList.add('teaser-editable');
                    editableElements.push(heroDescription);
                }

                // Карточки - параграфы, списки, футеры
                // Исключаем карточки с графиками из редактирования
                const cardParagraphs = teaserResult.querySelectorAll('.teaser-card:not(.teaser-chart-card) p:not(.teaser-card__subtitle):not(.teaser-chart__note)');
                cardParagraphs.forEach(p => {
                    // Проверяем, что элемент не находится внутри контейнера графика
                    if (!p.closest('.teaser-chart')) {
                        p.contentEditable = 'true';
                        p.classList.add('teaser-editable');
                        editableElements.push(p);
                    }
                });

                const cardListItems = teaserResult.querySelectorAll('.teaser-card:not(.teaser-chart-card) li');
                cardListItems.forEach(li => {
                    // Проверяем, что элемент не находится внутри контейнера графика
                    if (!li.closest('.teaser-chart')) {
                        li.contentEditable = 'true';
                        li.classList.add('teaser-editable');
                        editableElements.push(li);
                    }
                });

                const cardFooters = teaserResult.querySelectorAll('.teaser-card__footer');
                cardFooters.forEach(footer => {
                    // Проверяем, что элемент не находится внутри контейнера графика
                    if (!footer.closest('.teaser-chart-card')) {
                        footer.contentEditable = 'true';
                        footer.classList.add('teaser-editable');
                        editableElements.push(footer);
                    }
                });

                // Показываем кнопки сохранения/отмены
                if (editTeaserBtn) editTeaserBtn.style.display = 'none';
                if (teaserEditControls) teaserEditControls.style.display = 'flex';
                if (teaserSubmitModerationBtn) teaserSubmitModerationBtn.disabled = true;

                // Добавляем визуальные индикаторы
                teaserResult.classList.add('teaser-edit-mode');
            };

            /**
             * Выключает режим редактирования тизера
             */
            const disableTeaserEditMode = (restoreOriginal = false) => {
                const elements = getTeaserElements();
                const { teaserResult, editTeaserBtn, teaserEditControls, teaserSubmitModerationBtn } = elements;
                
                isTeaserEditMode = false;

                // Убираем contentEditable
                editableElements.forEach(el => {
                    el.contentEditable = 'false';
                    el.classList.remove('teaser-editable');
                });
                editableElements = [];

                // Восстанавливаем оригинальный HTML, если нужно
                if (restoreOriginal && originalTeaserHtml && teaserResult) {
                    teaserResult.innerHTML = originalTeaserHtml;
                    // Переинициализируем графики, если они были
                    if (typeof initTeaserCharts === 'function') {
                        setTimeout(() => {
                            initTeaserCharts();
                        }, 100);
                    }
                }
                // Примечание: переинициализацию графиков при сохранении делаем в saveTeaserEdits,
                // чтобы избежать конфликтов и множественных инициализаций

                // Скрываем кнопки сохранения/отмены
                if (editTeaserBtn) editTeaserBtn.style.display = 'inline-flex';
                if (teaserEditControls) teaserEditControls.style.display = 'none';
                if (teaserSubmitModerationBtn) teaserSubmitModerationBtn.disabled = false;

                // Убираем визуальные индикаторы
                if (teaserResult) teaserResult.classList.remove('teaser-edit-mode');

                originalTeaserHtml = null;
            };

            /**
             * Сохраняет отредактированный HTML тизера
             */
            const saveTeaserEdits = async () => {
                const elements = getTeaserElements();
                const { teaserResult, saveTeaserEditsBtn } = elements;
                
                if (!teaserResult || !currentFormId) {
                    alert('Ошибка: не удалось сохранить изменения.');
                    return;
                }

                // Клонируем элемент, чтобы не изменять оригинал
                const clonedResult = teaserResult.cloneNode(true);
                
                // Очищаем контейнеры графиков от SVG элементов, оставляя только data-chart атрибут
                // Это необходимо, чтобы графики правильно переинициализировались после сохранения
                const chartContainers = clonedResult.querySelectorAll('.teaser-chart[data-chart]');
                chartContainers.forEach(container => {
                    // Сохраняем data-chart атрибут
                    const chartData = container.getAttribute('data-chart');
                    const chartId = container.getAttribute('data-chart-id');
                    const containerId = container.id;
                    
                    // Очищаем контейнер от SVG элементов, созданных ApexCharts
                    container.innerHTML = '';
                    
                    // Восстанавливаем атрибуты
                    if (chartData) {
                        container.setAttribute('data-chart', chartData);
                    }
                    if (chartId) {
                        container.setAttribute('data-chart-id', chartId);
                    }
                    if (containerId) {
                        container.id = containerId;
                    }
                    
                    // Удаляем data-chart-ready, чтобы график переинициализировался
                    container.removeAttribute('data-chart-ready');
                });

                // Собираем HTML из клонированного элемента
                const editedHtml = clonedResult.innerHTML;

                // Валидация: проверяем, что есть контент
                if (!editedHtml || editedHtml.trim().length === 0) {
                    alert('Ошибка: тизер не может быть пустым.');
                    return;
                }

                const originalText = saveTeaserEditsBtn ? saveTeaserEditsBtn.textContent : 'Сохранить';
                if (saveTeaserEditsBtn) {
                    saveTeaserEditsBtn.disabled = true;
                    saveTeaserEditsBtn.textContent = 'Сохранение...';
                }

                try {
                    const response = await fetch('save_teaser_edits.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            form_id: currentFormId,
                            html: editedHtml
                        }),
                    });

                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || 'Не удалось сохранить изменения.');
                    }

                    // Показываем успешное сообщение
                    if (saveTeaserEditsBtn) {
                        saveTeaserEditsBtn.textContent = '✓ Сохранено';
                        saveTeaserEditsBtn.style.background = 'linear-gradient(135deg, #10B981 0%, #059669 100%)';
                    }

                    // Выходим из режима редактирования
                    disableTeaserEditMode(false);

                    // Обновляем оригинальный HTML для следующего редактирования
                    originalTeaserHtml = teaserResult.innerHTML;

                    // Переинициализируем графики после сохранения
                    // Сначала уничтожаем существующие графики, затем создаем новые
                    const reinitCharts = () => {
                        if (typeof ApexCharts === 'undefined' || typeof initTeaserCharts !== 'function') {
                            console.warn('ApexCharts or initTeaserCharts not available');
                            return;
                        }
                        
                        const containers = teaserResult.querySelectorAll('.teaser-chart[data-chart]');
                        if (containers.length === 0) {
                            console.warn('No chart containers found for reinitialization');
                            return;
                        }
                        
                        console.log('Reinitializing', containers.length, 'chart(s)');
                        
                        // Сначала уничтожаем все существующие графики
                        containers.forEach(container => {
                            const chartId = container.id || container.getAttribute('data-chart-id');
                            if (chartId) {
                                const existingChart = ApexCharts.exec(chartId);
                                if (existingChart) {
                                    existingChart.destroy();
                                }
                            }
                            
                            // Очищаем контейнер
                            container.innerHTML = '';
                            
                            // Удаляем data-chart-ready для переинициализации
                            container.removeAttribute('data-chart-ready');
                            
                            // Убеждаемся, что data-chart атрибут сохранен
                            if (!container.getAttribute('data-chart')) {
                                console.error('Chart container missing data-chart attribute after save');
                            }
                        });
                        
                        // Затем инициализируем графики заново
                        initTeaserCharts();
                    };
                    
                    // Первая попытка через 300ms
                    setTimeout(reinitCharts, 300);
                    
                    // Вторая попытка через 800ms (на случай, если первая не сработала)
                    setTimeout(reinitCharts, 800);
                    
                    // Третья попытка через 1500ms (последняя попытка)
                    setTimeout(reinitCharts, 1500);

                    // Восстанавливаем кнопку
                    setTimeout(() => {
                        if (saveTeaserEditsBtn) {
                            saveTeaserEditsBtn.textContent = originalText;
                            saveTeaserEditsBtn.style.background = '';
                            saveTeaserEditsBtn.disabled = false;
                        }
                    }, 1500);

                } catch (error) {
                    console.error('Error saving teaser edits:', error);
                    alert('Ошибка: ' + error.message);
                    if (saveTeaserEditsBtn) {
                        saveTeaserEditsBtn.disabled = false;
                        saveTeaserEditsBtn.textContent = originalText;
                    }
                }
            };

            const initTeaserGenerator = () => {
                const elements = getTeaserElements();
                const { teaserBtn, teaserStatus, teaserResult, teaserSubmitModerationBtn } = elements;
                
                // Проверяем, что все необходимые элементы найдены
                if (!teaserBtn) {
                    console.error('Teaser button not found in DOM');
                    return;
                }
                if (!teaserStatus) {
                    console.error('Teaser status element not found in DOM');
                    return;
                }
                if (!teaserResult) {
                    console.error('Teaser result element not found in DOM');
                    return;
                }
                
                // Убеждаемся, что кнопка не disabled (если тизер уже создан, кнопка должна работать)
                const btnText = teaserBtn.textContent.trim();
                if (btnText === 'Обновить тизер') {
                    // Разблокируем кнопку, если тизер уже создан
                    teaserBtn.disabled = false;
                    teaserBtn.removeAttribute('disabled');
                    // Убираем inline стили, которые могут блокировать кнопку
                    teaserBtn.style.opacity = '1';
                    teaserBtn.style.cursor = 'pointer';
                    teaserBtn.style.pointerEvents = 'auto';
                }
                
                // Удаляем все предыдущие обработчики, создавая новую кнопку
                const newBtn = teaserBtn.cloneNode(true);
                teaserBtn.parentNode.replaceChild(newBtn, teaserBtn);
                
                // Привязываем обработчик к новой кнопке
                newBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('Teaser button clicked, form_id:', currentFormId);
                    if (newBtn.disabled) {
                        console.warn('Button is disabled, cannot generate teaser');
                        return;
                    }
                    handleTeaserGenerate();
                });
                
                if (teaserSubmitModerationBtn) {
                    teaserSubmitModerationBtn.addEventListener('click', handleTeaserSubmitModeration);
                }

                // Обработчики для редактирования тизера
                const { editTeaserBtn, saveTeaserEditsBtn, cancelTeaserEditsBtn } = elements;
                if (editTeaserBtn) {
                    editTeaserBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        enableTeaserEditMode();
                    });
                }
                if (saveTeaserEditsBtn) {
                    saveTeaserEditsBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        saveTeaserEdits();
                    });
                }
                if (cancelTeaserEditsBtn) {
                    cancelTeaserEditsBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (confirm('Отменить все изменения? Все несохраненные правки будут потеряны.')) {
                            disableTeaserEditMode(true);
                        }
                    });
                }
            };

            /**
             * Инициализация ApexCharts для отображения финансовых графиков в тизере
             * 
             * Функциональность:
             * - Находит все контейнеры с атрибутом data-chart
             * - Парсит JSON данные графика из атрибута data-chart
             * - Создает уникальный ID для каждого графика
             * - Проверяет, не был ли график уже отрендерен
             * - Создает и рендерит график с настройками для обычного просмотра или печати
             * - Использует градиентную заливку и плавные кривые для красивого отображения
             * - Легенда уменьшена в два раза для компактного размещения внутри графика
             * 
             * Создано: 2025-01-XX
             */
            const initTeaserCharts = () => {
                if (typeof ApexCharts === 'undefined') {
                    console.warn('ApexCharts is not available.');
                    return;
                }
                // Поиск всех контейнеров для графиков
                const containers = document.querySelectorAll('.teaser-chart[data-chart]');
                if (!containers.length) {
                    return;
                }
                containers.forEach((container, index) => {
                    // Очистка контейнера от предыдущего содержимого
                    container.innerHTML = '';
                    
                    // Генерация уникального ID для графика, если его нет
                    if (!container.id) {
                        container.id = 'teaser-chart-' + Date.now() + '-' + index;
                    }
                    const chartId = container.id;
                    
                    // Проверка, не был ли график уже отрендерен
                    if (container.dataset.chartReady === '1') {
                        const existingChart = ApexCharts.exec(chartId);
                        if (existingChart && document.body.classList.contains('print-teaser')) {
                            // Обновление существующего графика для режима печати
                            existingChart.updateOptions({
                                chart: { height: 75 },
                                legend: { fontSize: '6px', offsetY: -6 },
                                xaxis: { labels: { style: { fontSize: '5px' } } },
                                yaxis: { labels: { style: { fontSize: '5px' } } }
                            }, false, true);
                        }
                        return;
                    }
                    
                    // Парсинг JSON данных графика из атрибута data-chart
                    let payload;
                    try {
                        payload = JSON.parse(container.getAttribute('data-chart') || '{}');
                    } catch (error) {
                        console.error('Chart payload parse error', error);
                        return;
                    }
                    if (!payload || !Array.isArray(payload.series) || payload.series.length === 0) {
                        return;
                    }
                    // Определение режима отображения (обычный или печать)
                    const isPrintMode = document.body.classList.contains('print-teaser');
                    const options = {
                        chart: {
                            id: chartId,
                            type: 'line',
                            height: isPrintMode ? 75 : 300,
                            parentHeightOffset: isPrintMode ? 0 : 10,
                            toolbar: { show: false },
                            fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
                        },
                        colors: payload.colors || ['#6366F1', '#0EA5E9', '#F97316', '#10B981'],
                        series: payload.series,
                        stroke: {
                            width: 3,
                            curve: 'smooth',
                        },
                        markers: {
                            size: 4,
                            strokeWidth: 2,
                            hover: { size: 7 },
                        },
                        dataLabels: { enabled: false },
                        grid: {
                            strokeDashArray: 5,
                            borderColor: 'rgba(15,23,42,0.08)',
                        },
                        xaxis: {
                            categories: payload.categories || [],
                            labels: {
                                style: {
                                    colors: 'rgba(71,85,105,0.9)',
                                    fontSize: isPrintMode ? '6px' : '12px',
                                },
                            },
                            axisBorder: { show: false },
                            axisTicks: { show: false },
                        },
                        yaxis: {
                            labels: {
                                style: {
                                    colors: 'rgba(71,85,105,0.9)',
                                    fontSize: isPrintMode ? '6px' : '12px',
                                },
                                formatter: (value) => {
                                    if (value === null || value === undefined) {
                                        return '';
                                    }
                                    const unit = payload.unit || '';
                                    return `${Math.round(value).toLocaleString('ru-RU')} ${unit}`.trim();
                                },
                            },
                        },
                        legend: {
                            position: 'top',
                            horizontalAlign: 'left',
                            // Легенда уменьшена в два раза для компактного размещения внутри графика
                            fontSize: isPrintMode ? '7px' : '6px',
                            offsetY: isPrintMode ? -4 : -5,
                            offsetX: 0,
                            markers: { width: isPrintMode ? 6 : 5, height: isPrintMode ? 6 : 5, radius: 2 },
                            itemMargin: {
                                horizontal: isPrintMode ? 6 : 6,
                                vertical: 0,
                            },
                        },
                        tooltip: {
                            theme: 'light',
                            y: {
                                formatter: (value) => {
                                    if (value === null || value === undefined) {
                                        return '—';
                                    }
                                    const unit = payload.unit || '';
                                    return `${value.toLocaleString('ru-RU', { maximumFractionDigits: 1 })} ${unit}`.trim();
                                },
                            },
                        },
                        fill: {
                            type: 'gradient',
                            gradient: {
                                shadeIntensity: 0.3,
                                opacityFrom: 0.8,
                                opacityTo: 0.1,
                                stops: [0, 90, 100],
                            },
                        },
                    };
                    // Ensure container is empty and ready
                    container.innerHTML = '';
                    container.style.minHeight = isPrintMode ? '75px' : '260px';
                    
                    const chart = new ApexCharts(container, options);
                    chart.render().then(() => {
                        container.dataset.chartReady = '1';
                        container.setAttribute('data-chart-id', chartId);
                        // Ensure no text content remains
                        const textNodes = Array.from(container.childNodes).filter(node => 
                            node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== ''
                        );
                        textNodes.forEach(node => node.remove());
                    }).catch((error) => {
                        console.error('Chart render error:', error);
                        container.innerHTML = '<p style="font-size: 10px; color: #999; text-align: center; padding: 20px;">График временно недоступен</p>';
                    });
                });
            };

            const initInvestorGenerator = () => {
                const { investorBtn } = getTeaserElements();
                if (!investorBtn) {
                    return;
                }
                investorBtn.addEventListener('click', handleInvestorGenerate);
            };

            const initInvestorCtas = () => {
                if (investorCtaBound) {
                    return;
                }
                document.addEventListener('click', handleInvestorSend);
                investorCtaBound = true;
            };

            document.addEventListener('DOMContentLoaded', () => {
                try {
                    initDcfPrint();
                } catch (error) {
                    console.error('DCF print init failed', error);
                }

                try {
                    initTeaserPrint();
                } catch (error) {
                    console.error('Teaser print init failed', error);
                }

                try {
                    initTeaserGenerator();
                } catch (error) {
                    console.error('Teaser generator init failed', error);
                    const { teaserStatus } = getTeaserElements();
                    if (teaserStatus) {
                        teaserStatus.textContent = 'Не удалось инициализировать управление тизером.';
                    }
                }

                try {
                    initTeaserCharts();
                } catch (error) {
                    console.error('Teaser charts init failed', error);
                }

                try {
                    initInvestorGenerator();
                } catch (error) {
                    console.error('Investor generator init failed', error);
                }

                try {
                    initInvestorCtas();
                } catch (error) {
                    console.error('Investor CTA init failed', error);
                }

                // Инициализация расчета оценки по мультипликаторам
                try {
                    initMultiplierValuation();
                } catch (error) {
                    console.error('Multiplier valuation init failed', error);
                }
            });

            /**
             * Обработчик расчета оценки по мультипликаторам
             * 
             * Функциональность:
             * - Отправляет AJAX запрос на calculate_multiplier_valuation.php
             * - Показывает прогресс-бар во время расчета
             * - Отображает результаты расчета (сектор, мультипликаторы, стоимость)
             * - Обрабатывает ошибки
             * 
             * @async
             */
            const handleMultiplierValuation = async () => {
                const calculateBtn = document.getElementById('calculate-multiplier-btn');
                const resultDiv = document.getElementById('multiplier-valuation-result');
                const progressDiv = document.getElementById('multiplier-valuation-progress');
                const progressBar = document.getElementById('multiplier-progress-bar');
                const sellerPriceInput = document.getElementById('seller-price-input');
                
                if (!calculateBtn || !resultDiv || !progressDiv || !progressBar) {
                    return;
                }
                
                // Блокируем кнопку и показываем прогресс
                calculateBtn.disabled = true;
                calculateBtn.textContent = 'Рассчитываем...';
                resultDiv.style.display = 'none';
                progressDiv.style.display = 'block';
                progressBar.style.width = '0%';
                
                // Анимация прогресс-бара
                let progress = 0;
                const progressInterval = setInterval(() => {
                    progress += Math.random() * 15;
                    if (progress > 90) progress = 90;
                    progressBar.style.width = progress + '%';
                }, 200);
                
                try {
                    const response = await fetch('calculate_multiplier_valuation.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ 
                            form_id: currentFormId 
                        }),
                    });
                    
                    const payload = await response.json();
                    
                    // Завершаем прогресс-бар
                    clearInterval(progressInterval);
                    progressBar.style.width = '100%';
                    
                    setTimeout(() => {
                        progressDiv.style.display = 'none';
                        
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Не удалось рассчитать оценку.');
                        }
                        
                        // Отображаем результаты
                        // Получаем DCF Equity Value из data-атрибута
                        const priceDeterminationCard = document.getElementById('price-determination');
                        let dcfEquityValue = null;
                        if (priceDeterminationCard && priceDeterminationCard.dataset.dcfEquity) {
                            const parsed = parseFloat(priceDeterminationCard.dataset.dcfEquity);
                            if (!isNaN(parsed) && parsed > 0) {
                                dcfEquityValue = parsed;
                            }
                        }
                        
                        displayMultiplierValuationResult(payload, dcfEquityValue);
                        resultDiv.style.display = 'block';
                        
                        // Сохраняем результаты расчета мультипликатора в БД
                        saveMultiplierValuation(payload);
                        
                        // Показываем секцию с финальной ценой
                        const finalPriceSection = document.getElementById('final-price-section');
                        if (finalPriceSection) {
                            finalPriceSection.style.display = 'block';
                        }
                    }, 300);
                    
                } catch (error) {
                    clearInterval(progressInterval);
                    progressDiv.style.display = 'none';
                    console.error('Multiplier valuation failed', error);
                    resultDiv.innerHTML = `
                        <div style="background: #FEF2F2; border: 1px solid #FEE2E2; border-radius: 8px; padding: 16px; color: #991B1B;">
                            <strong>Ошибка:</strong> ${error.message || 'Не удалось рассчитать оценку по мультипликаторам.'}
                        </div>
                    `;
                    resultDiv.style.display = 'block';
                } finally {
                    calculateBtn.disabled = false;
                    calculateBtn.textContent = 'Рассчитать оценку по мультипликаторам';
                }
            };
            
            /**
             * Отображает результаты расчета оценки по мультипликаторам
             * 
             * @param {Object} payload Данные от сервера с результатами расчета
             * @param {number|null} dcfEquityValue Equity Value из DCF модели
             */
            const displayMultiplierValuationResult = (payload, dcfEquityValue) => {
                const resultDiv = document.getElementById('multiplier-valuation-result');
                if (!resultDiv) return;
                
                const { sector, financial_data, valuation } = payload;
                const { equity_value, applied_multipliers, ev, ev1, ev2 } = valuation;
                
                // Используем equity_value для итоговой оценки по мультипликаторам
                // Для нефинансовых секторов: equity_value = EV + денежные средства - долг
                // Для финансового сектора: equity_value = Чистая прибыль × P/E
                const multiplierValue = equity_value;
                
                // Определяем диапазон оценки (от меньшей до большей цены)
                let minValue = multiplierValue;
                let maxValue = multiplierValue;
                
                if (dcfEquityValue !== null && dcfEquityValue > 0) {
                    if (dcfEquityValue < multiplierValue) {
                        minValue = dcfEquityValue;
                        maxValue = multiplierValue;
                    } else {
                        minValue = multiplierValue;
                        maxValue = dcfEquityValue;
                    }
                }
                
                let html = '<div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px;">';
                
                // Диапазон оценки
                html += '<div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.08) 100%); border: 2px solid rgba(102, 126, 234, 0.2); border-radius: 12px; padding: 24px; margin-bottom: 24px;">';
                html += '<div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Диапазон оценки актива</div>';
                html += '<div style="font-size: 28px; font-weight: 800; color: var(--text-primary); margin-bottom: 8px;">';
                html += 'от ' + formatMoney(minValue) + ' до ' + formatMoney(maxValue) + ' млн ₽';
                html += '</div>';
                html += '<div style="font-size: 14px; color: var(--text-secondary); margin-top: 12px;">';
                if (dcfEquityValue !== null && dcfEquityValue > 0) {
                    html += '<div style="margin-bottom: 4px;">• Оценка по DCF: <strong>' + formatMoney(dcfEquityValue) + ' млн ₽</strong></div>';
                    html += '<div>• Оценка по мультипликаторам: <strong>' + formatMoney(multiplierValue) + ' млн ₽</strong></div>';
                } else {
                    html += 'Оценка по мультипликаторам: <strong>' + formatMoney(multiplierValue) + ' млн ₽</strong>';
                }
                html += '</div>';
                html += '</div>';
                
                // Отрасль
                html += '<div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">';
                html += '<div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Отрасль</div>';
                html += '<div style="font-size: 18px; font-weight: 700; color: var(--text-primary);">' + escapeHtml(sector) + '</div>';
                html += '</div>';
                
                // Примененные мультипликаторы
                html += '<div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">';
                html += '<div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Примененные мультипликаторы</div>';
                html += '<div style="font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; line-height: 1.5;">Это текущие мультипликаторы по аналогичным сделкам M&A на российском рынке</div>';
                html += '<div style="display: flex; gap: 16px; flex-wrap: wrap;">';
                for (const [key, value] of Object.entries(applied_multipliers)) {
                    html += '<div style="background: #F8F9FA; border-radius: 8px; padding: 12px 16px; flex: 1; min-width: 150px;">';
                    html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 4px;">' + escapeHtml(key) + '</div>';
                    html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + value.toFixed(1) + '×</div>';
                    html += '</div>';
                }
                html += '</div>';
                html += '</div>';
                
                // Детали расчета (для нефинансового сектора)
                if (ev !== null && ev1 !== null && ev2 !== null) {
                    html += '<div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);">';
                    html += '<div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 12px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Детали расчета</div>';
                    html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">';
                    html += '<div><div style="font-size: 11px; color: var(--text-secondary);">EV₁ (Выручка × мультипликатор)</div><div style="font-size: 16px; font-weight: 600;">' + formatMoney(ev1) + ' млн ₽</div></div>';
                    html += '<div><div style="font-size: 11px; color: var(--text-secondary);">EV₂ ((Прибыль от продаж + амортизация) × мультипликатор)</div><div style="font-size: 16px; font-weight: 600;">' + formatMoney(ev2) + ' млн ₽</div></div>';
                    html += '<div><div style="font-size: 11px; color: var(--text-secondary);">EV (среднее)</div><div style="font-size: 16px; font-weight: 600;">' + formatMoney(ev) + ' млн ₽</div></div>';
                    html += '</div>';
                    html += '</div>';
                }
                
                // Стоимость актива по методу мультипликаторов
                html += '<div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.08) 100%); border: 2px solid rgba(102, 126, 234, 0.2); border-radius: 12px; padding: 20px; margin-bottom: 20px;">';
                html += '<div style="font-weight: 600; color: var(--text-secondary); margin-bottom: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em;">Стоимость актива по методу мультипликаторов</div>';
                html += '<div style="font-size: 32px; font-weight: 800; color: var(--text-primary); margin-bottom: 8px;">' + formatMoney(multiplierValue) + ' млн ₽</div>';
                html += '<div style="font-size: 13px; color: var(--text-secondary);">Оценочная стоимость актива</div>';
                html += '</div>';
                
                // Финансовые показатели
                html += '<div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid var(--border-color);">';
                html += '<div style="font-weight: 700; color: var(--text-primary); margin-bottom: 20px; font-size: 16px; letter-spacing: 0.02em;">Использованные финансовые показатели</div>';
                html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">';
                
                // Выручка
                html += '<div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.05) 100%); border: 1px solid rgba(102, 126, 234, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Выручка</div>';
                html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.revenue) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                html += '</div>';
                
                // Прибыль от продаж
                if (financial_data.operating_profit !== null) {
                    html += '<div style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.08) 0%, rgba(22, 163, 74, 0.05) 100%); border: 1px solid rgba(34, 197, 94, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                    html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Прибыль от продаж</div>';
                    html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.operating_profit) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                    html += '</div>';
                }
                
                // Амортизация
                html += '<div style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.08) 0%, rgba(245, 158, 11, 0.05) 100%); border: 1px solid rgba(251, 191, 36, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Амортизация</div>';
                html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.depreciation) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                html += '</div>';
                
                // EBITDA
                html += '<div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.08) 0%, rgba(37, 99, 235, 0.05) 100%); border: 1px solid rgba(59, 130, 246, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">EBITDA</div>';
                html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.ebitda) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                html += '</div>';
                
                // Долг
                if (financial_data.debt > 0 || financial_data.cash > 0) {
                    html += '<div style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.08) 0%, rgba(220, 38, 38, 0.05) 100%); border: 1px solid rgba(239, 68, 68, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                    html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Долг</div>';
                    html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.debt) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                    html += '</div>';
                    
                    // Денежные средства
                    html += '<div style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.05) 100%); border: 1px solid rgba(16, 185, 129, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                    html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Денежные средства</div>';
                    html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.cash) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                    html += '</div>';
                }
                
                // Чистая прибыль
                if (financial_data.net_profit !== null && financial_data.net_profit > 0) {
                    html += '<div style="background: linear-gradient(135deg, rgba(168, 85, 247, 0.08) 0%, rgba(147, 51, 234, 0.05) 100%); border: 1px solid rgba(168, 85, 247, 0.15); border-radius: 12px; padding: 16px; transition: all 0.3s ease;">';
                    html += '<div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Чистая прибыль</div>';
                    html += '<div style="font-size: 20px; font-weight: 700; color: var(--text-primary);">' + formatMoney(financial_data.net_profit) + ' <span style="font-size: 14px; font-weight: 500; color: var(--text-secondary);">млн ₽</span></div>';
                    html += '</div>';
                }
                
                html += '</div>';
                html += '</div>';
                
                html += '</div>';
                
                resultDiv.innerHTML = html;
            };
            
            /**
             * Форматирует число как денежную сумму
             * 
             * @param {number} value Значение для форматирования
             * @return {string} Отформатированная строка
             */
            const formatMoney = (value) => {
                if (value === null || value === undefined) return '—';
                const absValue = Math.abs(value);
                if (absValue > 0 && absValue < 1) {
                    return value.toLocaleString('ru-RU', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    });
                }
                return Math.round(value).toLocaleString('ru-RU');
            };
            
            /**
             * Экранирует HTML символы
             * 
             * @param {string} text Текст для экранирования
             * @return {string} Экранированный текст
             */
            const escapeHtml = (text) => {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            };
            
            /**
             * Сохраняет результаты расчета мультипликатора в БД
             * 
             * @param {Object} payload Данные расчета мультипликатора
             */
            const saveMultiplierValuation = async (payload) => {
                try {
                    const response = await fetch('save_price_data.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            multiplier_valuation: payload
                        })
                    });
                    
                    const result = await response.json();
                    if (!result.success) {
                        console.error('Failed to save multiplier valuation:', result.message);
                    }
                } catch (error) {
                    console.error('Error saving multiplier valuation:', error);
                }
            };
            
            /**
             * Обновляет отображение даты и времени последнего изменения финальной цены
             * 
             * @param {string} timestamp ISO строка с датой и временем
             */
            const updateFinalPriceTimestamp = (timestamp) => {
                const timestampDiv = document.getElementById('final-price-updated-at');
                if (timestampDiv && timestamp) {
                    try {
                        const date = new Date(timestamp);
                        const formattedDate = date.toLocaleDateString('ru-RU', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        timestampDiv.textContent = 'Последнее изменение: ' + formattedDate;
                        timestampDiv.style.display = 'block';
                    } catch (error) {
                        console.error('Error formatting timestamp:', error);
                    }
                }
            };
            
            /**
             * Обновляет цену в hero block после сохранения финальной цены
             * 
             * @param {number} price Цена предложения Продавца
             */
            const updateHeroPrice = (price) => {
                const heroPriceValue = document.getElementById('hero-price-value');
                const heroPriceCaption = document.getElementById('hero-price-caption');
                const heroPriceStat = document.getElementById('hero-price-stat');
                
                if (price && price > 0) {
                    const formattedPrice = Math.round(price).toLocaleString('ru-RU') + ' млн ₽';
                    
                    // Если элемент "Цена" уже существует, обновляем его
                    if (heroPriceValue) {
                        heroPriceValue.textContent = formattedPrice;
                        if (heroPriceCaption) {
                            heroPriceCaption.textContent = 'Цена предложения Продавца';
                        }
                    } else {
                        // Если элемента "Цена" нет, создаем его
                        const heroStats = document.querySelector('.teaser-hero__stats');
                        if (heroStats) {
                            // Проверяем, не превышен ли лимит в 4 элемента
                            const existingStats = heroStats.querySelectorAll('.teaser-stat');
                            if (existingStats.length >= 4) {
                                // Заменяем последний элемент или элемент с "Equity Value", если он есть
                                let statToReplace = null;
                                existingStats.forEach(stat => {
                                    const label = stat.querySelector('span');
                                    if (label && label.textContent.trim() === 'Equity Value') {
                                        statToReplace = stat;
                                    }
                                });
                                if (!statToReplace && existingStats.length > 0) {
                                    statToReplace = existingStats[existingStats.length - 1];
                                }
                                
                                if (statToReplace) {
                                    statToReplace.id = 'hero-price-stat';
                                    const strong = statToReplace.querySelector('strong');
                                    const small = statToReplace.querySelector('small');
                                    const span = statToReplace.querySelector('span');
                                    
                                    if (span) span.textContent = 'Цена';
                                    if (strong) {
                                        strong.id = 'hero-price-value';
                                        strong.textContent = formattedPrice;
                                    }
                                    if (small) {
                                        small.id = 'hero-price-caption';
                                        small.textContent = 'Цена предложения Продавца';
                                    }
                                }
                            } else {
                                // Добавляем новый элемент
                                const newStat = document.createElement('div');
                                newStat.className = 'teaser-stat';
                                newStat.id = 'hero-price-stat';
                                newStat.innerHTML = `
                                    <span>Цена</span>
                                    <strong id="hero-price-value">${formattedPrice}</strong>
                                    <small id="hero-price-caption">Цена предложения Продавца</small>
                                `;
                                heroStats.appendChild(newStat);
                            }
                        }
                    }
                }
            };
            
            /**
             * Сохраняет цену предложения Продавца в БД
             * 
             * @param {number} price Цена предложения Продавца
             */
            const saveFinalPrice = async (price) => {
                try {
                    // Получаем form_id из URL или из элемента страницы
                    const urlParams = new URLSearchParams(window.location.search);
                    let formId = urlParams.get('form_id');
                    
                    // Если form_id не в URL, пытаемся найти его в элементах страницы
                    if (!formId) {
                        const formIdElement = document.querySelector('[data-form-id]');
                        if (formIdElement) {
                            formId = formIdElement.dataset.formId;
                        }
                    }
                    
                    const requestBody = {
                        final_price: parseFloat(price)
                    };
                    
                    // Если form_id найден, добавляем его в запрос
                    if (formId) {
                        requestBody.form_id = parseInt(formId);
                    }
                    
                    const response = await fetch('save_price_data.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify(requestBody)
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const result = await response.json();
                    if (!result.success) {
                        console.error('Failed to save final price:', result.message);
                        throw new Error(result.message || 'Failed to save final price');
                    }
                    
                    // Возвращаем дату обновления из ответа сервера, если она есть
                    return result.final_price_updated_at || new Date().toISOString();
                } catch (error) {
                    console.error('Error saving final price:', error);
                    throw error; // Пробрасываем ошибку дальше
                }
            };
            
            /**
             * Загружает сохраненные данные расчета мультипликатора и финальной цены
             */
            const loadSavedPriceData = () => {
                <?php
                // Загружаем сохраненные данные из БД
                if ($latestForm && !empty($latestForm['data_json'])) {
                    $formData = json_decode($latestForm['data_json'], true);
                    if (is_array($formData)) {
                        $savedMultiplierValuation = $formData['multiplier_valuation'] ?? null;
                        $savedFinalPrice = $formData['final_price'] ?? null;
                        
                        if ($savedMultiplierValuation) {
                            echo "const savedMultiplierValuation = " . json_encode($savedMultiplierValuation, JSON_UNESCAPED_UNICODE) . ";\n";
                        } else {
                            echo "const savedMultiplierValuation = null;\n";
                        }
                        
                        if ($savedFinalPrice !== null) {
                            echo "const savedFinalPrice = " . json_encode($savedFinalPrice, JSON_UNESCAPED_UNICODE) . ";\n";
                        } else {
                            echo "const savedFinalPrice = null;\n";
                        }
                        
                        $savedFinalPriceUpdatedAt = $formData['final_price_updated_at'] ?? null;
                        if ($savedFinalPriceUpdatedAt) {
                            echo "const savedFinalPriceUpdatedAt = " . json_encode($savedFinalPriceUpdatedAt, JSON_UNESCAPED_UNICODE) . ";\n";
                        } else {
                            echo "const savedFinalPriceUpdatedAt = null;\n";
                        }
                    } else {
                        echo "const savedMultiplierValuation = null;\n";
                        echo "const savedFinalPrice = null;\n";
                    }
                } else {
                    echo "const savedMultiplierValuation = null;\n";
                    echo "const savedFinalPrice = null;\n";
                    echo "const savedFinalPriceUpdatedAt = null;\n";
                }
                ?>
                
                // Восстанавливаем результаты расчета мультипликатора, если они есть
                if (savedMultiplierValuation) {
                    const resultDiv = document.getElementById('multiplier-valuation-result');
                    const finalPriceSection = document.getElementById('final-price-section');
                    const priceDeterminationCard = document.getElementById('price-determination');
                    
                    if (resultDiv && savedMultiplierValuation.sector && savedMultiplierValuation.valuation) {
                        let dcfEquityValue = null;
                        if (priceDeterminationCard && priceDeterminationCard.dataset.dcfEquity) {
                            const parsed = parseFloat(priceDeterminationCard.dataset.dcfEquity);
                            if (!isNaN(parsed) && parsed > 0) {
                                dcfEquityValue = parsed;
                            }
                        }
                        
                        displayMultiplierValuationResult(savedMultiplierValuation, dcfEquityValue);
                        resultDiv.style.display = 'block';
                        
                        if (finalPriceSection) {
                            finalPriceSection.style.display = 'block';
                        }
                    }
                }
                
                // Восстанавливаем финальную цену, если она есть
                if (savedFinalPrice !== null) {
                    const finalPriceInput = document.getElementById('final-price-input');
                    if (finalPriceInput) {
                        finalPriceInput.value = savedFinalPrice;
                    }
                    // Обновляем цену в hero block
                    updateHeroPrice(savedFinalPrice);
                }
                
                // Восстанавливаем дату и время последнего изменения, если они есть
                if (savedFinalPriceUpdatedAt) {
                    updateFinalPriceTimestamp(savedFinalPriceUpdatedAt);
                }
                
                // Восстанавливаем дату и время последнего изменения, если они есть
                if (savedFinalPriceUpdatedAt) {
                    updateFinalPriceTimestamp(savedFinalPriceUpdatedAt);
                }
            };
            
            /**
             * Инициализация расчета оценки по мультипликаторам
             */
            const initMultiplierValuation = () => {
                const calculateBtn = document.getElementById('calculate-multiplier-btn');
                if (!calculateBtn) {
                    return;
                }
                calculateBtn.addEventListener('click', handleMultiplierValuation);
                
                // Загружаем сохраненные данные ПЕРЕД инициализацией обработчиков
                loadSavedPriceData();
                
                // Инициализируем обработчики для сохранения финальной цены
                initFinalPriceHandlers();
            };
            
            /**
             * Инициализация обработчиков для сохранения финальной цены
             * Может вызываться повторно, если секция показывается динамически
             */
            const initFinalPriceHandlers = () => {
                // Добавляем обработчик для сохранения финальной цены
                const finalPriceInput = document.getElementById('final-price-input');
                const confirmPriceBtn = document.getElementById('confirm-price-btn');
                
                if (!finalPriceInput || !confirmPriceBtn) {
                    // Элементы еще не загружены, попробуем позже
                    return;
                }
                
                // Убираем старые обработчики, если они были добавлены ранее
                const newConfirmBtn = confirmPriceBtn.cloneNode(true);
                confirmPriceBtn.parentNode.replaceChild(newConfirmBtn, confirmPriceBtn);
                const newFinalPriceInput = finalPriceInput.cloneNode(true);
                finalPriceInput.parentNode.replaceChild(newFinalPriceInput, finalPriceInput);
                
                // Получаем обновленные ссылки на элементы
                const updatedFinalPriceInput = document.getElementById('final-price-input');
                const updatedConfirmPriceBtn = document.getElementById('confirm-price-btn');
                
                if (updatedFinalPriceInput && updatedConfirmPriceBtn) {
                    // Автосохранение при потере фокуса, если значение изменилось
                    let lastSavedPrice = typeof savedFinalPrice !== 'undefined' ? savedFinalPrice : null;
                    let isSaving = false;
                    
                    const savePrice = async (priceValue, updateLastSaved = true) => {
                        if (isSaving) return;
                        
                        isSaving = true;
                        try {
                            const updatedAt = await saveFinalPrice(priceValue);
                            
                            if (updateLastSaved) {
                                lastSavedPrice = priceValue;
                            }
                            
                            if (updatedAt) {
                                updateFinalPriceTimestamp(updatedAt);
                            } else {
                                updateFinalPriceTimestamp(new Date().toISOString());
                            }
                            
                            updateHeroPrice(priceValue);
                            return updatedAt;
                        } catch (error) {
                            console.error('Error saving price:', error);
                            throw error;
                        } finally {
                            isSaving = false;
                        }
                    };
                    
                    // Обработчик для кнопки "Подтвердить"
                    updatedConfirmPriceBtn.addEventListener('click', async () => {
                        const price = updatedFinalPriceInput.value;
                        console.log('Confirm price button clicked, price value:', price);
                        
                        if (!price || parseFloat(price) <= 0) {
                            alert('Пожалуйста, введите корректную цену');
                            return;
                        }
                        
                        const priceValue = parseFloat(price);
                        console.log('Parsed price value:', priceValue);
                        
                        // Сохраняем оригинальный текст кнопки до изменения
                        const originalText = updatedConfirmPriceBtn.textContent;
                        
                        // Блокируем кнопку на время сохранения
                        updatedConfirmPriceBtn.disabled = true;
                        updatedConfirmPriceBtn.textContent = 'Сохранение...';
                        
                        try {
                            console.log('Calling savePrice with value:', priceValue);
                            await savePrice(priceValue);
                            console.log('Price saved successfully');
                            
                            // Показываем успешное сообщение
                            updatedConfirmPriceBtn.textContent = '✓ Сохранено';
                            updatedConfirmPriceBtn.style.background = 'linear-gradient(135deg, #10B981 0%, #059669 100%)';
                            
                            setTimeout(() => {
                                updatedConfirmPriceBtn.textContent = originalText;
                                updatedConfirmPriceBtn.style.background = '';
                                updatedConfirmPriceBtn.disabled = false;
                            }, 2000);
                        } catch (error) {
                            console.error('Error in confirm button handler:', error);
                            alert('Ошибка при сохранении цены: ' + (error.message || 'Неизвестная ошибка'));
                            updatedConfirmPriceBtn.disabled = false;
                            updatedConfirmPriceBtn.textContent = originalText;
                        }
                    });
                    
                    // Автосохранение при потере фокуса, если значение изменилось
                    updatedFinalPriceInput.addEventListener('blur', async () => {
                        const currentPrice = parseFloat(updatedFinalPriceInput.value);
                        // Сохраняем только если значение изменилось и оно валидное
                        if (currentPrice > 0 && currentPrice !== lastSavedPrice) {
                            try {
                                await savePrice(currentPrice);
                            } catch (error) {
                                console.error('Error auto-saving price:', error);
                            }
                        }
                    });
                    
                    // Также сохраняем при нажатии Enter
                    updatedFinalPriceInput.addEventListener('keypress', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            updatedConfirmPriceBtn.click();
                        }
                    });
                }
            };

            window.handleTeaserGenerate = handleTeaserGenerate;
            window.handleTeaserPrint = handleTeaserPrint;
        })();
    </script>
    <script>
            /**
             * Обработка загрузки документов
             * Определяем функцию глобально ПЕРЕД загрузкой script.js
             * чтобы она была доступна в обработчиках событий
             */
            window.handleDocumentUpload = async function(files) {
                console.log('=== handleDocumentUpload CALLED ===');
                console.log('Files parameter:', files);
                console.log('Files count:', files ? files.length : 0);
                console.log('Files type:', typeof files);
                console.log('Is FileList?', files instanceof FileList);
                
                if (!files || files.length === 0) {
                    console.error('❌ No files provided to handleDocumentUpload');
                    alert('Файлы не выбраны.');
                    return;
                }
                
                const documentsSection = document.getElementById('asset-documents-section');
                if (!documentsSection) {
                    console.error('❌ Documents section not found');
                    console.error('Available elements with "document" in ID:');
                    const allElements = document.querySelectorAll('[id*="document"]');
                    allElements.forEach(el => console.log('  -', el.id));
                    alert('Блок документов не найден на странице. Убедитесь, что актив выбран.');
                    return;
                }
                
                const formId = documentsSection.dataset.formId;
                if (!formId) {
                    console.error('❌ Form ID not found in dataset');
                    console.error('Documents section dataset:', documentsSection.dataset);
                    alert('Не указан ID актива.');
                    return;
                }
                
                console.log('✅ Form ID found:', formId);
                
                const uploadZone = document.getElementById('document-upload-zone');
                const fileInput = document.getElementById('document-file-input');
                
                if (!uploadZone) {
                    console.error('❌ Upload zone not found!');
                    alert('Зона загрузки не найдена на странице.');
                    return;
                }
                
                if (!fileInput) {
                    console.error('❌ File input not found!');
                    alert('Поле выбора файлов не найдено на странице.');
                    return;
                }
                
                console.log('✅ Upload zone and file input found');
                
                // Отключаем зону загрузки
                uploadZone.style.opacity = '0.6';
                uploadZone.style.pointerEvents = 'none';
                
                // Показываем индикатор загрузки
                const originalText = uploadZone.querySelector('.document-upload-zone__text');
                const originalButton = uploadZone.querySelector('.document-upload-zone__button');
                const originalHTML = originalText ? originalText.innerHTML : '';
                const originalButtonHTML = originalButton ? originalButton.innerHTML : '';
                
                if (originalText) {
                    originalText.innerHTML = 'Загрузка файлов...';
                }
                if (originalButton) {
                    originalButton.style.display = 'none';
                }
                
                try {
                    // Загружаем файлы по одному
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        console.log(`Uploading file ${i + 1}/${files.length}:`, file.name, `(${(file.size / 1024 / 1024).toFixed(2)} МБ)`);
                        
                        const formData = new FormData();
                        formData.append('file', file);
                        formData.append('seller_form_id', formId);
                        
                        console.log('Sending request to upload_asset_document.php...');
                        
                        const response = await fetch('upload_asset_document.php', {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        
                        console.log('Response status:', response.status, response.statusText);
                        
                        // Проверяем, является ли ответ JSON
                        const contentType = response.headers.get('content-type');
                        if (!contentType || !contentType.includes('application/json')) {
                            const text = await response.text();
                            console.error('Non-JSON response:', text);
                            throw new Error('Сервер вернул неверный формат ответа. Проверьте консоль для деталей.');
                        }
                        
                        const result = await response.json();
                        console.log('Response result:', result);
                        
                        if (!response.ok || !result.success) {
                            throw new Error(result.message || 'Ошибка загрузки файла: ' + file.name);
                        }
                        
                        console.log('File uploaded successfully:', file.name);
                    }
                    
                    // Обновляем список документов и статистику
                    console.log('Updating documents list...');
                    await updateDocumentsList();
                    
                    // Очищаем input
                    if (fileInput) {
                        fileInput.value = '';
                    }

                    // Сбрасываем флаги после успешной загрузки
                    // ВАЖНО: Делаем это ПОСЛЕ всех асинхронных операций
                    if (window.documentUploadState) {
                        console.log('🔄 Resetting upload flags after successful upload');
                        window.documentUploadState.isProcessingFiles = false;
                        window.documentUploadState.isDialogOpen = false;
                        window.documentUploadState.lastProcessedFiles = null; // Сбрасываем, чтобы можно было загрузить тот же файл снова
                        console.log('✅ Upload flags reset after success:', {
                            isProcessingFiles: window.documentUploadState.isProcessingFiles,
                            isDialogOpen: window.documentUploadState.isDialogOpen,
                            lastProcessedFiles: window.documentUploadState.lastProcessedFiles
                        });
                    }
                    
                    // Показываем сообщение об успехе
                    if (originalText) {
                        originalText.innerHTML = 'Файлы успешно загружены!';
                        setTimeout(() => {
                            originalText.innerHTML = originalHTML;
                            // Переинициализируем кнопку после изменения HTML
                            // НЕ нужно переинициализировать, так как обработчики уже привязаны через initDocumentUpload
                            // Просто восстанавливаем кнопку
                            if (originalButton) {
                                originalButton.style.display = '';
                            }
                        }, 2000);
                    }
                    
                } catch (error) {
                    console.error('Error uploading document:', error);
                    console.error('Error stack:', error.stack);
                    
                    let errorMessage = 'Ошибка загрузки документа: ' + error.message;
                    if (error.message.includes('JSON')) {
                        errorMessage += '\n\nВозможно, на сервере произошла ошибка. Проверьте логи сервера.';
                    }
                    
                    alert(errorMessage);
                    
                    // Восстанавливаем текст
                    if (originalText) {
                        originalText.innerHTML = originalHTML;
                    }
                    
                    // Сбрасываем флаги при ошибке
                    if (window.documentUploadState) {
                        console.log('🔄 Resetting upload flags after error');
                        window.documentUploadState.isProcessingFiles = false;
                        window.documentUploadState.isDialogOpen = false;
                        window.documentUploadState.lastProcessedFiles = null;
                        console.log('✅ Upload flags reset after error:', {
                            isProcessingFiles: window.documentUploadState.isProcessingFiles,
                            isDialogOpen: window.documentUploadState.isDialogOpen,
                            lastProcessedFiles: window.documentUploadState.lastProcessedFiles
                        });
                    }
                } finally {
                    // Включаем зону загрузки обратно
                    uploadZone.style.opacity = '1';
                    uploadZone.style.pointerEvents = 'auto';
                    
                    // Сбрасываем флаги обработки, чтобы можно было загрузить файлы снова
                    if (window.documentUploadState) {
                        console.log('🔄 Resetting upload flags in finally block');
                        window.documentUploadState.isProcessingFiles = false;
                        window.documentUploadState.isDialogOpen = false;
                        window.documentUploadState.lastProcessedFiles = null; // Сбрасываем, чтобы можно было загрузить тот же файл снова
                        console.log('✅ Upload flags reset:', {
                            isProcessingFiles: window.documentUploadState.isProcessingFiles,
                            isDialogOpen: window.documentUploadState.isDialogOpen,
                            lastProcessedFiles: window.documentUploadState.lastProcessedFiles
                        });
                    }
                }
            };
            
            /**
             * Инициализация функциональности загрузки документов
             * Определяем глобально для доступа из других скриптов
             */
            
            // Глобальные переменные для предотвращения повторной обработки
            // Они должны быть общими для всех вызовов initDocumentUpload
            if (!window.documentUploadState) {
                window.documentUploadState = {
                    isProcessingFiles: false,
                    lastProcessedFiles: null,
                    isInitialized: false,
                    fileCheckInterval: null,
                    isDialogOpen: false
                };
            }
            
            window.initDocumentUpload = function() {
                // Предотвращаем повторную инициализацию
                if (window.documentUploadState.isInitialized) {
                    console.log('⚠️ Document upload already initialized, skipping...');
                    return;
                }
                
                // Помечаем как инициализированное
                window.documentUploadState.isInitialized = true;
                
                // Используем глобальные переменные
                const state = window.documentUploadState;
                const uploadZone = document.getElementById('document-upload-zone');
                const fileInput = document.getElementById('document-file-input');
                const uploadBtn = document.getElementById('document-upload-btn');
                
                if (!uploadZone || !fileInput || !uploadBtn) {
                    console.log('Document upload elements not found:', {
                        uploadZone: !!uploadZone,
                        fileInput: !!fileInput,
                        uploadBtn: !!uploadBtn
                    });
                    return;
                }
                
                console.log('Initializing document upload...');
                console.log('handleDocumentUpload available:', typeof window.handleDocumentUpload);
                
                // Вспомогательная функция для привязки обработчиков к кнопке
                const bindUploadButton = (btn) => {
                    if (!btn || btn.dataset.boundUpload === '1') {
                        return;
                    }
                    btn.addEventListener('click', handleUploadClick, { once: false });
                    btn.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            handleUploadClick(e);
                        }
                    });
                    btn.dataset.boundUpload = '1';
                    console.log('✅ Upload button listeners bound');
                };
                
                // Используем глобальные переменные из state (уже определены выше)
                
                // Обработчик клика по кнопке загрузки
                const handleUploadClick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Предотвращаем повторное открытие диалога
                    if (state.isDialogOpen || state.isProcessingFiles) {
                        console.log('⚠️ Dialog already open or processing, ignoring click');
                        console.log('State:', {
                            isDialogOpen: state.isDialogOpen,
                            isProcessingFiles: state.isProcessingFiles,
                            lastProcessedFiles: state.lastProcessedFiles
                        });
                        return;
                    }
                    
                    console.log('✅ Button click allowed, state:', {
                        isDialogOpen: state.isDialogOpen,
                        isProcessingFiles: state.isProcessingFiles,
                        lastProcessedFiles: state.lastProcessedFiles
                    });
                    
                    console.log('✅ Upload button clicked, opening file dialog...');
                    
                    // Останавливаем предыдущий интервал, если он был
                    if (state.fileCheckInterval) {
                        console.log('Stopping previous polling interval');
                        clearInterval(state.fileCheckInterval);
                        state.fileCheckInterval = null;
                    }
                    
                    // Очищаем предыдущее значение input для возможности повторной загрузки того же файла
                    fileInput.value = '';
                    
                    // Сохраняем текущее состояние файлов
                    const initialFileCount = fileInput.files ? fileInput.files.length : 0;
                    console.log('Initial file count:', initialFileCount);
                    
                    // Устанавливаем флаг
                    state.isDialogOpen = true;
                    
                    // Открываем диалог выбора файлов
                    console.log('Calling fileInput.click()...');
                    fileInput.click();
                    
                    // Запускаем polling для проверки изменений файлов
                    // Используем более частую проверку для быстрого обнаружения
                    let pollCount = 0;
                    // Проверяем не дольше 2 секунд (20 * 100ms)
                    const maxPolls = 20;
                    
                    state.fileCheckInterval = setInterval(() => {
                        pollCount++;
                        const currentFileCount = fileInput.files ? fileInput.files.length : 0;
                        const currentFiles = fileInput.files;
                        
                        // Логируем каждые 5 проверок для более детальной отладки
                        if (pollCount % 5 === 0) {
                            console.log(`🔍 Polling check #${pollCount}: files count = ${currentFileCount} (initial: ${initialFileCount})`);
                            if (currentFiles && currentFiles.length > 0) {
                                console.log(`   Files: ${Array.from(currentFiles).map(f => f.name).join(', ')}`);
                            }
                        }
                        
                        // Проверяем, изменилось ли количество файлов
                        if (currentFileCount !== initialFileCount && currentFileCount > 0 && !state.isProcessingFiles) {
                            console.log('=== ✅ FILES DETECTED VIA POLLING ===');
                            console.log('Files count changed from', initialFileCount, 'to', currentFileCount);
                            clearInterval(state.fileCheckInterval);
                            state.fileCheckInterval = null;
                            state.isDialogOpen = false; // Сбрасываем флаг
                            
                            if (fileInput.files && fileInput.files.length > 0) {
                                const files = fileInput.files;
                                console.log('First file:', files[0].name, files[0].size, 'bytes');
                                
                                // Проверяем, не обрабатывали ли мы уже эти файлы
                                const filesKey = Array.from(files).map(f => `${f.name}-${f.size}-${f.lastModified}`).join('|');
                                if (state.lastProcessedFiles === filesKey) {
                                    console.log('⚠️ These files were already processed via polling, ignoring');
                                    return;
                                }
                                
                                // Устанавливаем флаг обработки
                                state.isProcessingFiles = true;
                                state.lastProcessedFiles = filesKey;
                                
                                if (typeof window.handleDocumentUpload === 'function') {
                                    console.log('🚀 Calling handleDocumentUpload with', files.length, 'file(s)');
                                    try {
                                        // Вызываем асинхронно
                                        window.handleDocumentUpload(files).then(() => {
                                            // Флаги будут сброшены в finally блоке handleDocumentUpload
                                            // Здесь только очищаем input
                                            fileInput.value = '';
                                            console.log('✅ File upload completed (polling), input cleared');
                                        }).catch(error => {
                                            console.error('❌ Error in handleDocumentUpload promise:', error);
                                            // Флаги будут сброшены в finally блоке handleDocumentUpload
                                            alert('Ошибка при загрузке файла: ' + error.message);
                                        });
                                    } catch (error) {
                                        console.error('❌ ERROR in handleDocumentUpload:', error);
                                        console.error('Error stack:', error.stack);
                                        state.isProcessingFiles = false;
                                        state.lastProcessedFiles = null;
                                        alert('Ошибка при загрузке файла: ' + error.message);
                                    }
                                } else {
                                    console.error('❌ handleDocumentUpload is not a function!');
                                    console.error('typeof window.handleDocumentUpload:', typeof window.handleDocumentUpload);
                                    state.isProcessingFiles = false;
                                    state.lastProcessedFiles = null;
                                    alert('Ошибка: функция загрузки не найдена.');
                                }
                            }
                        } else if (pollCount >= maxPolls) {
                            console.log('⏱️ Polling timeout - no files selected or dialog cancelled');
                            console.log('Final file count:', currentFileCount, 'initial:', initialFileCount);
                            clearInterval(state.fileCheckInterval);
                            state.fileCheckInterval = null;
                            state.isDialogOpen = false; // Сбрасываем флаг
                        }
                    }, 100); // Проверяем каждые 100ms
                };
                
                // Привязываем обработчики только один раз без клонирования кнопки,
                // чтобы не терять обработчики при последующих кликах
                if (!uploadBtn.dataset.boundUpload) {
                    uploadBtn.addEventListener('click', handleUploadClick, { once: false });
                    // Также обрабатываем нажатие Enter для доступности
                    uploadBtn.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            handleUploadClick(e);
                        }
                    });
                    uploadBtn.dataset.boundUpload = '1';
                    console.log('✅ Upload button event listeners attached (single instance, no clone)');
                } else {
                    console.log('⚠️ Upload button listeners already attached, skipping re-bind');
                }
                
                // Обработчик change - основной способ обнаружения файлов
                const handleFileChange = function(e) {
                    console.log('=== 🔔 FILE INPUT CHANGE EVENT ===');
                    console.log('Change event - files:', e.target.files ? e.target.files.length : 0);
                    console.log('Change event - isProcessingFiles:', state.isProcessingFiles);
                    
                    // Предотвращаем повторную обработку - проверяем СРАЗУ
                    if (state.isProcessingFiles) {
                        console.log('⚠️ Files are already being processed, ignoring change event');
                        return;
                    }
                    
                    const files = e.target.files;
                    console.log('Change event - files object:', files);
                    console.log('Change event - files.length:', files ? files.length : 'null');
                    
                    if (files && files.length > 0) {
                        // Создаем ключ для проверки дубликатов
                        const filesKey = Array.from(files).map(f => `${f.name}-${f.size}-${f.lastModified}`).join('|');
                        
                        // Проверяем, не обрабатывали ли мы уже эти файлы
                        if (state.lastProcessedFiles === filesKey) {
                            console.log('⚠️ These files were already processed, ignoring');
                            return;
                        }
                        
                        console.log('✅ Files detected in change event:', Array.from(files).map(f => `${f.name} (${f.size} bytes)`));
                        
                        // Устанавливаем флаг обработки СРАЗУ, до любых других операций
                        // Это предотвратит повторную обработку, если событие сработает еще раз
                        state.isProcessingFiles = true;
                        state.lastProcessedFiles = filesKey;
                        
                        // Останавливаем polling, если он активен
                        if (state.fileCheckInterval) {
                            console.log('🛑 Stopping polling (files detected via change event)');
                            clearInterval(state.fileCheckInterval);
                            state.fileCheckInterval = null;
                        }
                        
                        // НЕ сбрасываем флаг диалога здесь, так как он будет сброшен после завершения загрузки
                        // state.isDialogOpen = false; // УБРАНО - сбрасываем только после завершения загрузки
                        
                        console.log('handleDocumentUpload type:', typeof window.handleDocumentUpload);
                        if (typeof window.handleDocumentUpload === 'function') {
                            console.log('🚀 Calling handleDocumentUpload from change event with', files.length, 'file(s)');
                            try {
                                // Вызываем асинхронно
                                window.handleDocumentUpload(files).then(() => {
                                    // Флаги будут сброшены в finally блоке handleDocumentUpload
                                    // Здесь только очищаем input
                                    fileInput.value = '';
                                    console.log('✅ File upload completed (change event), input cleared');
                                    // Убеждаемся, что флаги сброшены
                                    if (window.documentUploadState) {
                                        window.documentUploadState.isDialogOpen = false;
                                        window.documentUploadState.isProcessingFiles = false;
                                        window.documentUploadState.lastProcessedFiles = null;
                                        console.log('🔄 Flags reset in then handler:', {
                                            isDialogOpen: window.documentUploadState.isDialogOpen,
                                            isProcessingFiles: window.documentUploadState.isProcessingFiles
                                        });
                                    }
                                }).catch(error => {
                                    console.error('❌ Error in handleDocumentUpload promise:', error);
                                    // Сбрасываем флаги при ошибке
                                    if (window.documentUploadState) {
                                        window.documentUploadState.isDialogOpen = false;
                                        window.documentUploadState.isProcessingFiles = false;
                                        window.documentUploadState.lastProcessedFiles = null;
                                        console.log('🔄 Flags reset in catch handler:', {
                                            isDialogOpen: window.documentUploadState.isDialogOpen,
                                            isProcessingFiles: window.documentUploadState.isProcessingFiles
                                        });
                                    }
                                    alert('Ошибка при загрузке файла: ' + error.message);
                                });
                            } catch (error) {
                                console.error('❌ ERROR in handleDocumentUpload from change event:', error);
                                console.error('Error stack:', error.stack);
                                // Сбрасываем флаги при синхронной ошибке
                                state.isProcessingFiles = false;
                                state.isDialogOpen = false;
                                state.lastProcessedFiles = null;
                                alert('Ошибка при загрузке файла: ' + error.message);
                            }
                        } else {
                            console.error('❌ handleDocumentUpload is not a function in change handler!');
                            console.error('typeof window.handleDocumentUpload:', typeof window.handleDocumentUpload);
                            state.isProcessingFiles = false;
                            state.lastProcessedFiles = null;
                            alert('Ошибка: функция загрузки не найдена.');
                        }
                    } else {
                        console.log('⚠️ Change event fired but no files selected');
                        state.isDialogOpen = false; // Сбрасываем флаг даже если файлов нет
                    }
                };
                
                // Привязываем обработчик change ТОЛЬКО один раз
                // НЕ используем одновременно addEventListener и onchange, чтобы избежать дублирования
                fileInput.addEventListener('change', handleFileChange, false);
                
                // НЕ добавляем обработчик input, так как он может вызывать дублирование
                // Событие change достаточно для обнаружения файлов
                
                // Добавляем обработчик focus для отладки
                fileInput.addEventListener('focus', function(e) {
                    console.log('=== 👁️ FILE INPUT FOCUS EVENT ===');
                });
                
                // Добавляем обработчик blur для отладки
                fileInput.addEventListener('blur', function(e) {
                    console.log('=== 👁️ FILE INPUT BLUR EVENT ===');
                    console.log('Blur event - files:', e.target.files ? e.target.files.length : 0);
                    // Проверяем файлы после blur (когда диалог закрывается)
                    setTimeout(() => {
                        const files = e.target.files;
                        if (files && files.length > 0) {
                            console.log('✅ Files detected after blur:', Array.from(files).map(f => f.name));
                            handleFileChange(e);
                        }
                    }, 100);
                });
                
                console.log('✅ Change, input, focus, blur event listeners attached to file input');
                console.log('File input element:', fileInput);
                console.log('File input display style:', window.getComputedStyle(fileInput).display);
                console.log('File input visibility:', window.getComputedStyle(fileInput).visibility);
                console.log('File input opacity:', window.getComputedStyle(fileInput).opacity);
                
                // Drag and drop обработчики
                uploadZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    uploadZone.classList.add('document-upload-zone--dragover');
                });
                
                uploadZone.addEventListener('dragleave', () => {
                    uploadZone.classList.remove('document-upload-zone--dragover');
                });
                
                uploadZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    uploadZone.classList.remove('document-upload-zone--dragover');
                    
                    if (e.dataTransfer.files.length > 0) {
                        if (typeof window.handleDocumentUpload === 'function') {
                            window.handleDocumentUpload(e.dataTransfer.files);
                        }
                    }
                });
                
                // Загружаем список документов при инициализации
                updateDocumentsList();
            };
            
            /**
             * Обработка удаления документа
             */
            const handleDocumentDelete = async (documentId) => {
                if (!confirm('Вы уверены, что хотите удалить этот документ?')) {
                    return;
                }
                
                try {
                    const response = await fetch('delete_asset_document.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ document_id: documentId })
                    });
                    
                    const result = await response.json();
                    
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Ошибка удаления документа');
                    }
                    
                    // Обновляем список документов и статистику
                    await updateDocumentsList();
                    
                } catch (error) {
                    console.error('Error deleting document:', error);
                    alert('Ошибка удаления документа: ' + error.message);
                }
            };
            
            /**
             * Обновление списка документов и статистики
             */
            const updateDocumentsList = async () => {
                const documentsSection = document.getElementById('asset-documents-section');
                if (!documentsSection) {
                    return;
                }
                
                const formId = documentsSection.dataset.formId;
                if (!formId) {
                    return;
                }
                
                try {
                    const response = await fetch(`get_asset_documents.php?seller_form_id=${formId}`, {
                        credentials: 'same-origin'
                    });
                    
                    const result = await response.json();
                    
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Ошибка загрузки списка документов');
                    }
                    
                    // Обновляем статистику
                    updateStorageIndicator(result.stats);
                    
                    // Обновляем список документов
                    renderDocumentsList(result.documents);
                    
                } catch (error) {
                    console.error('Error loading documents list:', error);
                }
            };
            
            /**
             * Обновление индикатора использования места
             */
            const updateStorageIndicator = (stats) => {
                const indicatorLabel = document.querySelector('.storage-indicator__label strong');
                const indicatorFill = document.querySelector('.storage-indicator__fill');
                
                if (indicatorLabel) {
                    indicatorLabel.textContent = `${stats.total_size_mb} МБ из ${stats.max_size_mb} МБ`;
                }
                
                if (indicatorFill) {
                    const percentage = Math.min(100, (stats.total_size / (stats.max_size_mb * 1024 * 1024)) * 100);
                    indicatorFill.style.width = percentage + '%';
                }
            };
            
            /**
             * Рендеринг списка документов
             */
            const renderDocumentsList = (documents) => {
                const documentsList = document.getElementById('documents-list');
                if (!documentsList) {
                    return;
                }
                
                if (documents.length === 0) {
                    documentsList.innerHTML = '<div class="documents-empty"><p>Документы не загружены</p></div>';
                    return;
                }
                
                const getFileIcon = (fileType, fileName) => {
                    const ext = fileName.split('.').pop().toLowerCase();
                    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/><path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                    } else if (ext === 'pdf') {
                        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/><line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2"/><line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2"/></svg>';
                    } else {
                        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/></svg>';
                    }
                };
                
                const getIconClass = (fileName) => {
                    const ext = fileName.split('.').pop().toLowerCase();
                    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                        return 'document-icon--image';
                    } else if (ext === 'pdf') {
                        return 'document-icon--pdf';
                    } else if (['doc', 'docx'].includes(ext)) {
                        return 'document-icon--doc';
                    } else if (['xls', 'xlsx'].includes(ext)) {
                        return 'document-icon--xls';
                    } else if (['zip', 'rar', '7z'].includes(ext)) {
                        return 'document-icon--archive';
                    }
                    return 'document-icon--default';
                };
                
                const formatDate = (dateString) => {
                    const date = new Date(dateString);
                    const day = String(date.getDate()).padStart(2, '0');
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const year = date.getFullYear();
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    return `${day}.${month}.${year} ${hours}:${minutes}`;
                };
                
                documentsList.innerHTML = documents.map(doc => `
                    <div class="document-item" data-document-id="${doc.id}">
                        <div class="document-item__icon">
                            <div class="document-icon ${getIconClass(doc.file_name)}">
                                ${getFileIcon(doc.file_type, doc.file_name)}
                            </div>
                        </div>
                        <div class="document-item__info">
                            <div class="document-item__name" title="${doc.file_name.replace(/"/g, '&quot;')}">
                                ${doc.file_name.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                            </div>
                            <div class="document-item__meta">
                                <span>${doc.file_size_mb} МБ</span>
                                <span>•</span>
                                <span>${formatDate(doc.uploaded_at)}</span>
                            </div>
                        </div>
                        <div class="document-item__actions">
                            <button type="button" class="document-item__delete" onclick="handleDocumentDelete(${doc.id})" title="Удалить документ">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                    <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                `).join('');
            };
            
            // Флаг для предотвращения повторной инициализации
            let docUploadInitialized = false;
            
            // Инициализация при загрузке страницы
            // Используем несколько способов для гарантии инициализации
            const initDocUploadWhenReady = () => {
                // Предотвращаем повторную инициализацию
                if (docUploadInitialized) {
                    console.log('⚠️ Document upload already initialized, skipping...');
                    return;
                }
                
                const uploadBtn = document.getElementById('document-upload-btn');
                if (uploadBtn) {
                    console.log('✅ Found upload button, initializing document upload...');
                    if (typeof window.initDocumentUpload === 'function') {
                        window.initDocumentUpload();
                        docUploadInitialized = true;
                    } else {
                        console.error('❌ window.initDocumentUpload is not a function!');
                    }
                } else {
                    console.log('Upload button not found, retrying...');
                    // Если элементы еще не загружены, пробуем еще раз через небольшую задержку
                    setTimeout(initDocUploadWhenReady, 200);
                }
            };
            
            // Инициализация сразу, если DOM уже загружен
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    console.log('DOMContentLoaded, initializing document upload...');
                    setTimeout(initDocUploadWhenReady, 100);
                });
            } else {
                console.log('DOM already loaded, initializing document upload...');
                setTimeout(initDocUploadWhenReady, 100);
            }
            
            // Также инициализируем при переключении между формами
            // (если блок документов появляется динамически)
            const originalSwitchForm = window.switchForm;
            if (typeof originalSwitchForm === 'function') {
                window.switchForm = function(formId) {
                    originalSwitchForm(formId);
                    // Переинициализируем после переключения формы
                    setTimeout(() => {
                        console.log('Form switched, reinitializing document upload...');
                        initDocUploadWhenReady();
                    }, 500);
                };
            }
            
            // Также пробуем инициализировать через MutationObserver для динамически добавляемых элементов
            if (typeof MutationObserver !== 'undefined') {
                const observer = new MutationObserver((mutations) => {
                    const uploadBtn = document.getElementById('document-upload-btn');
                    if (uploadBtn && !uploadBtn.dataset.initialized) {
                        console.log('MutationObserver detected upload button, initializing...');
                        uploadBtn.dataset.initialized = 'true';
                        initDocumentUpload();
                    }
                });
                
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
            
            // Инициализация мобильного меню
            function initMobileMenu() {
                const navToggle = document.querySelector('.nav-toggle');
                const navMenu = document.querySelector('.nav-menu');
                
                if (navToggle && navMenu) {
                    // Удаляем старые обработчики, если они есть
                    const newToggle = navToggle.cloneNode(true);
                    navToggle.parentNode.replaceChild(newToggle, navToggle);
                    
                    newToggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        navMenu.classList.toggle('active');
                        newToggle.classList.toggle('active');
                    });
                    
                    // Закрытие меню при клике на ссылку
                    const navLinks = navMenu.querySelectorAll('a');
                    navLinks.forEach(link => {
                        link.addEventListener('click', function() {
                            navMenu.classList.remove('active');
                            newToggle.classList.remove('active');
                        });
                    });
                    
                    // Закрытие меню при клике вне его
                    document.addEventListener('click', function(e) {
                        if (navMenu.classList.contains('active') && 
                            !navMenu.contains(e.target) && 
                            !newToggle.contains(e.target)) {
                            navMenu.classList.remove('active');
                            newToggle.classList.remove('active');
                        }
                    });
                }
            }
            
            // Инициализируем при загрузке DOM
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initMobileMenu);
            } else {
                initMobileMenu();
            }
            
            // Показ приветственного модального окна
            <?php if ($showWelcomeModal): ?>
            console.log('Welcome modal should be shown');
            (function() {
                function initWelcomeModal() {
                    const welcomeModal = document.getElementById('welcome-modal');
                    const welcomeButton = document.getElementById('welcome-understand-btn');
                    
                    console.log('Welcome modal element:', welcomeModal);
                    console.log('Welcome button element:', welcomeButton);
                    
                    if (welcomeModal && welcomeButton) {
                        // Показываем модальное окно при загрузке страницы
                        welcomeModal.classList.add('active');
                        document.body.style.overflow = 'hidden';
                        console.log('Welcome modal activated');
                        
                        // Обработчик кнопки "Все понятно"
                        welcomeButton.addEventListener('click', function() {
                            console.log('Welcome button clicked');
                            // Отправляем запрос на сохранение флага welcome_shown
                            fetch('mark_welcome_shown.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({})
                            })
                            .then(response => response.json())
                            .then(data => {
                                console.log('Welcome shown response:', data);
                                if (data.success) {
                                    // Закрываем модальное окно
                                    welcomeModal.classList.remove('active');
                                    document.body.style.overflow = '';
                                } else {
                                    console.error('Error marking welcome as shown:', data.error);
                                    // Все равно закрываем модальное окно
                                    welcomeModal.classList.remove('active');
                                    document.body.style.overflow = '';
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                // Все равно закрываем модальное окно
                                welcomeModal.classList.remove('active');
                                document.body.style.overflow = '';
                            });
                        });
                    } else {
                        console.error('Welcome modal or button not found in DOM');
                    }
                }
                
                // Инициализируем после полной загрузки DOM
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initWelcomeModal);
                } else {
                    // DOM уже загружен
                    setTimeout(initWelcomeModal, 100);
                }
            })();
            <?php else: ?>
            console.log('Welcome modal should NOT be shown (showWelcomeModal = false)');
            <?php endif; ?>
    </script>
    
    <script>
        // Обработчик удаления черновиков
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.delete-draft-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const formId = this.getAttribute('data-form-id');
                    const row = this.closest('.table-row');
                    const formName = row ? row.querySelector('strong')?.textContent.trim() || 'черновик' : 'черновик';
                    
                    if (!confirm(`Вы уверены, что хотите удалить черновик "${formName}"? Это действие нельзя отменить.`)) {
                        return;
                    }
                    
                    // Отключаем кнопку на время запроса
                    this.disabled = true;
                    const originalText = this.textContent;
                    this.textContent = 'Удаление...';
                    
                    fetch('delete_draft.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ form_id: formId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Удаляем строку из таблицы с анимацией
                            if (row) {
                                // Сохраняем ссылку на контейнер до удаления строки
                                const draftsContainer = row.closest('.forms-table');
                                
                                row.style.transition = 'opacity 0.3s';
                                row.style.opacity = '0';
                                setTimeout(() => {
                                    row.remove();
                                    
                                    // Проверяем, остались ли черновики
                                    if (draftsContainer) {
                                        const remainingRows = draftsContainer.querySelectorAll('.table-row');
                                        if (remainingRows.length === 0) {
                                            // Если черновиков не осталось, скрываем всю секцию
                                            draftsContainer.style.transition = 'opacity 0.3s';
                                            draftsContainer.style.opacity = '0';
                                            setTimeout(() => {
                                                draftsContainer.remove();
                                            }, 300);
                                        }
                                    }
                                }, 300);
                            }
                        } else {
                            alert('Ошибка при удалении: ' + (data.message || 'Неизвестная ошибка'));
                            this.disabled = false;
                            this.textContent = originalText;
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting draft:', error);
                        alert('Ошибка при удалении черновика. Попробуйте обновить страницу.');
                        this.disabled = false;
                        this.textContent = originalText;
                    });
                });
            });
        });
    </script>
    
    <?php if ($showWelcomeModal): ?>
    <!-- Приветственное модальное окно -->
    <!-- DEBUG: showWelcomeModal = <?php echo $showWelcomeModal ? 'true' : 'false'; ?> -->
    <div id="welcome-modal" class="welcome-modal-overlay">
        <div class="welcome-modal">
            <div class="welcome-modal__header">
                <div class="welcome-modal__logo">SmartBizSell</div>
                <p class="welcome-modal__lead">Добро пожаловать! Мы поможем быстро подготовить материалы и найти инвесторов.</p>
            </div>
            <div class="welcome-modal__content">
                <h1 class="welcome-modal__title">
                    Здравствуйте<?php echo $user['full_name'] ? ', ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') : ''; ?>!
                </h1>
                <p class="welcome-modal__text">Спасибо за регистрацию в SmartBizSell. Вот как получить максимум пользы от платформы:</p>
                <ul class="welcome-modal__steps">
                    <li>
                        <strong>1. Заполните анкету продавца</strong>
                        Расскажите о бизнесе, финансах и целях сделки. Это основа для точной оценки и релевантных инвесторов.
                    </li>
                    <li>
                        <strong>2. Получите материалы автоматически</strong>
                        Платформа соберет тизер, DCF-модель и оценку по мультипликаторам. Вам останется лишь уточнить детали.
                    </li>
                    <li>
                        <strong>3. Пройдите модерацию</strong>
                        Мы быстро проверим материалы и дадим рекомендации, чтобы повысить шансы на сделку.
                    </li>
                    <li>
                        <strong>4. Сформируйте Term Sheet и выходите к инвесторам</strong>
                        Готовые документы и сопроводительные данные — чтобы начать переговоры без задержек.
                    </li>
                </ul>
                <div class="welcome-modal__benefits">
                    <strong>Что вы получаете:</strong>
                    быстрее подготовку документов, прозрачную оценку, готовые материалы для инвесторов и сопровождение команды M&A практиков.
                </div>
                <button id="welcome-understand-btn" class="welcome-modal__button">
                    Все понятно
                </button>
                <p class="welcome-modal__note">
                    Если понадобится помощь — напишите на <a href="mailto:info@smartbizsell.ru">info@smartbizsell.ru</a>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
<?php
} // Конец условия !DCF_API_MODE


