<?php
/**
 * Конфигурация базы данных SmartBizSell
 * Настройте параметры подключения для вашего хостинга reg.ru
 */

// Настройки подключения к MySQL
// Для reg.ru с Percona Server 8.0
define('DB_HOST', 'localhost'); // Localhost via UNIX socket
define('DB_NAME', 'u3064951_SmartBizSell'); // Имя базы данных
define('DB_USER', 'u3064951_default'); // Пользователь БД
define('DB_PASS', 'm6t7EWLS9q89mbRv'); // Пароль БД
define('DB_CHARSET', 'utf8mb4'); // UTF-8 Unicode (utf8mb4)

// Настройки сессий
define('SESSION_LIFETIME', 3600 * 24 * 7); // 7 дней
define('SESSION_NAME', 'smartbizsell_session');

// Настройки безопасности
define('PASSWORD_MIN_LENGTH', 8);
define('BCRYPT_COST', 12);

// Пути
define('BASE_URL', 'https://smartbizsell.ru'); // Замените на ваш домен
define('LOGIN_URL', BASE_URL . '/login.php');
define('DASHBOARD_URL', BASE_URL . '/dashboard.php');

// Настройки почты (для уведомлений)
define('ADMIN_EMAIL', 'admin@smartbizsell.ru'); // Замените на ваш email

// Настройки модерации
define('MODERATOR_EMAIL', getenv('MODERATOR_EMAIL') ?: 'drvinogradov@yahoo.com'); // Email модератора

// Настройки SMTP для отправки email
// 
// ВАЖНО: Для избежания попадания писем в спам необходимо настроить DNS записи:
// 
// 1. SPF запись (TXT запись для домена):
//    v=spf1 mx a:mail.smartbizsell.ru ip4:IP_АДРЕС_СЕРВЕРА ~all
//    Пример: v=spf1 mx a:mail.smartbizsell.ru ~all
//    Проверка: nslookup -type=TXT smartbizsell.ru
// 
// 2. DKIM запись (настраивается в панели управления хостингом):
//    Обычно создается автоматически при настройке почты на хостинге
//    Формат: selector._domainkey.smartbizsell.ru
//    Проверка: nslookup -type=TXT default._domainkey.smartbizsell.ru
// 
// 3. DMARC запись (TXT запись для _dmarc.smartbizsell.ru):
//    v=DMARC1; p=none; rua=mailto:admin@smartbizsell.ru; ruf=mailto:admin@smartbizsell.ru; fo=1
//    Начните с p=none для мониторинга, затем перейдите на p=quarantine или p=reject
//    Проверка: nslookup -type=TXT _dmarc.smartbizsell.ru
// 
// 4. Обратная DNS запись (PTR):
//    IP адрес сервера должен иметь обратную запись на mail.smartbizsell.ru
//    Проверка: nslookup IP_АДРЕС_СЕРВЕРА
// 
// 5. Проверка всех настроек:
//    - https://mxtoolbox.com/spf.aspx (проверка SPF)
//    - https://mxtoolbox.com/dkim.aspx (проверка DKIM)
//    - https://mxtoolbox.com/dmarc.aspx (проверка DMARC)
//    - https://www.mail-tester.com/ (общая проверка доставляемости)
// 
// Эти записи настраиваются в DNS зоне домена smartbizsell.ru через панель управления хостингом
define('SMTP_HOST', 'mail.hosting.reg.ru');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls'); // tls для порта 587
define('SMTP_USER', 'no-reply@smartbizsell.ru');
define('SMTP_PASS', getenv('SMTP_PASS') ?: '!No-reply2025');
define('SMTP_FROM_EMAIL', 'no-reply@smartbizsell.ru');
define('SMTP_FROM_NAME', 'SmartBizSell');

// Настройки восстановления пароля
define('PASSWORD_RESET_TOKEN_LIFETIME', 3600); // 1 час в секундах

// Настройки AI (together.ai + Qwen)
// Можно переопределить через переменные окружения TOGETHER_API_KEY и TOGETHER_MODEL
define('TOGETHER_API_KEY', getenv('TOGETHER_API_KEY') ?: 'c0bf29d89744dd1e091c9eca2b1cfeda9d7af4dacedadcf82872b4698d8365ba');
define('TOGETHER_MODEL', getenv('TOGETHER_MODEL') ?: 'Qwen/Qwen3-Next-80B-A3B-Instruct');

// Параметры для Together.ai
// Можно переопределить через переменные окружения
define('TOGETHER_MAX_TOKENS_NORMAL', (int)(getenv('TOGETHER_MAX_TOKENS_NORMAL') ?: 2500)); // Увеличено для полных тизеров без обрезки
define('TOGETHER_MAX_TOKENS_LONG', (int)(getenv('TOGETHER_MAX_TOKENS_LONG') ?: 8000));
define('TOGETHER_TEMPERATURE', (float)(getenv('TOGETHER_TEMPERATURE') ?: 0.15)); // Чуть ниже для стабильности текста
define('TOGETHER_TOP_P', (float)(getenv('TOGETHER_TOP_P') ?: 0.9));

