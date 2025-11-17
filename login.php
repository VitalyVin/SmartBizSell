<?php
require_once 'config.php';

// Если уже авторизован, редирект в кабинет
if (isLoggedIn()) {
    redirectToDashboard();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $errors['general'] = 'Введите email и пароль';
    } else {
        try {
            $pdo = getDBConnection();
            $stmt = $pdo->prepare("SELECT id, email, password_hash, full_name, is_active FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && verifyPassword($password, $user['password_hash'])) {
                if (!$user['is_active']) {
                    $errors['general'] = 'Ваш аккаунт заблокирован. Обратитесь к администратору.';
                } else {
                    // Успешный вход
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['full_name'];
                    
                    // Обновление времени последнего входа
                    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    // Установка времени жизни сессии
                    if ($remember) {
                        ini_set('session.gc_maxlifetime', SESSION_LIFETIME * 2);
                    }
                    
                    // Редирект на страницу, с которой пришли, или в кабинет
                    $redirect = $_GET['redirect'] ?? 'dashboard.php';
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                $errors['general'] = 'Неверный email или пароль';
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors['general'] = 'Ошибка входа. Попробуйте позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - SmartBizSell.ru</title>
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
        .form-group-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }
        .form-group-checkbox input[type="checkbox"] {
            width: auto;
            cursor: pointer;
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
                <h1 class="auth-title">Вход в систему</h1>
                <p class="auth-subtitle">Войдите в личный кабинет</p>
            </div>
            
            <?php if (!empty($errors['general'])): ?>
                <div class="error-message" style="background: #fee; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo $errors['general']; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group-checkbox">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember" style="margin: 0;">Запомнить меня</label>
                </div>
                
                <button type="submit" class="btn-primary">Войти</button>
            </form>
            
            <div class="auth-footer">
                Нет аккаунта? <a href="register.php">Зарегистрироваться</a>
            </div>
        </div>
    </div>
</body>
</html>

