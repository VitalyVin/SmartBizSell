<?php
/**
 * blog.php
 * 
 * Страница со списком статей блога
 * Отображает все опубликованные статьи с пагинацией
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверяем существование таблицы блога
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SHOW TABLES LIKE 'blog_posts'");
    $blogTableExists = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $blogTableExists = false;
}

// Получаем статьи из базы данных
$posts = [];
$totalPosts = 0;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$postsPerPage = 10;
$offset = ($currentPage - 1) * $postsPerPage;

if ($blogTableExists) {
    try {
        // Общее количество опубликованных статей
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM blog_posts WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()");
        $totalPosts = $stmt->fetch()['total'] ?? 0;
        
        // Получаем статьи для текущей страницы
        $stmt = $pdo->prepare("
            SELECT 
                id, title, slug, excerpt, 
                category, tags, 
                published_at, views,
                meta_title, meta_description
            FROM blog_posts 
            WHERE status = 'published' 
                AND published_at IS NOT NULL 
                AND published_at <= NOW()
            ORDER BY published_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $postsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error loading blog posts: " . $e->getMessage());
    }
}

$totalPages = $blogTableExists && $totalPosts > 0 ? ceil($totalPosts / $postsPerPage) : 1;

// Мета-теги для SEO
$pageTitle = "Блог SmartBizSell - Статьи о продаже и покупке бизнеса, M&A, инвестициях";
$pageDescription = "Полезные статьи о продаже и покупке бизнеса, M&A сделках, оценке бизнеса, финансовом моделировании, поиске инвесторов и других аспектах сделок слияний и поглощений.";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="блог о продаже бизнеса, статьи M&A, как продать бизнес, как купить бизнес, оценка бизнеса, инвестиции">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/blog">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/blog">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/yandex_metrika.php'; ?>
    
    <style>
        .blog-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 100px 20px 60px;
        }
        .blog-header {
            text-align: center;
            margin-bottom: 60px;
        }
        .blog-header h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .blog-header p {
            font-size: 18px;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }
        .blog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }
        .blog-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .blog-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        .blog-card-category {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(102, 126, 234, 0.1);
            color: #667EEA;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 16px;
        }
        .blog-card-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            line-height: 1.3;
        }
        .blog-card-title a {
            color: var(--text-primary);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .blog-card-title a:hover {
            color: #667EEA;
        }
        .blog-card-excerpt {
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 16px;
        }
        .blog-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: var(--text-secondary);
            padding-top: 16px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }
        .blog-card-date {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .blog-card-views {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .blog-pagination {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 60px;
        }
        .pagination-link {
            padding: 10px 16px;
            background: white;
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .pagination-link:hover {
            background: #667EEA;
            color: white;
            border-color: #667EEA;
        }
        .pagination-link.active {
            background: #667EEA;
            color: white;
            border-color: #667EEA;
        }
        .pagination-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .empty-blog {
            text-align: center;
            padding: 80px 20px;
            color: var(--text-secondary);
        }
        .empty-blog h2 {
            font-size: 32px;
            margin-bottom: 16px;
            color: var(--text-primary);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="/" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="/#features">Возможности</a></li>
                    <li><a href="/#how-it-works">Как это работает</a></li>
                    <li><a href="/#buy-business">Купить бизнес</a></li>
                    <li><a href="/blog" style="color: #667EEA; font-weight: 600;">Блог</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="dashboard.php">Продать бизнес</a></li>
                        <li><a href="dashboard.php">Личный кабинет</a></li>
                        <li><a href="logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Продать бизнес</a></li>
                        <li><a href="login.php">Войти</a></li>
                        <li><a href="register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="blog-container">
        <div class="blog-header">
            <h1>Блог SmartBizSell</h1>
            <p>Полезные статьи о продаже и покупке бизнеса, M&A сделках, оценке бизнеса и инвестициях</p>
        </div>

        <?php if (empty($posts)): ?>
            <div class="empty-blog">
                <h2>Статьи скоро появятся</h2>
                <p>Мы готовим интересные материалы о продаже и покупке бизнеса, M&A сделках и инвестициях.</p>
                <p><a href="/" style="color: #667EEA; text-decoration: none; font-weight: 600;">← Вернуться на главную</a></p>
            </div>
        <?php else: ?>
            <div class="blog-grid">
                <?php foreach ($posts as $post): ?>
                    <article class="blog-card">
                        <?php if (!empty($post['category'])): ?>
                            <div class="blog-card-category"><?php echo htmlspecialchars($post['category'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                        <h2 class="blog-card-title">
                            <a href="/blog/<?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        </h2>
                        <?php if (!empty($post['excerpt'])): ?>
                            <p class="blog-card-excerpt"><?php echo htmlspecialchars($post['excerpt'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <div class="blog-card-meta">
                            <div class="blog-card-date">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M8 1V4M8 12V15M3 8H1M15 8H13M3.5 3.5L2 2M14 2L12.5 3.5M3.5 12.5L2 14M14 14L12.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    <circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                                <?php echo date('d.m.Y', strtotime($post['published_at'])); ?>
                            </div>
                            <div class="blog-card-views">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                    <path d="M8 3C4 3 1 6 1 8C1 10 4 13 8 13C12 13 15 10 15 8C15 6 12 3 8 3Z" stroke="currentColor" stroke-width="1.5"/>
                                    <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                                <?php echo number_format($post['views'] ?? 0, 0, '.', ' '); ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="blog-pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="/blog?page=<?php echo $currentPage - 1; ?>" class="pagination-link">← Назад</a>
                    <?php else: ?>
                        <span class="pagination-link disabled">← Назад</span>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == 1 || $i == $totalPages || ($i >= $currentPage - 2 && $i <= $currentPage + 2)): ?>
                            <a href="/blog?page=<?php echo $i; ?>" class="pagination-link <?php echo $i == $currentPage ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php elseif ($i == $currentPage - 3 || $i == $currentPage + 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="/blog?page=<?php echo $currentPage + 1; ?>" class="pagination-link">Вперед →</a>
                    <?php else: ?>
                        <span class="pagination-link disabled">Вперед →</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Структурированные данные для блога -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Blog",
        "name": "Блог SmartBizSell",
        "description": "Полезные статьи о продаже и покупке бизнеса, M&A сделках, оценке бизнеса и инвестициях",
        "url": "<?php echo BASE_URL; ?>/blog",
        "publisher": {
            "@type": "Organization",
            "name": "SmartBizSell",
            "url": "<?php echo BASE_URL; ?>"
        }
    }
    </script>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