// Настройки Alibaba Cloud Qwen 3 Max
// Можно переопределить через переменные окружения
define('ALIBABA_API_KEY', getenv('ALIBABA_API_KEY') ?: 'sk-bfcf015974d0414281c1d9904e5e1f12');
// Используем qwen-turbo для скорости (быстрее qwen-max, но все еще качественный)
// Альтернативы: 'qwen3-max' (качество), 'qwen-plus' (баланс), 'qwen-turbo' (скорость)
define('ALIBABA_MODEL', getenv('ALIBABA_MODEL') ?: 'qwen3-max');
define('ALIBABA_BASE_URL', 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1');
define('ALIBABA_ENDPOINT', ALIBABA_BASE_URL . '/chat/completions');

// Параметры оптимизации скорости для Alibaba Cloud
// Можно переопределить через переменные окружения
define('ALIBABA_MAX_TOKENS_NORMAL', (int)(getenv('ALIBABA_MAX_TOKENS_NORMAL') ?: 2500)); // Увеличено для полных тизеров без обрезки
define('ALIBABA_MAX_TOKENS_LONG', (int)(getenv('ALIBABA_MAX_TOKENS_LONG') ?: 8000));
define('ALIBABA_TEMPERATURE', (float)(getenv('ALIBABA_TEMPERATURE') ?: 0.2)); // Чуть ниже для стабильности текста
define('ALIBABA_TOP_P', (float)(getenv('ALIBABA_TOP_P') ?: 0.9));

// Выбор провайдера AI по умолчанию: 'together' или 'alibaba'
// Можно переопределить через переменную окружения AI_PROVIDER
// Также можно изменить через интерфейс модерации (сохраняется в сессии)
define('DEFAULT_AI_PROVIDER', getenv('AI_PROVIDER') ?: 'together');

// Настройки загрузки документов
define('MAX_DOCUMENTS_SIZE_PER_ASSET', 20 * 1024 * 1024); // 20 МБ в байтах
// Разрешенные типы файлов (MIME типы)
define('ALLOWED_DOCUMENT_TYPES', [
    // Документы
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation', // .pptx
    // Изображения
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    // Архивы
    'application/zip',
    'application/x-rar-compressed',
    'application/x-7z-compressed',
    'application/gzip',
    // Текстовые файлы
    'text/plain',
    'text/csv',
]);

/**
 * Подключение к базе данных
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Ошибка подключения к базе данных. Пожалуйста, обратитесь к администратору.");
        }
    }
    
    return $pdo;
}

/**
 * Инициализация сессии
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        // Используем HTTPS только если сайт работает по HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            ini_set('session.cookie_secure', 1);
        }
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        
        session_name(SESSION_NAME);
        session_start();
        
        // Сохраняем выбор провайдера перед возможным уничтожением сессии
        $savedProvider = null;
        if (isset($_SESSION['ai_provider']) && in_array($_SESSION['ai_provider'], ['together', 'alibaba'])) {
            $savedProvider = $_SESSION['ai_provider'];
        }
        
        // Обновление времени жизни сессии
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity'] > SESSION_LIFETIME)) {
            session_destroy();
            session_start();
            
            // Восстанавливаем выбор провайдера после пересоздания сессии
            if ($savedProvider !== null) {
                $_SESSION['ai_provider'] = $savedProvider;
            }
        }
        
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Проверка авторизации пользователя
 */
function isLoggedIn() {
    initSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

/**
 * Получение данных текущего пользователя
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, email, full_name, phone, company_name, created_at FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting current user: " . $e->getMessage());
        return null;
    }
}

/**
 * Редирект на страницу входа
 */
function redirectToLogin() {
    header('Location: ' . LOGIN_URL);
    exit;
}

/**
 * Редирект в личный кабинет
 */
function redirectToDashboard() {
    header('Location: ' . DASHBOARD_URL);
    exit;
}

/**
 * Проверяет, является ли текущий пользователь модератором
 * 
 * @return bool true если пользователь модератор, false иначе
 */
/**
 * Проверяет, является ли текущий пользователь модератором
 * 
 * Модератор определяется по email, который должен совпадать с константой MODERATOR_EMAIL.
 * Модераторы имеют доступ к интерфейсу модерации тизеров (moderation.php) и могут
 * редактировать, одобрять, отклонять и публиковать тизеры на главной странице.
 * 
 * @return bool true если пользователь авторизован и является модератором, false в противном случае
 */
function isModerator() {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    // Проверяем по email (сравнение без учета регистра)
    return isset($user['email']) && strtolower(trim($user['email'])) === strtolower(trim(MODERATOR_EMAIL));
}

/**
 * Проверяет и создает таблицу published_teasers, если она не существует
 * 
 * Таблица published_teasers используется для хранения тизеров, отправленных на модерацию.
 * Содержит следующие поля:
 * - id: Уникальный идентификатор записи
 * - seller_form_id: Ссылка на анкету продавца
 * - moderated_html: Отредактированная версия HTML тизера
 * - moderation_status: Статус модерации ('pending', 'approved', 'rejected', 'published')
 * - moderator_id: ID модератора, который обработал тизер
 * - moderation_notes: Заметки модератора (особенно при отклонении)
 * - moderated_at: Дата и время модерации
 * - published_at: Дата и время публикации на главной странице
 * - created_at, updated_at: Временные метки создания и обновления
 * 
 * Функция вызывается автоматически при работе с модерацией, чтобы гарантировать
 * существование таблицы без необходимости ручной миграции.
 * 
 * @return bool true если таблица создана или уже существует, false при ошибке
 */
function ensurePublishedTeasersTable() {
    try {
        $pdo = getDBConnection();
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS published_teasers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seller_form_id INT NOT NULL,
                moderated_html TEXT DEFAULT NULL COMMENT 'Отредактированная версия тизера',
                moderation_status ENUM('pending', 'approved', 'rejected', 'published') DEFAULT 'pending',
                moderator_id INT DEFAULT NULL COMMENT 'ID модератора из таблицы users',
                moderation_notes TEXT DEFAULT NULL COMMENT 'Заметки модератора',
                moderated_at TIMESTAMP NULL DEFAULT NULL,
                published_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (seller_form_id) REFERENCES seller_forms(id) ON DELETE CASCADE,
                FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE SET NULL,
                
                INDEX idx_seller_form_id (seller_form_id),
                INDEX idx_moderation_status (moderation_status),
                INDEX idx_moderator_id (moderator_id),
                INDEX idx_published_at (published_at),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating published_teasers table: " . $e->getMessage());
        return false;
    }
}

/**
 * Хеширование пароля
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

/**
 * Проверка пароля
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Санитизация данных
 * Очищает данные от HTML-тегов и лишних пробелов, но НЕ экранирует HTML-сущности
 * HTML-экранирование должно происходить только при выводе данных (через htmlspecialchars)
 */
function sanitizeInput($data) {
    return strip_tags(trim($data));
}

/**
 * Валидация email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Проверяет и добавляет поле welcome_shown в таблицу users, если его нет
 * 
 * Поле используется для отслеживания показа приветственного окна новым пользователям.
 * 
 * @return bool true если поле добавлено или уже существует, false при ошибке
 */
function ensureUsersWelcomeField() {
    try {
        $pdo = getDBConnection();
        
        // Проверяем, существует ли поле welcome_shown
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'welcome_shown'");
        if ($stmt->rowCount() == 0) {
            // Добавляем поле welcome_shown
            $pdo->exec("
                ALTER TABLE users 
                ADD COLUMN welcome_shown TINYINT(1) DEFAULT 0 
                COMMENT 'Флаг показа приветственного окна (0 - не показано, 1 - показано)'
            ");
        }
        
        return true;
    } catch (PDOException $e) {
        error_log("Error ensuring welcome_shown field: " . $e->getMessage());
        return false;
    }
}

/**
 * Проверяет и создает таблицу password_reset_tokens, если она не существует
 * 
 * Таблица используется для хранения токенов восстановления пароля.
 * 
 * @return bool true если таблица создана или уже существует, false при ошибке
 */
function ensurePasswordResetTokensTable() {
    try {
        $pdo = getDBConnection();
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL COMMENT 'Хеш токена (SHA-256)',
                expires_at TIMESTAMP NOT NULL COMMENT 'Время истечения токена',
                used_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Время использования токена (NULL если не использован)',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                
                INDEX idx_token (token),
                INDEX idx_user_id (user_id),
                INDEX idx_expires_at (expires_at),
                INDEX idx_used_at (used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating password_reset_tokens table: " . $e->getMessage());
        return false;
    }
}

/**
 * Генерация токена для восстановления пароля
 * 
 * @param int $userId ID пользователя
 * @return string|false Токен в виде строки или false при ошибке
 */
function generatePasswordResetToken($userId) {
    try {
        $pdo = getDBConnection();
        ensurePasswordResetTokensTable();
        
        // Генерируем случайный токен (64 символа)
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        
        // Удаляем старые неиспользованные токены для этого пользователя
        $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL");
        $stmt->execute([$userId]);
        
        // Сохраняем новый токен
        $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TOKEN_LIFETIME);
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_tokens (user_id, token, expires_at) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $tokenHash, $expiresAt]);
        
        return $token;
    } catch (PDOException $e) {
        error_log("Error generating password reset token: " . $e->getMessage());
        return false;
    }
}

/**
 * Проверка и валидация токена восстановления пароля
 * 
 * @param string $token Токен для проверки
 * @return array|false Массив с данными токена (user_id, expires_at) или false если токен невалиден
 */
function validatePasswordResetToken($token) {
    try {
        $pdo = getDBConnection();
        ensurePasswordResetTokensTable();
        
        $tokenHash = hash('sha256', $token);
        
        $stmt = $pdo->prepare("
            SELECT user_id, expires_at, used_at 
            FROM password_reset_tokens 
            WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$tokenHash]);
        $tokenData = $stmt->fetch();
        
        if (!$tokenData) {
            return false;
        }
        
        return $tokenData;
    } catch (PDOException $e) {
        error_log("Error validating password reset token: " . $e->getMessage());
        return false;
    }
}

/**
 * Помечает токен восстановления пароля как использованный
 * 
 * @param string $token Токен для пометки
 * @return bool true при успехе, false при ошибке
 */
