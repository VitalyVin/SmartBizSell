<?php
/**
 * Страница настроек профиля пользователя
 * 
 * Функциональность:
 * - Редактирование личных данных (ФИО, телефон, название компании)
 * - Изменение пароля с проверкой текущего пароля
 * - Просмотр email и даты регистрации (не редактируются)
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

/**
 * Проверка авторизации пользователя
 */
if (!isLoggedIn()) {
    redirectToLogin();
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

// Инициализация переменных для обработки формы
$errors = [];
$success = false;

/**
 * Обработка формы обновления профиля
 * Валидация данных и обновление в базе данных
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitizeInput($_POST['full_name'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $company_name = sanitizeInput($_POST['company_name'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Валидация
    if (empty($full_name)) {
        $errors['full_name'] = 'Введите ваше имя';
    }
    
    /**
     * Обработка изменения пароля
     * Пароль меняется только если указан новый пароль
     * Требуется подтверждение текущего пароля для безопасности
     */
    $update_password = false;
    if (!empty($new_password)) {
        if (strlen($new_password) < PASSWORD_MIN_LENGTH) {
            $errors['new_password'] = 'Пароль должен содержать минимум ' . PASSWORD_MIN_LENGTH . ' символов';
        }
        
        if ($new_password !== $confirm_password) {
            $errors['confirm_password'] = 'Пароли не совпадают';
        }
        
        // Проверка текущего пароля
        if (empty($current_password)) {
            $errors['current_password'] = 'Введите текущий пароль для изменения';
        } else {
            try {
                $pdo = getDBConnection();
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user_data = $stmt->fetch();
                
                if (!$user_data || !verifyPassword($current_password, $user_data['password_hash'])) {
                    $errors['current_password'] = 'Неверный текущий пароль';
                } else {
                    $update_password = true;
                }
            } catch (PDOException $e) {
                error_log("Error verifying password: " . $e->getMessage());
                $errors['general'] = 'Ошибка проверки пароля. Попробуйте позже.';
            }
        }
    }
    
    // Обновление данных профиля
    if (empty($errors)) {
        try {
            $pdo = getDBConnection();
            
            if ($update_password) {
                $password_hash = hashPassword($new_password);
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, phone = ?, company_name = ?, password_hash = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$full_name, $phone, $company_name, $password_hash, $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, phone = ?, company_name = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$full_name, $phone, $company_name, $_SESSION['user_id']]);
            }
            
            // Обновление данных в сессии
            $_SESSION['user_name'] = $full_name;
            
            // Получение обновленных данных
            $user = getCurrentUser();
            
            $success = true;
        } catch (PDOException $e) {
            error_log("Error updating profile: " . $e->getMessage());
            $errors['general'] = 'Ошибка обновления профиля. Попробуйте позже.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки профиля - SmartBizSell.ru</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            padding: 40px 0;
            color: white;
            margin-bottom: 40px;
            margin-top: 80px;
        }
        .profile-header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .profile-header h1 {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .profile-header p {
            opacity: 0.9;
            font-size: 16px;
        }
        .profile-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }
        .profile-card {
            background: white;
            border-radius: 16px;
            padding: 32px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        .profile-card-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--text-primary);
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border-color);
        }
        .form-group {
            margin-bottom: 24px;
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
            font-family: inherit;
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0, 122, 255, 0.1);
        }
        .form-group input:disabled {
            background: #f5f5f7;
            color: #86868B;
            cursor: not-allowed;
        }
        .error-message {
            color: var(--accent-color);
            font-size: 13px;
            margin-top: 6px;
            display: block;
        }
        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-family: inherit;
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
            margin-right: 12px;
        }
        .btn-secondary:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .password-section {
            margin-top: 32px;
            padding-top: 32px;
            border-top: 2px solid var(--border-color);
        }
        .password-section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--text-primary);
        }
        .password-hint {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
        }
        .info-text {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon">🚀</span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="index.php">Главная</a></li>
                    <li><a href="index.php#buy-business">Купить бизнес</a></li>
                    <li><a href="index.php#seller-form">Продать бизнес</a></li>
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

    <div class="profile-header">
        <div class="profile-header-content">
            <h1>Настройки профиля</h1>
            <p>Управление личными данными и настройками аккаунта</p>
        </div>
    </div>

    <div class="profile-container">
        <?php if ($success): ?>
            <div class="success-message">
                <strong>✓ Профиль успешно обновлен!</strong>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors['general'])): ?>
            <div class="error-message" style="background: #fee; padding: 12px; border-radius: 8px; margin-bottom: 20px;">
                <?php echo $errors['general']; ?>
            </div>
        <?php endif; ?>

        <div class="profile-card">
            <h2 class="profile-card-title">Личная информация</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    <span class="info-text">Email нельзя изменить</span>
                </div>
                
                <div class="form-group">
                    <label for="full_name">ФИО *</label>
                    <input type="text" id="full_name" name="full_name" required 
                           value="<?php echo htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['full_name'])): ?>
                        <span class="error-message"><?php echo $errors['full_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="phone">Телефон</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['phone'])): ?>
                        <span class="error-message"><?php echo $errors['phone']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="company_name">Название компании</label>
                    <input type="text" id="company_name" name="company_name" 
                           value="<?php echo htmlspecialchars($user['company_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($errors['company_name'])): ?>
                        <span class="error-message"><?php echo $errors['company_name']; ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Дата регистрации</label>
                    <input type="text" value="<?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?>" disabled>
                </div>
                
                <div class="password-section">
                    <h3 class="password-section-title">Изменение пароля</h3>
                    <p class="password-hint">Оставьте поля пустыми, если не хотите менять пароль</p>
                    
                    <div class="form-group">
                        <label for="current_password">Текущий пароль</label>
                        <input type="password" id="current_password" name="current_password">
                        <?php if (isset($errors['current_password'])): ?>
                            <span class="error-message"><?php echo $errors['current_password']; ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password">Новый пароль</label>
                        <input type="password" id="new_password" name="new_password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                        <?php if (isset($errors['new_password'])): ?>
                            <span class="error-message"><?php echo $errors['new_password']; ?></span>
                        <?php endif; ?>
                        <span class="password-hint">Минимум <?php echo PASSWORD_MIN_LENGTH; ?> символов</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Подтвердите новый пароль</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="<?php echo PASSWORD_MIN_LENGTH; ?>">
                        <?php if (isset($errors['confirm_password'])): ?>
                            <span class="error-message"><?php echo $errors['confirm_password']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div style="margin-top: 32px; display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                    <a href="dashboard.php" class="btn btn-secondary">Отмена</a>
                </div>
            </form>
        </div>
    </div>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

