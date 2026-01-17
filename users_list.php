<?php
/**
 * Список пользователей для модераторов
 * 
 * Функциональность:
 * - Просмотр списка всех пользователей
 * - Поиск по email/имени
 * - Вход в личный кабинет любого пользователя
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверка авторизации и прав модератора
if (!isLoggedIn()) {
    redirectToLogin();
}

if (!isModerator()) {
    http_response_code(403);
    die('Доступ запрещен. Только модераторы могут просматривать эту страницу.');
}

$user = getCurrentUser();
$pdo = getDBConnection();

// Обработка входа в кабинет пользователя
if (isset($_GET['impersonate']) && is_numeric($_GET['impersonate'])) {
    $targetUserId = (int)$_GET['impersonate'];
    
    if (setImpersonation($targetUserId)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Не удалось войти в кабинет пользователя.';
    }
}

// Получаем параметры поиска и пагинации
$searchQuery = $_GET['search'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Формируем запрос для получения пользователей
$whereConditions = [];
$params = [];

if (!empty($searchQuery)) {
    $whereConditions[] = "(u.email LIKE ? OR u.full_name LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Получаем общее количество пользователей
try {
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM users u
        $whereClause
    ");
    $countStmt->execute($params);
    $totalUsers = (int)$countStmt->fetch()['total'];
    $totalPages = max(1, ceil($totalUsers / $perPage));
} catch (PDOException $e) {
    error_log("Error counting users: " . $e->getMessage());
    $totalUsers = 0;
    $totalPages = 1;
}

// Получаем список пользователей с количеством анкет
$users = [];
try {
    $sql = "
        SELECT 
            u.id,
            u.email,
            u.full_name,
            u.phone,
            u.company_name,
            u.created_at,
            COUNT(sf.id) as forms_count
        FROM users u
        LEFT JOIN seller_forms sf ON u.id = sf.user_id
        $whereClause
        GROUP BY u.id, u.email, u.full_name, u.phone, u.company_name, u.created_at
        ORDER BY u.created_at DESC
        LIMIT $perPage OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Список пользователей - SmartBizSell</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        body {
            background: #f5f5f7;
            padding-top: 96px;
        }
        .users-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .users-header {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
        }
        .users-header h1 {
            font-size: 32px;
            font-weight: 800;
            margin: 0 0 16px 0;
        }
        .search-box {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }
        .search-box input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            font-size: 14px;
        }
        .search-box button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        .users-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .users-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .users-table th {
            background: rgba(102, 126, 234, 0.08);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
            border-bottom: 2px solid rgba(0, 0, 0, 0.1);
        }
        .users-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-size: 14px;
        }
        .users-table tr:hover {
            background: rgba(102, 126, 234, 0.03);
        }
        .btn-impersonate {
            padding: 8px 16px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn-impersonate:hover {
            opacity: 0.9;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        .pagination a {
            background: white;
            color: var(--primary-color);
            border: 2px solid rgba(102, 126, 234, 0.3);
        }
        .pagination a:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        .pagination span {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
        }
        .error-message {
            background: #FF3B30;
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .stats {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 8px;
        }
    </style>
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
                    <li><a href="moderation.php">Модерация</a></li>
                    <li><a href="users_list.php">Пользователи</a></li>
                    <li><a href="dashboard.php">Личный кабинет</a></li>
                    <li><a href="logout.php">Выйти</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="users-container">
        <div class="users-header">
            <h1>Список пользователей</h1>
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            
            <form method="GET" action="users_list.php" class="search-box">
                <input 
                    type="text" 
                    name="search" 
                    placeholder="Поиск по email или имени..." 
                    value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                >
                <button type="submit">Найти</button>
                <?php if (!empty($searchQuery)): ?>
                    <a href="users_list.php" class="btn-impersonate" style="background: #86868B;">Сбросить</a>
                <?php endif; ?>
            </form>
            
            <div class="stats">
                Всего пользователей: <?php echo number_format($totalUsers, 0, ',', ' '); ?>
            </div>
        </div>

        <div class="users-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Имя</th>
                        <th>Компания</th>
                        <th>Дата регистрации</th>
                        <th>Анкет</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                                Пользователи не найдены
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $userRow): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($userRow['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($userRow['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($userRow['full_name'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($userRow['company_name'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($userRow['created_at'])); ?></td>
                                <td><?php echo (int)$userRow['forms_count']; ?></td>
                                <td>
                                    <a href="users_list.php?impersonate=<?php echo $userRow['id']; ?>" 
                                       class="btn-impersonate"
                                       onclick="return confirm('Войти в личный кабинет пользователя <?php echo htmlspecialchars($userRow['email'], ENT_QUOTES, 'UTF-8'); ?>?');">
                                        Войти в кабинет
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">← Назад</a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <a href="?page=1<?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">1</a>
                    <?php if ($startPage > 2): ?>
                        <span>...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span>...</span>
                    <?php endif; ?>
                    <a href="?page=<?php echo $totalPages; ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo !empty($searchQuery) ? '&search=' . urlencode($searchQuery) : ''; ?>">Вперед →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>