function markPasswordResetTokenAsUsed($token) {
    try {
        $pdo = getDBConnection();
        $tokenHash = hash('sha256', $token);
        
        $stmt = $pdo->prepare("
            UPDATE password_reset_tokens 
            SET used_at = NOW() 
            WHERE token = ?
        ");
        $stmt->execute([$tokenHash]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error marking password reset token as used: " . $e->getMessage());
        return false;
    }
}

/**
 * Отправка email через SMTP с использованием PHPMailer
 * 
 * Оптимизировано для избежания попадания в спам:
 * - Правильные заголовки для SPF/DKIM/DMARC
 * - Уникальный Message-ID для каждого письма
 * - Корректная структура multipart/alternative
 * - Убраны заголовки, помечающие письма как массовые рассылки
 * 
 * @param string $to Email получателя
 * @param string $subject Тема письма
 * @param string $body Тело письма (HTML)
 * @param string $altBody Альтернативный текстовый вариант (опционально)
 * @param string|null $fromEmail Произвольный адрес отправителя (по умолчанию SMTP_FROM_EMAIL)
 * @param string|null $fromName  Произвольное имя отправителя (по умолчанию SMTP_FROM_NAME)
 * @return bool true при успешной отправке, false при ошибке
 */
function sendEmail($to, $subject, $body, $altBody = '', $fromEmail = null, $fromName = null) {
    $fromEmail = $fromEmail ?: SMTP_FROM_EMAIL;
    $fromName  = $fromName  ?: SMTP_FROM_NAME;
    
    // Пытаемся загрузить PHPMailer через Composer
    $phpmailerPath = __DIR__ . '/vendor/autoload.php';
    if (file_exists($phpmailerPath)) {
        require_once $phpmailerPath;
    } else {
        // Fallback без Composer: подключаем PHPMailer напрямую, если он загружен вручную
        $phpmailerBase = __DIR__ . '/vendor/PHPMailer/src';
        $phpmailerFiles = [
            $phpmailerBase . '/PHPMailer.php',
            $phpmailerBase . '/SMTP.php',
            $phpmailerBase . '/Exception.php',
        ];
        $allExists = true;
        foreach ($phpmailerFiles as $file) {
            if (!file_exists($file)) {
                $allExists = false;
                break;
            }
        }
        if ($allExists) {
            require_once $phpmailerFiles[0];
            require_once $phpmailerFiles[1];
            require_once $phpmailerFiles[2];
        }
    }
    
    // Проверяем наличие PHPMailer
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Включаем SMTPDebug только при ошибках (уровень 0 = отключен, 1 = только ошибки)
            // Для детальной отладки можно установить 2 (client/server conversation)
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = function($str, $level) {
                // Логируем только ошибки (level 1 и выше)
                if ($level >= 1) {
                    error_log("SMTP Debug (level $level): " . trim($str));
                }
            };
            
            // Настройки SMTP
            $mail->isSMTP();
            $smtpHost = SMTP_HOST;
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->CharSet = 'UTF-8';
            // Явно указываем кодировку для корректной обработки кириллицы
            // base64 лучше для UTF-8, чем quoted-printable, и предотвращает проблемы с MIXED_ES
            $mail->Encoding = 'base64';
            
            // Настройки SSL для безопасности
            // Проверка сертификата важна для правильной работы SPF/DKIM/DMARC
            // Но на некоторых хостингах может быть недоступен CA bundle
            $sslOptions = array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                // Ensure cert CN/SAN is checked against hostname, not IP
                'peer_name' => $smtpHost,
            );
            
            // Пытаемся найти CA bundle в стандартных местах
            $caPaths = array(
                '/etc/ssl/certs/ca-certificates.crt',  // Debian/Ubuntu
                '/etc/pki/tls/certs/ca-bundle.crt',    // CentOS/RHEL
                '/usr/local/etc/openssl/cert.pem',     // macOS (Homebrew)
                '/etc/ssl/cert.pem',                   // Альтернативный путь
            );
            
            foreach ($caPaths as $caPath) {
                if (file_exists($caPath)) {
                    $sslOptions['cafile'] = $caPath;
                    break;
                }
            }
            
            // Если CA bundle не найден, отключаем проверку (только для fallback)
            // В production лучше настроить правильный путь к CA bundle
            if (!isset($sslOptions['cafile'])) {
                error_log("Warning: CA bundle not found, SSL verification may fail");
                // На некоторых хостингах можно использовать системный bundle
                $sslOptions['verify_peer'] = false;
                $sslOptions['verify_peer_name'] = false;
            }
            
            $mail->SMTPOptions = array('ssl' => $sslOptions);
            
            // Отправитель и получатель
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            
            // Reply-To должен совпадать с From для транзакционных писем
            // Это улучшает доставляемость
            $mail->addReplyTo($fromEmail, $fromName);
            
            // Return-Path (обязателен для SPF)
            $mail->Sender = $fromEmail;
            
            // Убираем X-Mailer или делаем его нейтральным
            $mail->XMailer = '';
            
            // Генерируем уникальный Message-ID для каждого письма
            // Формат: <timestamp.random@domain>
            $messageId = '<' . time() . '.' . uniqid() . '@' . parse_url(BASE_URL, PHP_URL_HOST) . '>';
            $mail->MessageID = $messageId;
            
            // Приоритет письма (нормальный, не срочный)
            $mail->Priority = 3;
            
            // Содержимое письма
            $mail->isHTML(true);
            // Кодируем тему в UTF-8 для корректного отображения кириллицы
            $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
            $mail->Body = $body;
            
            // Текстовая версия обязательна для избежания спама
            // Улучшаем текстовую версию: убираем лишние пробелы и переносы
            if (!empty($altBody)) {
                $mail->AltBody = $altBody;
            } else {
                // Создаем улучшенную текстовую версию из HTML
                $textBody = strip_tags($body);
                // Убираем множественные пробелы и переносы строк
                $textBody = preg_replace('/\s+/', ' ', $textBody);
                $textBody = trim($textBody);
                $mail->AltBody = $textBody;
            }
            
            // Важные заголовки для избежания спама
            // Message-ID уже установлен выше
            
            // Date заголовок устанавливается автоматически PHPMailer в правильном формате RFC 2822
            // НЕ добавляем его вручную через addCustomHeader, чтобы избежать дублирования или неправильного формата
            // PHPMailer автоматически устанавливает Date при вызове send() в формате: "Date: Mon, 1 Jan 2024 12:00:00 +0000"
            
            // MIME-Version (автоматически устанавливается PHPMailer)
            
            // Content-Type (автоматически устанавливается PHPMailer для multipart/alternative)
            
            // DKIM подпись: reg.ru должен автоматически подписывать письма при отправке через SMTP
            // Если DKIM_INVALID в SpamAssassin, проверьте настройки DKIM в панели reg.ru
            // Убедитесь, что DKIM включен для домена smartbizsell.ru
            
            // УБИРАЕМ заголовки, которые могут помечать письма как спам:
            // - List-Unsubscribe (только для массовых рассылок)
            // - Precedence: bulk (помечает как массовую рассылку)
            // - Auto-Submitted: auto-generated (помечает как автоматическое письмо)
            
            // Добавляем заголовки для транзакционных писем (не массовых рассылок):
            // X-Auto-Response-Suppress предотвращает автоответы, но не помечает как спам
            $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
            
            // Для транзакционных писем (восстановление пароля) используем:
            // Auto-Submitted: auto-replied (для автоматических ответов)
            // Но для транзакционных писем лучше вообще не ставить Auto-Submitted
            // или использовать значение "no" для явного указания, что это не автоответ
            
            // Улучшаем репутацию отправителя:
            // Добавляем заголовок с доменом для идентификации
            $mail->addCustomHeader('X-Mailer-Domain', parse_url(BASE_URL, PHP_URL_HOST));
            
            // Добавляем заголовок для отслеживания транзакционных писем
            $mail->addCustomHeader('X-Transaction-ID', uniqid());
            
            // Отправляем письмо и проверяем результат
            $result = $mail->send();
            if (!$result) {
                error_log("Email sending failed: " . $mail->ErrorInfo);
                return false;
            }
            
            error_log("Email successfully sent to: $to");
            return true;
        } catch (Exception $e) {
            error_log("Email sending error (PHPMailer): " . $mail->ErrorInfo);
            error_log("Exception: " . $e->getMessage());
            return false;
        }
    } else {
        // Без PHPMailer не отправляем письма напрямую, чтобы не ломать SPF/DKIM/DMARC
        error_log("PHPMailer not found: SMTP disabled. Install PHPMailer to send email via reg.ru SMTP.");
        return false;
    }
}

