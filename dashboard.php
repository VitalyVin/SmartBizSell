<?php
require_once 'config.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirectToLogin();
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

// Получение анкет пользователя
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, asset_name, status, created_at, updated_at, submitted_at 
        FROM seller_forms 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $forms = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching forms: " . $e->getMessage());
    $forms = [];
}

// Статусы анкет
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - SmartBizSell.ru</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            padding: 40px 0;
            color: white;
            margin-bottom: 40px;
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
        }
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
        @media (max-width: 768px) {
            .table-row {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .dashboard-stats {
                grid-template-columns: 1fr;
            }
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

    <div class="dashboard-header">
        <div class="dashboard-header-content">
            <h1>Личный кабинет</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>!</p>
        </div>
    </div>

    <div class="dashboard-container">
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                <strong>✓ Анкета успешно отправлена!</strong> Команда SmartBizSell изучит информацию и свяжется с вами.
            </div>
        <?php endif; ?>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($forms); ?></div>
                <div class="stat-label">Всего анкет</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'submitted' || $f['status'] === 'review')); ?></div>
                <div class="stat-label">На проверке</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'approved')); ?></div>
                <div class="stat-label">Одобрено</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'draft')); ?></div>
                <div class="stat-label">Черновиков</div>
            </div>
        </div>

        <div class="dashboard-actions">
            <a href="index.php#seller-form" class="btn btn-primary">+ Создать новую анкету</a>
            <a href="profile.php" class="btn btn-secondary">Настройки профиля</a>
        </div>

        <div class="forms-table">
            <div class="table-header">Мои анкеты</div>
            
            <?php if (empty($forms)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>У вас пока нет анкет</h3>
                    <p>Создайте первую анкету для продажи вашего бизнеса</p>
                    <a href="index.php#seller-form" class="btn btn-primary" style="margin-top: 20px;">Создать анкету</a>
                </div>
            <?php else: ?>
                <div style="padding: 0;">
                    <?php foreach ($forms as $form): ?>
                        <div class="table-row">
                            <div>
                                <strong><?php echo htmlspecialchars($form['asset_name'] ?: 'Без названия', ENT_QUOTES, 'UTF-8'); ?></strong>
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
                            <div>
                                <a href="form-view.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">Просмотр</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

