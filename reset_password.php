<?php
/**
 * Страница сброса пароля по токену
 * 
 * Функциональность:
 * - Проверка валидности токена из URL
 * - Форма для ввода нового пароля
 * - Обновление пароля в БД
 * - Помечение токена как использованного
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

/**
 * Если пользователь уже авторизован, редирект в личный кабинет
 */
if (isLoggedIn()) {
    redirectToDashboard();
}

$errors = [];
$success = false;
$token = $_GET['token'] ?? '';
$tokenValid = false;
$tokenData = null;

/**
 * Проверка валидности токена
 */
if (!empty($token)) {
    $tokenData = validatePasswordResetToken($token);
    if ($tokenData) {
        $tokenValid = true;
    } else {
        $errors['general'] = 'Токен восстановления недействителен или истек. Пожалуйста, запросите новую ссылку для восстановления пароля.';
    }
} else {
    $errors['general'] = 'Токен восстановления не указан.';
}

/**
 * Обработка формы сброса пароля
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $newPassword = $_POST['password'] ?? '';
    $confirmPassword = $_POST['password_confirm'] ?? '';
    
    // Валидация пароля
    if (empty($newPassword)) {
        $errors['password'] = 'Введите новый пароль';
    } elseif (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        $errors['password'] = 'Пароль должен содержать минимум ' . PASSWORD_MIN_LENGTH . ' символов';
    }
    
    if (empty($confirmPassword)) {
        $errors['password_confirm'] = 'Подтвердите пароль';
    } elseif ($newPassword !== $confirmPassword) {
        $errors['password_confirm'] = 'Пароли не совпадают';
    }
    
    // Если нет ошибок, обновляем пароль
    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            
            // Хешируем новый пароль
            $passwordHash = hashPassword($newPassword);
            
            // Обновляем пароль пользователя
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$passwordHash, $tokenData['user_id']]);
            
            // Помечаем токен как использованный
            markPasswordResetTokenAsUsed($token);
            
            $success = true;
            
            // Редирект на страницу входа через 3 секунды
            header('Refresh: 3; url=' . LOGIN_URL . '?password_reset=success');
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $errors['general'] = 'Ошибка обновления пароля. Попробуйте позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля - SmartBizSell.ru</title>
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .auth-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .auth-title {
            font-size: 28px;
            font-weight: 700;
            color: #333;
            margin: 0 0 10px 0;
        }
        
        .auth-subtitle {
            font-size: 16px;
            color: #666;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-primary {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        
        .success-message {
            background: #efe;
            color: #3c3;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #cfc;
        }
        
        .auth-footer {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        
        .auth-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .auth-footer a:hover {
            text-decoration: underline;
        }
        
        .password-requirements {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1 class="auth-title">Сброс пароля</h1>
                <p class="auth-subtitle">Введите новый пароль</p>
            </div>
            
            <?php if (!empty($errors['general'])): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <strong>Пароль успешно изменен!</strong><br>
                    Вы будете перенаправлены на страницу входа через несколько секунд.
                </div>
                <div class="auth-footer">
                    <a href="login.php">Перейти к входу сейчас</a>
                </div>
            <?php elseif ($tokenValid): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="password">Новый пароль</label>
                        <input type="password" id="password" name="password" required 
                               minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                               placeholder="Минимум <?php echo PASSWORD_MIN_LENGTH; ?> символов">
                        <?php if (!empty($errors['password'])): ?>
                            <span style="color: #c33; font-size: 14px; margin-top: 5px; display: block;">
                                <?php echo htmlspecialchars($errors['password'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php else: ?>
                            <div class="password-requirements">
                                Минимум <?php echo PASSWORD_MIN_LENGTH; ?> символов
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="password_confirm">Подтвердите пароль</label>
                        <input type="password" id="password_confirm" name="password_confirm" required 
                               minlength="<?php echo PASSWORD_MIN_LENGTH; ?>"
                               placeholder="Повторите пароль">
                        <?php if (!empty($errors['password_confirm'])): ?>
                            <span style="color: #c33; font-size: 14px; margin-top: 5px; display: block;">
                                <?php echo htmlspecialchars($errors['password_confirm'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" class="btn-primary">Изменить пароль</button>
                </form>
                
                <div class="auth-footer">
                    <a href="login.php">← Вернуться к входу</a>
                </div>
            <?php else: ?>
                <div class="auth-footer">
                    <a href="forgot_password.php">Запросить новую ссылку для восстановления</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