/**
 * Рендеринг email-шаблона с подстановкой переменных
 *
 * @param string $path Путь к файлу шаблона
 * @param array  $vars Ассоциативный массив переменных
 * @return string Содержимое шаблона или пустая строка при ошибке
 */
function renderEmailTemplate(string $path, array $vars = []): string
{
    if (!file_exists($path)) {
        error_log("Email template not found: {$path}");
        return '';
    }
    extract($vars, EXTR_OVERWRITE);
    ob_start();
    include $path;
    return ob_get_clean();
}

/**
 * Отправка приветственного письма новому пользователю
 *
 * @param string      $email Email получателя
 * @param string|null $fullName Имя получателя (опционально)
 * @return bool Результат отправки (логируем ошибку, но не выбрасываем)
 */
function sendWelcomeEmail(string $email, ?string $fullName = null): bool
{
    $templateDir = __DIR__ . '/email_templates';
    $htmlPath    = $templateDir . '/welcome_ru.html';
    $textPath    = $templateDir . '/welcome_ru.txt';
    
    $vars = [
        'recipientName' => $fullName,
    ];
    
    $htmlBody = renderEmailTemplate($htmlPath, $vars);
    $textBody = renderEmailTemplate($textPath, $vars);
    
    if (empty($htmlBody)) {
        error_log('Welcome email HTML template is empty.');
        return false;
    }
    
    // Страхуемся: если нет текстовой версии — сгенерируем из HTML
    if (empty($textBody)) {
        $textBody = trim(preg_replace('/\s+/', ' ', strip_tags($htmlBody)));
    }
    
    $subject = 'Добро пожаловать в SmartBizSell — инструкция по использованию платформы';
    $fromEmail = 'info@smartbizsell.ru';
    $fromName  = 'SmartBizSell';
    
    $sent = sendEmail($email, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
    if (!$sent) {
        error_log("Failed to send welcome email to {$email}");
    }
    return $sent;
}

/**
 * Генерация SVG иконки логотипа SmartBizSell
 * Символизирует рост бизнеса и M&A сделки
 * 
 * @return string SVG код иконки
 */
function getLogoIcon() {
    return '<svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" class="logo-svg-icon">
        <defs>
            <linearGradient id="logoGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#667EEA;stop-opacity:1" />
                <stop offset="50%" style="stop-color:#764BA2;stop-opacity:1" />
                <stop offset="100%" style="stop-color:#F093FB;stop-opacity:1" />
            </linearGradient>
        </defs>
        <!-- График роста (линия) -->
        <path d="M4 20 L8 16 L12 18 L16 12 L20 14 L24 8" stroke="url(#logoGradient)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        <!-- Стрелка вверх на конце графика -->
        <path d="M22 10 L24 8 L22 6 M24 8 L20 8" stroke="url(#logoGradient)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        <!-- Точки на графике -->
        <circle cx="8" cy="16" r="2.5" fill="url(#logoGradient)"/>
        <circle cx="12" cy="18" r="2.5" fill="url(#logoGradient)"/>
        <circle cx="16" cy="12" r="2.5" fill="url(#logoGradient)"/>
        <circle cx="20" cy="14" r="2.5" fill="url(#logoGradient)"/>
        <circle cx="24" cy="8" r="2.5" fill="url(#logoGradient)"/>
    </svg>';
}

/**
 * Проверяет и создает таблицу asset_documents, если она не существует
 * 
 * Таблица asset_documents используется для хранения информации о загруженных документах,
 * привязанных к активам (seller_forms).
 * 
 * @return bool True при успехе, false при ошибке
 */
function ensureAssetDocumentsTable(): bool
{
    try {
        $pdo = getDBConnection();
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS asset_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seller_form_id INT NOT NULL COMMENT 'ID анкеты продавца (актива)',
                user_id INT NOT NULL COMMENT 'ID пользователя, загрузившего документ (для проверки прав доступа)',
                file_name VARCHAR(255) NOT NULL COMMENT 'Оригинальное имя файла',
                file_path VARCHAR(500) NOT NULL COMMENT 'Путь к файлу на сервере',
                file_size INT NOT NULL COMMENT 'Размер файла в байтах',
                file_type VARCHAR(100) NOT NULL COMMENT 'MIME тип файла',
                uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время загрузки',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего обновления',
                
                FOREIGN KEY (seller_form_id) REFERENCES seller_forms(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                
                INDEX idx_seller_form_id (seller_form_id),
                INDEX idx_user_id (user_id),
                INDEX idx_uploaded_at (uploaded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Документы, привязанные к активам на продажу'
        ");
        
        return true;
    } catch (PDOException $e) {
        error_log("Error creating asset_documents table: " . $e->getMessage());
        return false;
    }
}

/**
 * Получает путь к папке для хранения документов активов
 * 
 * @return string Абсолютный путь к папке uploads/asset_documents/
 */
function getAssetDocumentsPath(): string
{
    return __DIR__ . '/uploads/asset_documents';
}

/**
 * Создает папку для документов конкретного актива, если она не существует
 * 
 * @param int $sellerFormId ID анкеты продавца
 * @return string Путь к папке актива
 */
function ensureAssetDocumentsFolder(int $sellerFormId): string
{
    $basePath = getAssetDocumentsPath();
    $assetPath = $basePath . '/' . (int)$sellerFormId;
    
    if (!is_dir($assetPath)) {
        mkdir($assetPath, 0755, true);
    }
    
    return $assetPath;
}

/**
 * Валидация загруженного файла
 * 
 * Проверяет:
 * - Тип файла (MIME type)
 * - Размер файла
 * - Общий размер документов актива (не должен превышать лимит)
 * 
 * @param array $file Массив $_FILES['file']
 * @param int $sellerFormId ID анкеты продавца
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validateUploadedFile(array $file, int $sellerFormId): array
{
    // Проверка наличия файла
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'error' => 'Файл не был загружен.'];
    }
    
    // Проверка ошибок загрузки
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, разрешенный сервером.',
            UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме.',
            UPLOAD_ERR_PARTIAL => 'Файл был загружен частично.',
            UPLOAD_ERR_NO_FILE => 'Файл не был загружен.',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка.',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск.',
            UPLOAD_ERR_EXTENSION => 'Загрузка файла была остановлена расширением PHP.',
        ];
        return ['valid' => false, 'error' => $errorMessages[$file['error']] ?? 'Неизвестная ошибка загрузки файла.'];
    }
    
    // Проверка размера файла
    if ($file['size'] > MAX_DOCUMENTS_SIZE_PER_ASSET) {
        return ['valid' => false, 'error' => 'Размер файла превышает максимальный лимит (20 МБ).'];
    }
    
    // Проверка типа файла
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_DOCUMENT_TYPES, true)) {
        return ['valid' => false, 'error' => 'Тип файла не разрешен. Разрешены: PDF, DOC, DOCX, XLS, XLSX, изображения, архивы.'];
    }
    
    // Проверка общего размера документов актива
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(file_size), 0) as total_size
            FROM asset_documents
            WHERE seller_form_id = ?
        ");
        $stmt->execute([$sellerFormId]);
        $result = $stmt->fetch();
        $currentTotalSize = (int)($result['total_size'] ?? 0);
        
        if ($currentTotalSize + $file['size'] > MAX_DOCUMENTS_SIZE_PER_ASSET) {
            $usedMB = round($currentTotalSize / 1024 / 1024, 2);
            $maxMB = round(MAX_DOCUMENTS_SIZE_PER_ASSET / 1024 / 1024, 2);
            return ['valid' => false, 'error' => "Общий размер документов актива превысит лимит ({$maxMB} МБ). Использовано: {$usedMB} МБ."];
        }
    } catch (PDOException $e) {
        error_log("Error checking total documents size: " . $e->getMessage());
        // Продолжаем, если не удалось проверить (таблица может не существовать)
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Санитизирует имя файла для безопасного хранения
 * 
 * Удаляет опасные символы и ограничивает длину имени файла
 * 
 * @param string $filename Оригинальное имя файла
 * @return string Безопасное имя файла
 */
function sanitizeFileName(string $filename): string
{
    // Удаляем путь, оставляем только имя файла
    $filename = basename($filename);
    
    // Удаляем опасные символы
    $filename = preg_replace('/[^a-zA-Z0-9._-]/u', '_', $filename);
    
    // Ограничиваем длину (255 символов - максимум для VARCHAR)
    if (mb_strlen($filename) > 200) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $filename = mb_substr($name, 0, 200 - mb_strlen($ext) - 1) . '.' . $ext;
    }
    
    return $filename;
}

/**
 * Получает текущий выбранный провайдер AI
 * 
 * Проверяет сессию, затем константу DEFAULT_AI_PROVIDER
 * 
 * @return string 'together' или 'alibaba'
 */
function getCurrentAIProvider(): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION['ai_provider']) && in_array($_SESSION['ai_provider'], ['together', 'alibaba'])) {
            return $_SESSION['ai_provider'];
        }
    }
    return DEFAULT_AI_PROVIDER;
}

