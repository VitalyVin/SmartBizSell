<?php
/**
 * Страница запроса восстановления пароля
 * 
 * Функциональность:
 * - Форма для ввода email
 * - Генерация токена восстановления
 * - Отправка email со ссылкой для сброса пароля
 * - Защита от частых запросов (rate limiting)
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
$message = '';

/**
 * Обработка формы запроса восстановления пароля
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    
    // Валидация email
    if (empty($email) || !validateEmail($email)) {
        $errors['email'] = 'Введите корректный email адрес';
    } else {
        // Rate limiting: проверяем количество запросов за последний час
        initSession();
        $rateLimitKey = 'password_reset_requests_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
        $requests = $_SESSION[$rateLimitKey] ?? [];
        
        // Удаляем запросы старше часа
        $requests = array_filter($requests, function($timestamp) {
            return (time() - $timestamp) < 3600;
        });
        
        // Проверяем лимит (максимум 3 запроса в час)
        if (count($requests) >= 3) {
            $errors['general'] = 'Слишком много запросов. Попробуйте позже.';
        } else {
            try {
                $pdo = getDBConnection();
                
                // Проверяем существование пользователя
                // Не раскрываем, существует ли email (безопасность)
                $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Генерируем токен восстановления
                    $token = generatePasswordResetToken($user['id']);
                    
                    if ($token) {
                        // Формируем ссылку для сброса пароля
                        $resetLink = BASE_URL . '/reset_password.php?token=' . urlencode($token);
                        
                        // Формируем email с улучшенной структурой для избежания спама
                        $subject = 'Восстановление пароля - SmartBizSell.ru';
                        
                        // Улучшенный HTML шаблон с правильной структурой
                        $body = '
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Восстановление пароля</title>
</head>
<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4;">
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f4f4;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: bold;">SmartBizSell.ru</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px 30px;">
                            <h2 style="color: #333333; margin-top: 0; font-size: 20px; font-weight: 600;">Восстановление пароля</h2>
                            <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 15px 0;">Здравствуйте, ' . htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') . '!</p>
                            <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 15px 0;">Вы запросили восстановление пароля для вашего аккаунта на SmartBizSell.ru.</p>
                            <p style="color: #333333; font-size: 16px; line-height: 1.6; margin: 0 0 30px 0;">Для сброса пароля нажмите на кнопку ниже:</p>
                            
                            <!-- Button -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                <tr>
                                    <td align="center" style="padding: 0 0 30px 0;">
                                        <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" 
                                           style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                                                  color: #ffffff; padding: 15px 40px; text-decoration: none; 
                                                  border-radius: 5px; font-weight: bold; font-size: 16px;">
                                            Сбросить пароль
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Alternative link -->
                            <p style="color: #666666; font-size: 14px; line-height: 1.6; margin: 0 0 15px 0;">
                                Если кнопка не работает, скопируйте и вставьте эту ссылку в браузер:
                            </p>
                            <p style="color: #667eea; font-size: 12px; line-height: 1.6; margin: 0 0 30px 0; word-break: break-all;">
                                <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="color: #667eea; text-decoration: underline;">' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</a>
                            </p>
                            
                            <!-- Warning -->
                            <p style="color: #999999; font-size: 13px; line-height: 1.6; margin: 0 0 10px 0;">
                                Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.
                            </p>
                            <p style="color: #999999; font-size: 12px; line-height: 1.6; margin: 0;">
                                Ссылка действительна в течение 1 часа.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9f9f9; padding: 20px 30px; text-align: center; border-top: 1px solid #eeeeee;">
                            <p style="color: #999999; font-size: 12px; margin: 0; line-height: 1.6;">
                                Это автоматическое письмо, пожалуйста, не отвечайте на него.<br>
                                © ' . date('Y') . ' SmartBizSell.ru. Все права защищены.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
                        
                        $altBody = "Восстановление пароля - SmartBizSell.ru\n\n" .
                                   "Здравствуйте, " . $user['full_name'] . "!\n\n" .
                                   "Вы запросили восстановление пароля для вашего аккаунта.\n\n" .
                                   "Для сброса пароля перейдите по ссылке:\n" . $resetLink . "\n\n" .
                                   "Ссылка действительна в течение 1 часа.\n\n" .
                                   "Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.";
                        
                        // Отправляем email
                        if (sendEmail($email, $subject, $body, $altBody)) {
                            $success = true;
                            $message = 'Инструкции по восстановлению пароля отправлены на ваш email.';
                            
                            // Сохраняем запрос для rate limiting
                            $requests[] = time();
                            $_SESSION[$rateLimitKey] = $requests;
                        } else {
                            $errors['general'] = 'Ошибка отправки email. Попробуйте позже или обратитесь в поддержку.';
                        }
                    } else {
                        $errors['general'] = 'Ошибка создания токена восстановления. Попробуйте позже.';
                    }
                } else {
                    // Не раскрываем, существует ли email (безопасность)
                    // Но все равно показываем успешное сообщение
                    $success = true;
                    $message = 'Если указанный email зарегистрирован в системе, инструкции по восстановлению пароля будут отправлены.';
                    
                    // Сохраняем запрос для rate limiting (даже если email не найден)
                    $requests[] = time();
                    $_SESSION[$rateLimitKey] = $requests;
                }
            } catch (PDOException $e) {
                error_log("Password reset request error: " . $e->getMessage());
                $errors['general'] = 'Ошибка обработки запроса. Попробуйте позже.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля - SmartBizSell.ru</title>
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
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <h1 class="auth-title">Восстановление пароля</h1>
                <p class="auth-subtitle">Введите email для получения инструкций</p>
            </div>
            
            <?php if (!empty($errors['general'])): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success && !empty($message)): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$success): ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="your@email.com">
                    <?php if (!empty($errors['email'])): ?>
                        <span style="color: #c33; font-size: 14px; margin-top: 5px; display: block;">
                            <?php echo htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn-primary">Отправить инструкции</button>
            </form>
            <?php endif; ?>
            
            <div class="auth-footer">
                <a href="login.php">← Вернуться к входу</a>
            </div>
        </div>
    </div>
</body>
</html>

