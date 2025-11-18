<?php
/**
 * Страница регистрации нового пользователя
 * 
 * Функциональность:
 * - Регистрация нового аккаунта продавца
 * - Валидация email (уникальность, формат)
 * - Валидация пароля (минимальная длина)
 * - Автоматический вход после успешной регистрации
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Инициализация переменных для обработки формы
$errors = [];
$success = false;

/**
 * Обработка формы регистрации
 * Валидация данных, проверка уникальности email, создание пользователя
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $full_name = sanitizeInput($_POST['full_name'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $company_name = sanitizeInput($_POST['company_name'] ?? '');
    
    // Валидация
    if (empty($email) || !validateEmail($email)) {
        $errors['email'] = 'Введите корректный email адрес';
    }
    
    if (empty($password) || strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors['password'] = 'Пароль должен содержать минимум ' . PASSWORD_MIN_LENGTH . ' символов';
    }
    
    if ($password !== $password_confirm) {
        $errors['password_confirm'] = 'Пароли не совпадают';
    }
    
    if (empty($full_name)) {
        $errors['full_name'] = 'Введите ваше имя';
    }
    
    // Проверка существования email
    if (empty($errors['email'])) {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Пользователь с таким email уже зарегистрирован';
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors['general'] = 'Ошибка регистрации. Попробуйте позже.';
        }
    }
    
    /**
     * Создание нового пользователя в базе данных
     * Пароль хешируется перед сохранением
     * После успешной регистрации пользователь автоматически входит в систему
     */
    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("
                INSERT INTO users (email, password_hash, full_name, phone, company_name) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            // Хеширование пароля перед сохранением
            $password_hash = hashPassword($password);
            $stmt->execute([$email, $password_hash, $full_name, $phone, $company_name]);
            
            /**
             * Автоматический вход после регистрации
             * Создание сессии с данными нового пользователя
             */
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $full_name;
            
            // Обновление времени последнего входа
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            
            $success = true;
            // Редирект в личный кабинет
            header('Location: dashboard.php');
            exit;
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors['general'] = 'Ошибка регистрации. Попробуйте позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - SmartBizSell.ru</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
        }
        .auth-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 48px;
            max-width: 480px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .auth-header {
            text-align: center;
            margin-bottom: 32px;
        }
        .auth-logo {
            font-size: 32px;
            margin-bottom: 16px;
        }
        .auth-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .auth-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #fff;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0, 122, 255, 0.1);
        }
        .error-message {
            color: var(--accent-color);
            font-size: 13px;
            margin-top: 6px;
            display: block;
        }
        .btn-primary {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        .auth-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .auth-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">🚀</div>
                <h1 class="auth-title">Регистрация</h1>
                <p class="auth-subtitle">Создайте аккаунт для продажи бизнеса</p>
            </div>
            
            <?php if (!empty($errors['general'])): ?>
                <div class="error-message" style="background: #fee; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo $errors['general']; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['email'])): ?>
                        <span class="error-message"><?php echo $errors['email']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="full_name">ФИО *</label>
                    <input type="text" id="full_name" name="full_name" required 
                           value="<?php echo htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['full_name'])): ?>
                        <span class="error-message"><?php echo $errors['full_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="phone">Телефон</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="company_name">Название компании</label>
                    <input type="text" id="company_name" name="company_name" 
                           value="<?php echo htmlspecialchars($_POST['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль *</label>
                    <input type="password" id="password" name="password" required minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                    <?php if (isset($errors['password'])): ?>
                        <span class="error-message"><?php echo $errors['password']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="password_confirm">Подтвердите пароль *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                    <?php if (isset($errors['password_confirm'])): ?>
                        <span class="error-message"><?php echo $errors['password_confirm']; ?></span>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn-primary">Зарегистрироваться</button>
            </form>
            
            <div class="auth-footer">
                Уже есть аккаунт? <a href="login.php">Войти</a>
            </div>
        </div>
    </div>
</body>
</html>