/**
 * Устанавливает провайдера AI в сессию
 * 
 * @param string $provider 'together' или 'alibaba'
 * @return bool true если успешно установлено
 */
function setAIProvider(string $provider): bool
{
    if (!in_array($provider, ['together', 'alibaba'])) {
        return false;
    }
    
    if (session_status() !== PHP_SESSION_ACTIVE) {
        initSession();
    }
    
    $_SESSION['ai_provider'] = $provider;
    return true;
}

/**
 * Расчет WACC с помощью ИИ на основе отраслевой принадлежности компании
 * 
 * WACC определяется в диапазоне 18-22%:
 * - Для капиталоёмких секторов с высокой долговой нагрузкой (металлургия, строительство) - ближе к 18%
 * - Для секторов с низкой долговой нагрузкой (TMT, IT) - ближе к 22%
 * 
 * @param array $formData Данные анкеты продавца
 * @return float Значение WACC в диапазоне 0.18-0.22 (fallback: 0.20)
 */
function calculateWACCWithAI(array $formData): float
{
    // Извлекаем информацию об отрасли из данных анкеты
    $assetName = $formData['asset_name'] ?? '';
    $companyDescription = $formData['company_description'] ?? '';
    $productsServices = $formData['products_services'] ?? '';
    
    // Пытаемся извлечь данные из data_json, если они там есть
    if (empty($assetName) && !empty($formData['data_json'])) {
        $decoded = json_decode($formData['data_json'], true);
        if (is_array($decoded)) {
            $assetName = $decoded['asset_name'] ?? $assetName;
            $companyDescription = $decoded['company_description'] ?? $companyDescription;
            $productsServices = $decoded['products_services'] ?? $productsServices;
        }
    }
    
    // Если нет данных об отрасли, используем fallback
    if (empty($assetName) && empty($companyDescription) && empty($productsServices)) {
        error_log('WACC AI: Нет данных об отрасли компании, используется fallback 20%');
        return 0.20;
    }
    
    // Формируем промпт для ИИ
    $prompt = "Определи отраслевую принадлежность компании на основе следующей информации:\n";
    if (!empty($assetName)) {
        $prompt .= "- Название актива: " . trim($assetName) . "\n";
    }
    if (!empty($companyDescription)) {
        $prompt .= "- Описание компании: " . trim($companyDescription) . "\n";
    }
    if (!empty($productsServices)) {
        $prompt .= "- Продукты/услуги: " . trim($productsServices) . "\n";
    }
    
    $prompt .= "\nWACC задаётся в диапазоне 18–22%. Конкретное значение выбирается в зависимости от отраслевой принадлежности компании.\n";
    $prompt .= "Для капиталоёмких секторов с высокой долговой нагрузкой (металлургия, строительство и т. п.) WACC принимается ближе к нижней границе диапазона (18%).\n";
    $prompt .= "Для секторов с низкой долговой нагрузкой (TMT, IT и т. п.) используется значение ближе к верхней границе диапазона (22%).\n\n";
    $prompt .= "Ответь ТОЛЬКО числом в формате процента (например, 19.5 или 21.0), без дополнительных пояснений.";
    
    try {
        // Вызываем ИИ через chat completions
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $response = callAIChatCompletions($messages, null, 2);
        
        // Парсим ответ - извлекаем числовое значение
        $wacc = parseWACCFromResponse($response);
        
        // Валидация диапазона
        if ($wacc < 0.18) {
            error_log("WACC AI: Получено значение ниже минимума ($wacc), устанавливаем 18%");
            $wacc = 0.18;
        } elseif ($wacc > 0.22) {
            error_log("WACC AI: Получено значение выше максимума ($wacc), устанавливаем 22%");
            $wacc = 0.22;
        }
        
        error_log("WACC AI: Успешно рассчитано значение WACC = " . ($wacc * 100) . "%");
        return $wacc;
        
    } catch (Exception $e) {
        error_log("WACC AI: Ошибка при вызове ИИ: " . $e->getMessage());
        return 0.20; // Fallback на середину диапазона
    } catch (Throwable $e) {
        error_log("WACC AI: Критическая ошибка: " . $e->getMessage());
        return 0.20; // Fallback на середину диапазона
    }
}

/**
 * Парсит значение WACC из ответа ИИ
 * 
 * @param string $response Ответ от ИИ
 * @return float Значение WACC (0.18-0.22) или 0.20 при ошибке парсинга
 */
function parseWACCFromResponse(string $response): float
{
    // Очищаем ответ от лишних символов
    $response = trim($response);
    
    // Удаляем знаки процента и другие нечисловые символы, кроме точки и запятой
    $response = preg_replace('/[^\d.,]/', '', $response);
    
    // Заменяем запятую на точку
    $response = str_replace(',', '.', $response);
    
    // Извлекаем первое число из строки
    if (preg_match('/(\d+\.?\d*)/', $response, $matches)) {
        $value = (float)$matches[1];
        
        // Если значение больше 1, предполагаем что это процент (например, 19.5 означает 19.5%)
        // Конвертируем в десятичную дробь
        if ($value > 1) {
            $value = $value / 100;
        }
        
        // Валидация диапазона
        if ($value >= 0.18 && $value <= 0.22) {
            return $value;
        } elseif ($value > 0.22) {
            // Если значение слишком большое, возможно это процент без деления на 100
            $value = $value / 100;
            if ($value >= 0.18 && $value <= 0.22) {
                return $value;
            }
        }
    }
    
    // Если не удалось распарсить, возвращаем fallback
    error_log("WACC AI: Не удалось распарсить ответ: " . $response);
    return 0.20;
}

/**
 * Вызов Alibaba Cloud Qwen 3 Max API (OpenAI-совместимый формат)
 * 
 * @param string $prompt Промпт для отправки в модель
 * @param string $apiKey API ключ Alibaba Cloud
 * @param int $maxRetries Максимальное количество попыток при ошибках
 * @param array|null $usedModelInfo Массив для записи информации о использованной модели (заполняется по ссылке)
 * @return string Текст ответа модели
 * @throws RuntimeException При ошибках API или сети
 */
