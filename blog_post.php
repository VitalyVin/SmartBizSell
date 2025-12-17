<?php
/**
 * blog_post.php
 * 
 * Страница отдельной статьи блога
 * Отображает полный текст статьи с SEO-оптимизацией
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Получаем slug статьи из URL
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if (empty($slug)) {
    header('Location: /blog');
    exit;
}

// Проверяем существование таблицы блога
try {
    $pdo = getDBConnection();
    $stmt = $pdo->query("SHOW TABLES LIKE 'blog_posts'");
    $blogTableExists = $stmt->rowCount() > 0;
} catch (PDOException $e) {
    $blogTableExists = false;
}

$post = null;
$relatedPosts = [];

if ($blogTableExists) {
    try {
        // Получаем статью
        $stmt = $pdo->prepare("
            SELECT 
                id, title, slug, content, excerpt,
                category, tags,
                published_at, updated_at, views,
                meta_title, meta_description, keywords,
                author_id
            FROM blog_posts 
            WHERE slug = :slug 
                AND status = 'published'
                AND published_at IS NOT NULL 
                AND published_at <= NOW()
        ");
        $stmt->execute(['slug' => $slug]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($post) {
            // Увеличиваем счетчик просмотров
            $stmt = $pdo->prepare("UPDATE blog_posts SET views = views + 1 WHERE id = :id");
            $stmt->execute(['id' => $post['id']]);
            
            // Получаем связанные статьи (той же категории)
            if (!empty($post['category'])) {
                $stmt = $pdo->prepare("
                    SELECT id, title, slug, excerpt, published_at
                    FROM blog_posts 
                    WHERE category = :category 
                        AND id != :id
                        AND status = 'published'
                        AND published_at IS NOT NULL 
                        AND published_at <= NOW()
                    ORDER BY published_at DESC 
                    LIMIT 3
                ");
                $stmt->execute([
                    'category' => $post['category'],
                    'id' => $post['id']
                ]);
                $relatedPosts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        error_log("Error loading blog post: " . $e->getMessage());
    }
}

if (!$post) {
    header('HTTP/1.0 404 Not Found');
    header('Location: /blog');
    exit;
}

// Мета-теги для SEO
$pageTitle = !empty($post['meta_title']) ? $post['meta_title'] : $post['title'] . ' | Блог SmartBizSell';
$pageDescription = !empty($post['meta_description']) ? $post['meta_description'] : (!empty($post['excerpt']) ? $post['excerpt'] : 'Статья блога SmartBizSell о продаже и покупке бизнеса, M&A сделках и инвестициях.');
$pageKeywords = !empty($post['keywords']) ? $post['keywords'] : 'продажа бизнеса, покупка бизнеса, M&A, инвестиции';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($pageKeywords, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="index, follow">
    <meta name="author" content="SmartBizSell">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/blog/<?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/blog/<?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="article:published_time" content="<?php echo date('c', strtotime($post['published_at'])); ?>">
    <meta property="article:modified_time" content="<?php echo date('c', strtotime($post['updated_at'])); ?>">
    <?php if (!empty($post['category'])): ?>
        <meta property="article:section" content="<?php echo htmlspecialchars($post['category'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        .blog-post-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 100px 20px 60px;
        }
        .blog-post-header {
            margin-bottom: 40px;
        }
        .blog-post-category {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(102, 126, 234, 0.1);
            color: #667EEA;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 20px;
        }
        .blog-post-title {
            font-size: 42px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 20px;
            color: var(--text-primary);
        }
        .blog-post-meta {
            display: flex;
            gap: 24px;
            align-items: center;
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .blog-post-content {
            font-size: 18px;
            line-height: 1.8;
            color: var(--text-primary);
        }
        .blog-post-content h2 {
            font-size: 32px;
            font-weight: 700;
            margin: 40px 0 20px;
            color: var(--text-primary);
        }
        .blog-post-content h3 {
            font-size: 24px;
            font-weight: 600;
            margin: 30px 0 16px;
            color: var(--text-primary);
        }
        .blog-post-content p {
            margin-bottom: 20px;
        }
        .blog-post-content ul,
        .blog-post-content ol {
            margin: 20px 0;
            padding-left: 30px;
        }
        .blog-post-content li {
            margin-bottom: 10px;
        }
        .blog-post-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }
        .blog-post-tag {
            padding: 6px 12px;
            background: rgba(0, 0, 0, 0.05);
            border-radius: 6px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .related-posts {
            margin-top: 60px;
            padding-top: 40px;
            border-top: 2px solid rgba(0, 0, 0, 0.1);
        }
        .related-posts h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 30px;
        }
        .related-posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }
        .related-post-card {
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .related-post-card a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .related-post-card a:hover {
            color: #667EEA;
        }
        .back-to-blog {
            margin-bottom: 30px;
        }
        .back-to-blog a {
            color: #667EEA;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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

    <div class="blog-post-container">
        <div class="back-to-blog">
            <a href="/blog">← Вернуться к списку статей</a>
        </div>

        <article class="blog-post">
            <div class="blog-post-header">
                <?php if (!empty($post['category'])): ?>
                    <div class="blog-post-category"><?php echo htmlspecialchars($post['category'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <h1 class="blog-post-title"><?php echo htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <div class="blog-post-meta">
                    <span>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: inline-block; vertical-align: middle; margin-right: 4px;">
                            <path d="M8 1V4M8 12V15M3 8H1M15 8H13M3.5 3.5L2 2M14 2L12.5 3.5M3.5 12.5L2 14M14 14L12.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        <?php echo date('d.m.Y', strtotime($post['published_at'])); ?>
                    </span>
                    <span>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: inline-block; vertical-align: middle; margin-right: 4px;">
                            <path d="M8 3C4 3 1 6 1 8C1 10 4 13 8 13C12 13 15 10 15 8C15 6 12 3 8 3Z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        <?php echo number_format($post['views'] ?? 0, 0, '.', ' '); ?> просмотров
                    </span>
                </div>
            </div>

            <div class="blog-post-content">
                <?php echo $post['content']; ?>
            </div>

            <?php if (!empty($post['tags'])): ?>
                <div class="blog-post-tags">
                    <?php 
                    $tags = array_map('trim', explode(',', $post['tags']));
                    foreach ($tags as $tag): 
                        if (!empty($tag)):
                    ?>
                        <span class="blog-post-tag">#<?php echo htmlspecialchars($tag, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            <?php endif; ?>
        </article>

        <?php if (!empty($relatedPosts)): ?>
            <div class="related-posts">
                <h2>Похожие статьи</h2>
                <div class="related-posts-grid">
                    <?php foreach ($relatedPosts as $related): ?>
                        <div class="related-post-card">
                            <a href="/blog/<?php echo htmlspecialchars($related['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($related['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                            <?php if (!empty($related['excerpt'])): ?>
                                <p style="margin-top: 12px; font-size: 14px; color: var(--text-secondary);">
                                    <?php echo htmlspecialchars(mb_substr($related['excerpt'], 0, 100) . '...', ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Структурированные данные для статьи -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "BlogPosting",
        "headline": <?php echo json_encode($post['title'], JSON_UNESCAPED_UNICODE); ?>,
        "description": <?php echo json_encode($pageDescription, JSON_UNESCAPED_UNICODE); ?>,
        "datePublished": "<?php echo date('c', strtotime($post['published_at'])); ?>",
        "dateModified": "<?php echo date('c', strtotime($post['updated_at'])); ?>",
        "author": {
            "@type": "Organization",
            "name": "SmartBizSell",
            "url": "<?php echo BASE_URL; ?>"
        },
        "publisher": {
            "@type": "Organization",
            "name": "SmartBizSell",
            "url": "<?php echo BASE_URL; ?>",
            "logo": {
                "@type": "ImageObject",
                "url": "<?php echo BASE_URL; ?>/logo.png"
            }
        },
        "mainEntityOfPage": {
            "@type": "WebPage",
            "@id": "<?php echo BASE_URL; ?>/blog/<?php echo htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8'); ?>"
        }
        <?php if (!empty($post['category'])): ?>
        ,"articleSection": <?php echo json_encode($post['category'], JSON_UNESCAPED_UNICODE); ?>
        <?php endif; ?>
    }
    </script>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