function callAlibabaCloudCompletions(string $prompt, string $apiKey, int $maxRetries = 3, ?array &$usedModelInfo = null): string
{
    // Валидация входных данных
    if (empty($prompt)) {
        throw new RuntimeException('Промпт не может быть пустым');
    }
    if (empty($apiKey)) {
        throw new RuntimeException('API ключ не может быть пустым');
    }
    
    // Ограничиваем длину промпта
    if (strlen($prompt) > 50000) {
        error_log('Warning: Prompt is very long (' . strlen($prompt) . ' chars), truncating to 50000');
        $prompt = mb_substr($prompt, 0, 50000, 'UTF-8');
    }
    
    // Формируем тело запроса в формате OpenAI API
    // Используем оптимизированные параметры для скорости
    $body = json_encode([
        'model' => ALIBABA_MODEL,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => ALIBABA_MAX_TOKENS_NORMAL,
        'temperature' => ALIBABA_TEMPERATURE,
        'top_p' => ALIBABA_TOP_P,
    ], JSON_UNESCAPED_UNICODE);
    
    if ($body === false) {
        throw new RuntimeException('Не удалось закодировать промпт в JSON: ' . json_last_error_msg());
    }

    $lastError = null;
    
    // Retry логика с экспоненциальной задержкой
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $ch = curl_init(ALIBABA_ENDPOINT);
            if ($ch === false) {
                throw new RuntimeException('Не удалось инициализировать cURL');
            }
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 60, // Уменьшено для более быстрого отказа при проблемах
                CURLOPT_CONNECTTIMEOUT => 5, // Уменьшено для более быстрого соединения
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            // Обработка сетевых ошибок (retry)
            if ($response === false || $curlErrno !== 0) {
                $lastError = 'Сетевая ошибка: ' . ($curlError ?: 'Неизвестная ошибка cURL (код: ' . $curlErrno . ')');
                error_log("Alibaba API call attempt $attempt failed: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException($lastError);
                }
                
                $delay = pow(2, $attempt - 1);
                error_log("Retrying in $delay seconds...");
                sleep($delay);
                continue;
            }

            // Обработка HTTP ошибок
            if ($status >= 500) {
                $lastError = "HTTP $status: " . substr($response, 0, 200);
                error_log("Alibaba API call attempt $attempt failed with HTTP $status: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException('Сервер API временно недоступен. Попробуйте позже.');
                }
                
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                continue;
            }
            
            if ($status >= 400 && $status < 500) {
                $decoded = json_decode($response, true);
                $errorMsg = 'Ошибка API';
                if (isset($decoded['error']['message'])) {
                    $errorMsg = $decoded['error']['message'];
                } elseif (isset($decoded['error'])) {
                    $errorMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
                }
                throw new RuntimeException("Ошибка API Alibaba Cloud (HTTP $status): $errorMsg");
            }

            // Успешный ответ
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Не удалось декодировать JSON ответ: ' . json_last_error_msg());
            }

            // Извлекаем текст ответа
            if (isset($decoded['choices'][0]['message']['content'])) {
                // Заполняем информацию о модели (параметр передается по ссылке, можно присваивать даже если был null)
                $usedModelInfo = [
                    'provider' => 'alibaba',
                    'model' => ALIBABA_MODEL,
                    'fallback_used' => false
                ];
                return trim($decoded['choices'][0]['message']['content']);
            } elseif (isset($decoded['choices'][0]['text'])) {
                // Заполняем информацию о модели (параметр передается по ссылке, можно присваивать даже если был null)
                $usedModelInfo = [
                    'provider' => 'alibaba',
                    'model' => ALIBABA_MODEL,
                    'fallback_used' => false
                ];
                return trim($decoded['choices'][0]['text']);
            } else {
                error_log('Unexpected response structure: ' . json_encode($decoded));
                throw new RuntimeException('Неожиданная структура ответа API');
            }
            
        } catch (RuntimeException $e) {
            // Если это последняя попытка, пробрасываем исключение
            if ($attempt >= $maxRetries) {
                throw $e;
            }
            // Иначе продолжаем retry
        }
    }
    
    throw new RuntimeException($lastError ?: 'Не удалось получить ответ от API');
}

/**
 * Вызов Together.ai API
 * 
 * @param string $prompt Промпт для отправки в модель
 * @param string $apiKey API ключ Together.ai
 * @param int $maxRetries Максимальное количество попыток при ошибках
 * @param array|null $usedModelInfo Массив для записи информации о использованной модели (заполняется по ссылке)
 * @return string Текст ответа модели
 * @throws RuntimeException При ошибках API или сети
 */
function callTogetherCompletions(string $prompt, string $apiKey, int $maxRetries = 3, ?array &$usedModelInfo = null): string
{
    // Валидация входных данных
    if (empty($prompt)) {
        throw new RuntimeException('Промпт не может быть пустым');
    }
    if (empty($apiKey)) {
        throw new RuntimeException('API ключ не может быть пустым');
    }
    
    // Ограничиваем длину промпта
    if (strlen($prompt) > 50000) {
        error_log('Warning: Prompt is very long (' . strlen($prompt) . ' chars), truncating to 50000');
        $prompt = mb_substr($prompt, 0, 50000, 'UTF-8');
    }
    
    $body = json_encode([
        'model' => TOGETHER_MODEL,
        'prompt' => $prompt,
        'max_tokens' => TOGETHER_MAX_TOKENS_NORMAL,
        'temperature' => TOGETHER_TEMPERATURE,
        'top_p' => TOGETHER_TOP_P,
    ], JSON_UNESCAPED_UNICODE);
    
    if ($body === false) {
        throw new RuntimeException('Не удалось закодировать промпт в JSON: ' . json_last_error_msg());
    }

    $lastError = null;
    
    // Retry логика с экспоненциальной задержкой
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $ch = curl_init('https://api.together.ai/v1/completions');
            if ($ch === false) {
                throw new RuntimeException('Не удалось инициализировать cURL');
            }
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 60, // Уменьшено для более быстрого отказа при проблемах
                CURLOPT_CONNECTTIMEOUT => 5, // Уменьшено для более быстрого соединения
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            // Обработка сетевых ошибок (retry)
            if ($response === false || $curlErrno !== 0) {
                $lastError = 'Сетевая ошибка: ' . ($curlError ?: 'Неизвестная ошибка cURL (код: ' . $curlErrno . ')');
                error_log("Together API call attempt $attempt failed: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException($lastError);
                }
                
                $delay = pow(2, $attempt - 1);
                error_log("Retrying in $delay seconds...");
                sleep($delay);
                continue;
            }

            // Обработка HTTP ошибок
            if ($status >= 500) {
                $lastError = "HTTP $status: " . substr($response, 0, 200);
                error_log("Together API call attempt $attempt failed with HTTP $status: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException('Сервер API временно недоступен. Попробуйте позже.');
                }
                
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                continue;
            }
            
            if ($status >= 400 && $status < 500) {
                $decoded = json_decode($response, true);
                $errorMsg = 'Ошибка API';
                if (isset($decoded['error']['message'])) {
                    $errorMsg = $decoded['error']['message'];
                } elseif (isset($decoded['error'])) {
                    $errorMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
                }
                throw new RuntimeException("Ошибка API Together.ai (HTTP $status): $errorMsg");
            }

            // Успешный ответ
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Не удалось декодировать JSON ответ: ' . json_last_error_msg());
            }

            // Извлекаем текст ответа
            if (isset($decoded['choices'][0]['text'])) {
                // Заполняем информацию о модели (параметр передается по ссылке, можно присваивать даже если был null)
                $usedModelInfo = [
                    'provider' => 'together',
                    'model' => TOGETHER_MODEL,
                    'fallback_used' => false
                ];
                return trim($decoded['choices'][0]['text']);
            } elseif (isset($decoded['output']['choices'][0]['text'])) {
                // Заполняем информацию о модели (параметр передается по ссылке, можно присваивать даже если был null)
                $usedModelInfo = [
                    'provider' => 'together',
                    'model' => TOGETHER_MODEL,
                    'fallback_used' => false
                ];
                return trim($decoded['output']['choices'][0]['text']);
            } else {
                error_log('Unexpected response structure: ' . json_encode($decoded));
                throw new RuntimeException('Неожиданная структура ответа API');
            }
            
        } catch (RuntimeException $e) {
            if ($attempt >= $maxRetries) {
                throw $e;
            }
        }
    }
    
    throw new RuntimeException($lastError ?: 'Не удалось получить ответ от API');
}

/**
 * Универсальная функция для вызова AI (автоматически выбирает провайдера)
 * 
 * Использует текущий выбранный провайдер из сессии или константу DEFAULT_AI_PROVIDER
 * Поддерживает как простой prompt, так и chat completions с messages
 * 
 * @param string|array $prompt Промпт (строка) или массив messages для chat completions
 * @param string|null $apiKey API ключ (если null, используется ключ текущего провайдера)
 * @param int $maxRetries Максимальное количество попыток при ошибках
 * @param array|null $systemMessage Опциональное system message для chat completions
 * @param array|null $usedModelInfo Массив для записи информации о использованной модели (заполняется по ссылке)
 * @return string Текст ответа модели
 * @throws RuntimeException При ошибках API или сети
 */
function callAICompletions($prompt, ?string $apiKey = null, int $maxRetries = 3, ?string $systemMessage = null, ?array &$usedModelInfo = null): string
{
    $provider = getCurrentAIProvider();
    
    // Если передан массив messages, используем chat completions
    if (is_array($prompt)) {
        return callAIChatCompletions($prompt, $apiKey, $maxRetries, $usedModelInfo);
    }
    
    // Если есть system message, формируем messages
    if ($systemMessage !== null) {
        $messages = [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => $prompt]
        ];
        return callAIChatCompletions($messages, $apiKey, $maxRetries, $usedModelInfo);
    }
    
    // Обычный prompt-based запрос
    // Если передан ключ, но он не соответствует текущему провайдеру, игнорируем его
    $originalProvider = $provider;
    try {
        if ($provider === 'alibaba') {
            // Для Alibaba используем только ALIBABA_API_KEY, игнорируем переданный ключ если он от Together
            $apiKey = ALIBABA_API_KEY;
            return callAlibabaCloudCompletions($prompt, $apiKey, $maxRetries, $usedModelInfo);
        }
        // По умолчанию используем Together.ai
        $apiKey = TOGETHER_API_KEY;
        return callTogetherCompletions($prompt, $apiKey, $maxRetries, $usedModelInfo);
    } catch (RuntimeException $e) {
        // Fallback на второго провайдера при 5xx/временной недоступности
        $message = $e->getMessage();
        if (mb_strpos($message, 'Сервер API временно недоступен') !== false) {
            if ($provider === 'alibaba') {
                error_log('Alibaba API temporary failure, fallback to Together.ai');
                $apiKey = TOGETHER_API_KEY;
                $result = callTogetherCompletions($prompt, $apiKey, $maxRetries, $usedModelInfo);
                // Обновляем информацию о модели для fallback
                if ($usedModelInfo !== null) {
                    $usedModelInfo['fallback_used'] = true;
                    $usedModelInfo['original_provider'] = $originalProvider;
                }
                return $result;
            }
            error_log('Together API temporary failure, fallback to Alibaba Cloud');
            $apiKey = ALIBABA_API_KEY;
            $result = callAlibabaCloudCompletions($prompt, $apiKey, $maxRetries, $usedModelInfo);
            // Обновляем информацию о модели для fallback
            if ($usedModelInfo !== null) {
                $usedModelInfo['fallback_used'] = true;
                $usedModelInfo['original_provider'] = $originalProvider;
            }
            return $result;
        }
        throw $e;
    }
}

/**
 * Универсальная функция для chat completions (с messages)
 * 
 * @param array $messages Массив сообщений [['role' => 'user', 'content' => '...'], ...]
 * @param string|null $apiKey API ключ
 * @param int $maxRetries Максимальное количество попыток
 * @param array|null $usedModelInfo Массив для записи информации о использованной модели (заполняется по ссылке)
 * @return string Текст ответа модели
 * @throws RuntimeException При ошибках API или сети
 */
function callAIChatCompletions(array $messages, ?string $apiKey = null, int $maxRetries = 3, ?array &$usedModelInfo = null): string
{
    $provider = getCurrentAIProvider();
    $originalProvider = $provider;
    
    // Игнорируем переданный ключ, всегда используем ключ текущего провайдера
    try {
        if ($provider === 'alibaba') {
            $apiKey = ALIBABA_API_KEY;
            return callAlibabaCloudChatCompletions($messages, $apiKey, $maxRetries, $usedModelInfo);
        }
        $apiKey = TOGETHER_API_KEY;
        return callTogetherChatCompletions($messages, $apiKey, $maxRetries, $usedModelInfo);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        if (mb_strpos($message, 'Сервер API временно недоступен') !== false) {
            if ($provider === 'alibaba') {
                error_log('Alibaba Chat API temporary failure, fallback to Together.ai');
                $apiKey = TOGETHER_API_KEY;
                $result = callTogetherChatCompletions($messages, $apiKey, $maxRetries, $usedModelInfo);
                // Обновляем информацию о модели для fallback
                if ($usedModelInfo !== null) {
                    $usedModelInfo['fallback_used'] = true;
                    $usedModelInfo['original_provider'] = $originalProvider;
                }
                return $result;
            }
            error_log('Together Chat API temporary failure, fallback to Alibaba Cloud');
            $apiKey = ALIBABA_API_KEY;
            $result = callAlibabaCloudChatCompletions($messages, $apiKey, $maxRetries, $usedModelInfo);
            // Обновляем информацию о модели для fallback
            if ($usedModelInfo !== null) {
                $usedModelInfo['fallback_used'] = true;
                $usedModelInfo['original_provider'] = $originalProvider;
            }
            return $result;
        }
        throw $e;
    }
}

/**
 * Вызов Alibaba Cloud Qwen для chat completions
 * 
 * @param array $messages Массив сообщений
 * @param string $apiKey API ключ Alibaba Cloud
 * @param int $maxRetries Максимальное количество попыток при ошибках
 * @param array|null $usedModelInfo Массив для записи информации о использованной модели (заполняется по ссылке)
 * @return string Текст ответа модели
 * @throws RuntimeException При ошибках API или сети
 */
function callAlibabaCloudChatCompletions(array $messages, string $apiKey, int $maxRetries = 3, ?array &$usedModelInfo = null): string
{
    if (empty($messages)) {
        throw new RuntimeException('Messages не могут быть пустыми');
    }
    if (empty($apiKey)) {
        throw new RuntimeException('API ключ не может быть пустым');
    }
    
    // Используем оптимизированные параметры для скорости (Term Sheet требует больше токенов)
    $body = json_encode([
        'model' => ALIBABA_MODEL,
        'messages' => $messages,
        'max_tokens' => ALIBABA_MAX_TOKENS_LONG,
        'temperature' => ALIBABA_TEMPERATURE,
        'top_p' => ALIBABA_TOP_P,
    ], JSON_UNESCAPED_UNICODE);
    
    if ($body === false) {
        throw new RuntimeException('Не удалось закодировать messages в JSON: ' . json_last_error_msg());
    }

    $lastError = null;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $ch = curl_init(ALIBABA_ENDPOINT);
            if ($ch === false) {
                throw new RuntimeException('Не удалось инициализировать cURL');
            }
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 90, // Уменьшено с 120 для Term Sheet
                CURLOPT_CONNECTTIMEOUT => 5, // Уменьшено с 10
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($response === false || $curlErrno !== 0) {
                $lastError = 'Сетевая ошибка: ' . ($curlError ?: 'Неизвестная ошибка cURL (код: ' . $curlErrno . ')');
                error_log("Alibaba Chat API call attempt $attempt failed: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException($lastError);
                }
                
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                continue;
            }

            if ($status >= 500) {
                $lastError = "HTTP $status: " . substr($response, 0, 200);
                error_log("Alibaba Chat API call attempt $attempt failed with HTTP $status: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException('Сервер API временно недоступен. Попробуйте позже.');
                }
                
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                continue;
            }
            
            if ($status >= 400 && $status < 500) {
                $decoded = json_decode($response, true);
                $errorMsg = 'Ошибка API';
                if (isset($decoded['error']['message'])) {
                    $errorMsg = $decoded['error']['message'];
                } elseif (isset($decoded['error'])) {
                    $errorMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
                }
                throw new RuntimeException("Ошибка API Alibaba Cloud (HTTP $status): $errorMsg");
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Не удалось декодировать JSON ответ: ' . json_last_error_msg());
            }

            if (isset($decoded['choices'][0]['message']['content'])) {
                // Заполняем информацию о модели (параметр передается по ссылке, можно присваивать даже если был null)
                $usedModelInfo = [
                    'provider' => 'alibaba',
                    'model' => ALIBABA_MODEL,
                    'fallback_used' => false
                ];
                return trim($decoded['choices'][0]['message']['content']);
            } else {
                error_log('Unexpected response structure: ' . json_encode($decoded));
                throw new RuntimeException('Неожиданная структура ответа API');
            }
            
        } catch (RuntimeException $e) {
            if ($attempt >= $maxRetries) {
                throw $e;
            }
        }
    }
    
    throw new RuntimeException($lastError ?: 'Не удалось получить ответ от API');
}

/**
 * Вызов Together.ai для chat completions
 * 
 * @param array $messages Массив сообщений
 * @param string $apiKey API ключ Together.ai
 * @param int $maxRetries Максимальное количество попыток при ошибках
 * @param array|null $usedModelInfo Массив для записи информации о использованной модели (заполняется по ссылке)
 * @return string Текст ответа модели
 * @throws RuntimeException При ошибках API или сети
 */
function callTogetherChatCompletions(array $messages, string $apiKey, int $maxRetries = 3, ?array &$usedModelInfo = null): string
{
    if (empty($messages)) {
        throw new RuntimeException('Messages не могут быть пустыми');
    }
    if (empty($apiKey)) {
        throw new RuntimeException('API ключ не может быть пустым');
    }
    
    $body = json_encode([
        'model' => TOGETHER_MODEL,
        'messages' => $messages,
        'max_tokens' => TOGETHER_MAX_TOKENS_LONG,
        'temperature' => TOGETHER_TEMPERATURE,
        'top_p' => TOGETHER_TOP_P,
    ], JSON_UNESCAPED_UNICODE);
    
    if ($body === false) {
        throw new RuntimeException('Не удалось закодировать messages в JSON: ' . json_last_error_msg());
    }

    $lastError = null;
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $ch = curl_init('https://api.together.ai/v1/chat/completions');
            if ($ch === false) {
                throw new RuntimeException('Не удалось инициализировать cURL');
            }
            
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_TIMEOUT => 90, // Уменьшено с 120 для Term Sheet
                CURLOPT_CONNECTTIMEOUT => 5, // Уменьшено с 10
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($response === false || $curlErrno !== 0) {
                $lastError = 'Сетевая ошибка: ' . ($curlError ?: 'Неизвестная ошибка cURL (код: ' . $curlErrno . ')');
                error_log("Together Chat API call attempt $attempt failed: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException($lastError);
                }
                
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                continue;
            }

            if ($status >= 500) {
                $lastError = "HTTP $status: " . substr($response, 0, 200);
                error_log("Together Chat API call attempt $attempt failed with HTTP $status: $lastError");
                
                if ($attempt >= $maxRetries) {
                    throw new RuntimeException('Сервер API временно недоступен. Попробуйте позже.');
                }
                
                $delay = pow(2, $attempt - 1);
                sleep($delay);
                continue;
            }
            
            if ($status >= 400 && $status < 500) {
                $decoded = json_decode($response, true);
                $errorMsg = 'Ошибка API';
                if (isset($decoded['error']['message'])) {
                    $errorMsg = $decoded['error']['message'];
                } elseif (isset($decoded['error'])) {
                    $errorMsg = is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']);
                }
                throw new RuntimeException("Ошибка API Together.ai (HTTP $status): $errorMsg");
            }

            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Не удалось декодировать JSON ответ: ' . json_last_error_msg());
            }

            if (isset($decoded['choices'][0]['message']['content'])) {
                // Заполняем информацию о модели (параметр передается по ссылке, можно присваивать даже если был null)
                $usedModelInfo = [
                    'provider' => 'together',
                    'model' => TOGETHER_MODEL,
                    'fallback_used' => false
                ];
                return trim($decoded['choices'][0]['message']['content']);
            } else {
                error_log('Unexpected response structure: ' . json_encode($decoded));
                throw new RuntimeException('Неожиданная структура ответа API');
            }
            
        } catch (RuntimeException $e) {
            if ($attempt >= $maxRetries) {
                throw $e;
            }
        }
    }
    
    throw new RuntimeException($lastError ?: 'Не удалось получить ответ от API');
}

/**
 * Получает ID пользователя, от имени которого работает модератор (если есть)
 * 
 * @return int|null ID пользователя или null, если impersonation не активен
 */
function getImpersonatedUserId(): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    
    if (isset($_SESSION['impersonate_user_id'])) {
        return (int)$_SESSION['impersonate_user_id'];
    }
    
    return null;
}

/**
 * Получает эффективный ID пользователя для операций
 * Если модератор работает в режиме impersonation, возвращает ID клиента
 * Иначе возвращает ID текущего пользователя
 * 
 * @return int|null ID пользователя или null, если пользователь не авторизован
 */
function getEffectiveUserId(): ?int
{
    $impersonatedId = getImpersonatedUserId();
    if ($impersonatedId !== null) {
        return $impersonatedId;
    }
    
    if (isLoggedIn()) {
        return (int)$_SESSION['user_id'];
    }
    
    return null;
}

/**
 * Проверяет, работает ли модератор от имени другого пользователя
 * 
 * @return bool true если активен режим impersonation, false иначе
 */
function isImpersonating(): bool
{
    return getImpersonatedUserId() !== null;
}

/**
 * Устанавливает режим impersonation для модератора
 * 
 * @param int $userId ID пользователя, от имени которого будет работать модератор
 * @return bool true при успехе, false при ошибке
 */
function setImpersonation(int $userId): bool
{
    // Проверяем, что текущий пользователь - модератор
    if (!isModerator()) {
        error_log("Attempt to set impersonation by non-moderator user");
        return false;
    }
    
    // Проверяем существование пользователя
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            error_log("Attempt to impersonate non-existent or inactive user: $userId");
            return false;
        }
    } catch (PDOException $e) {
        error_log("Error checking user for impersonation: " . $e->getMessage());
        return false;
    }
    
    // Устанавливаем impersonation в сессию
    if (session_status() !== PHP_SESSION_ACTIVE) {
        initSession();
    }
    
    $_SESSION['impersonate_user_id'] = (int)$userId;
    return true;
}

/**
 * Отключает режим impersonation
 * 
 * @return bool true при успехе
 */
function clearImpersonation(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }
    
    if (isset($_SESSION['impersonate_user_id'])) {
        unset($_SESSION['impersonate_user_id']);
    }
    
    return true;
}

// Инициализация сессии при подключении файла
initSession();

