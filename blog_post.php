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

/**
 * Вычисляет время чтения статьи
 */
function estimateReadingTime(?string $content): int {
    if (empty($content)) {
        return 5;
    }
    $wordCount = str_word_count(strip_tags($content));
    if ($wordCount === 0) {
        return 5;
    }
    $readingTime = ceil($wordCount / 200);
    return max(1, $readingTime);
}

/**
 * Генерирует SVG-иллюстрацию для категории блога
 */
function generateBlogCategoryIllustration(string $category, int $postId = null): string {
    $categoryLower = mb_strtolower($category);
    $themes = [
        'm&a' => ['#667EEA', '#764BA2', '#8B5CF6'],
        'оценка' => ['#3F51B5', '#673AB7', '#6366F1'],
        'инвестиции' => ['#4CAF50', '#009688', '#22C55E'],
        'продажа бизнеса' => ['#9C27B0', '#E91E63', '#EC4899'],
        'покупка бизнеса' => ['#0EA5E9', '#14B8A6', '#06B6D4'],
        'финансы' => ['#607D8B', '#455A64', '#64748B'],
    ];
    
    $theme = ['#667EEA', '#764BA2', '#8B5CF6'];
    foreach ($themes as $key => $colors) {
        if (strpos($categoryLower, $key) !== false) {
            $theme = $colors;
            break;
        }
    }
    
    $gradientStart = $theme[0];
    $gradientEnd = $theme[1];
    $accent = $theme[2];
    $uniqueId = md5($category . $postId);
    $variant = $postId ? (($postId % 5) + 1) : 1;
    
    $svg = '';
    switch ($variant) {
        case 1:
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="blog-grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.4" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.25" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#blog-grad-{$uniqueId})" />
    <circle cx="50" cy="50" r="30" fill="{$accent}" opacity="0.2" />
    <circle cx="150" cy="150" r="40" fill="{$accent}" opacity="0.15" />
</svg>
SVG;
            break;
        case 2:
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="blog-grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.35" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#blog-grad-{$uniqueId})" />
    <path d="M0,100 Q50,50 100,100 T200,100 L200,200 L0,200 Z" fill="{$accent}" opacity="0.2" />
</svg>
SVG;
            break;
        default:
            $svg = <<<SVG
<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid slice">
    <defs>
        <linearGradient id="blog-grad-{$uniqueId}" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:{$gradientStart};stop-opacity:0.3" />
            <stop offset="100%" style="stop-color:{$gradientEnd};stop-opacity:0.2" />
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#blog-grad-{$uniqueId})" />
</svg>
SVG;
    }
    
    return $svg;
}

/**
 * Генерирует оглавление из заголовков статьи
 */
function generateTableOfContents(string $content): array {
    $toc = [];
    if (empty($content)) {
        return $toc;
    }
    
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $content);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $headings = $xpath->query('//h2 | //h3');
    
    foreach ($headings as $index => $heading) {
        $text = trim($heading->textContent);
        if (empty($text)) {
            continue;
        }
        
        $id = 'heading-' . ($index + 1);
        $heading->setAttribute('id', $id);
        
        $level = (int)substr($heading->nodeName, 1);
        $toc[] = [
            'id' => $id,
            'text' => $text,
            'level' => $level
        ];
    }
    
    return $toc;
}

$readingTime = estimateReadingTime($post['content'] ?? '');
$toc = generateTableOfContents($post['content'] ?? '');
$currentUrl = BASE_URL . '/blog/' . htmlspecialchars($post['slug'], ENT_QUOTES, 'UTF-8');
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
    <?php include __DIR__ . '/yandex_metrika.php'; ?>
    
    <style>
        body {
            background: #f8f9fa;
        }
        .reading-progress {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 4px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            z-index: 9999;
            transition: width 0.1s ease;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.4);
        }
        /* Обеспечиваем правильное отображение навигации */
        .navbar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1000 !important;
            background: var(--blur-bg) !important;
            backdrop-filter: saturate(180%) blur(20px) !important;
            -webkit-backdrop-filter: saturate(180%) blur(20px) !important;
            border-bottom: 1px solid var(--blur-border) !important;
        }
        .nav-content {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            padding: 16px 0 !important;
        }
        .logo {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            font-size: 22px !important;
            font-weight: 800 !important;
            text-decoration: none !important;
            color: var(--text-primary) !important;
        }
        .logo:hover {
            text-decoration: none !important;
        }
        .nav-menu {
            display: flex !important;
            list-style: none !important;
            gap: 32px !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .nav-menu li {
            list-style: none !important;
        }
        .nav-menu a {
            color: var(--text-primary) !important;
            text-decoration: none !important;
            font-weight: 500 !important;
            font-size: 15px !important;
            transition: color 0.3s ease !important;
            position: relative !important;
        }
        .nav-menu a:hover {
            color: var(--primary-color) !important;
        }
        .nav-menu a::after {
            content: '' !important;
            position: absolute !important;
            bottom: -4px !important;
            left: 0 !important;
            width: 0 !important;
            height: 2px !important;
            background: var(--primary-color) !important;
            transition: width 0.3s ease !important;
        }
        .nav-menu a:hover::after {
            width: 100% !important;
        }
        .nav-toggle {
            display: none !important;
        }
        .nav-toggle.active span:nth-child(1) {
            transform: rotate(45deg) translate(7px, 7px) !important;
        }
        .nav-toggle.active span:nth-child(2) {
            opacity: 0 !important;
        }
        .nav-toggle.active span:nth-child(3) {
            transform: rotate(-45deg) translate(7px, -7px) !important;
        }
        @media (max-width: 768px) {
            .nav-toggle {
                display: flex !important;
                flex-direction: column !important;
                gap: 4px !important;
                background: none !important;
                border: none !important;
                cursor: pointer !important;
                padding: 8px !important;
            }
            .nav-toggle span {
                width: 24px !important;
                height: 2px !important;
                background: var(--text-primary) !important;
                transition: all 0.3s ease !important;
            }
            .nav-menu {
                position: fixed !important;
                top: 70px !important;
                left: 0 !important;
                right: 0 !important;
                flex-direction: column !important;
                background: var(--blur-bg) !important;
                backdrop-filter: saturate(180%) blur(20px) !important;
                -webkit-backdrop-filter: saturate(180%) blur(20px) !important;
                padding: 20px !important;
                gap: 16px !important;
                transform: translateX(-100%) !important;
                transition: transform 0.3s ease !important;
                border-bottom: 1px solid var(--blur-border) !important;
                z-index: 1001 !important;
                box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1) !important;
            }
            .nav-menu.active {
                transform: translateX(0) !important;
            }
            .nav-menu li {
                width: 100%;
            }
            .nav-menu a {
                display: block !important;
                padding: 12px 0 !important;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05) !important;
            }
            .nav-menu a::after {
                display: none !important;
            }
        }
        .blog-post-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 120px 20px 80px;
        }
        .blog-post-header {
            position: relative;
            margin-bottom: 50px;
            padding: 60px 40px;
            border-radius: 24px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .blog-post-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0.4;
            z-index: 0;
        }
        .blog-post-header > * {
            position: relative;
            z-index: 1;
        }
        .blog-post-category {
            display: inline-block;
            padding: 10px 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 28px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .blog-post-title {
            font-size: 52px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 28px;
            color: #1a1a1a;
            letter-spacing: -0.8px;
        }
        .blog-post-meta {
            display: flex;
            gap: 32px;
            align-items: center;
            font-size: 15px;
            color: #6b7280;
            margin-bottom: 40px;
            padding-bottom: 28px;
            border-bottom: 2px solid rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
        }
        .blog-post-meta span {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .blog-post-reading-time {
            color: #667EEA;
            font-weight: 600;
        }
        .blog-post-share {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .share-btn {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #667EEA;
        }
        .share-btn:hover {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .blog-post-wrapper {
            display: grid;
            grid-template-columns: 1fr 280px;
            gap: 40px;
            margin-bottom: 50px;
        }
        .blog-post-content {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08);
            font-size: 19px;
            line-height: 1.85;
            color: #2d3748;
        }
        .blog-post-sidebar {
            position: sticky;
            top: 100px;
            height: fit-content;
        }
        .blog-toc {
            background: white;
            padding: 28px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
        }
        .blog-toc h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 20px;
            color: #1a1a1a;
        }
        .blog-toc ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .blog-toc li {
            margin-bottom: 12px;
        }
        .blog-toc a {
            color: #6b7280;
            text-decoration: none;
            font-size: 15px;
            line-height: 1.6;
            transition: color 0.3s ease;
            display: block;
            padding-left: 16px;
            position: relative;
        }
        .blog-toc a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #667EEA;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .blog-toc a:hover,
        .blog-toc a.active {
            color: #667EEA;
        }
        .blog-toc a.active::before {
            opacity: 1;
        }
        .back-to-top {
            position: fixed;
            bottom: 40px;
            right: 40px;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border: none;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
            z-index: 100;
        }
        .back-to-top.visible {
            display: flex;
        }
        .back-to-top:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.5);
        }
        .blog-post-content h2 {
            font-size: 36px;
            font-weight: 700;
            margin: 50px 0 24px;
            color: #1a1a1a;
            line-height: 1.3;
            letter-spacing: -0.3px;
            position: relative;
            padding-left: 20px;
        }
        .blog-post-content h2::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            border-radius: 2px;
        }
        .blog-post-content h3 {
            font-size: 28px;
            font-weight: 600;
            margin: 40px 0 20px;
            color: #1a1a1a;
            line-height: 1.4;
            position: relative;
            padding-left: 16px;
        }
        .blog-post-content h3::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            border-radius: 2px;
        }
        .blog-post-content h4 {
            font-size: 22px;
            font-weight: 600;
            margin: 32px 0 16px;
            color: #2d3748;
        }
        .blog-post-content p {
            margin-bottom: 24px;
        }
        .blog-post-content ul,
        .blog-post-content ol {
            margin: 28px 0;
            padding-left: 36px;
        }
        .blog-post-content ul li {
            margin-bottom: 14px;
            line-height: 1.8;
            position: relative;
            padding-left: 8px;
        }
        .blog-post-content ul li::marker {
            color: #667EEA;
        }
        .blog-post-content ul li::before {
            content: '';
            position: absolute;
            left: -20px;
            top: 12px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
        }
        .blog-post-content ol li {
            margin-bottom: 14px;
            line-height: 1.8;
            padding-left: 8px;
        }
        .blog-post-content ol li::marker {
            color: #667EEA;
            font-weight: 600;
        }
        .blog-post-content strong {
            font-weight: 600;
            color: #1a1a1a;
        }
        .blog-post-content a {
            color: #667EEA;
            text-decoration: underline;
            text-underline-offset: 3px;
        }
        .blog-post-content a:hover {
            color: #764BA2;
        }
        .blog-post-content blockquote {
            margin: 40px 0;
            padding: 28px 36px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            border-left: 5px solid #667EEA;
            border-radius: 12px;
            font-style: italic;
            color: #4a5568;
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .blog-post-content blockquote::before {
            content: '"';
            position: absolute;
            top: 10px;
            left: 20px;
            font-size: 60px;
            color: #667EEA;
            opacity: 0.2;
            font-family: Georgia, serif;
        }
        .blog-post-content img {
            max-width: 100%;
            height: auto;
            border-radius: 16px;
            margin: 40px 0;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transition: transform 0.3s ease;
        }
        .blog-post-content img:hover {
            transform: scale(1.02);
        }
        .blog-post-content code {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.9em;
            color: #667EEA;
            font-weight: 600;
        }
        .blog-post-content pre {
            background: #1a1a1a;
            color: #f8f9fa;
            padding: 24px;
            border-radius: 12px;
            overflow-x: auto;
            margin: 32px 0;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(102, 126, 234, 0.2);
        }
        .blog-post-content pre code {
            background: transparent;
            padding: 0;
            color: inherit;
            font-weight: normal;
        }
        .blog-post-content table {
            width: 100%;
            border-collapse: collapse;
            margin: 32px 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-radius: 12px;
            overflow: hidden;
        }
        .blog-post-content table th {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            padding: 16px;
            text-align: left;
            font-weight: 600;
        }
        .blog-post-content table td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }
        .blog-post-content table tr:last-child td {
            border-bottom: none;
        }
        .blog-post-content table tr:nth-child(even) {
            background: rgba(102, 126, 234, 0.03);
        }
        .blog-post-content hr {
            border: none;
            height: 2px;
            background: linear-gradient(90deg, transparent 0%, #667EEA 50%, transparent 100%);
            margin: 48px 0;
        }
        .blog-post-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 50px;
            padding-top: 40px;
            border-top: 2px solid rgba(0, 0, 0, 0.08);
        }
        .blog-post-tag {
            padding: 8px 16px;
            background: #f1f5f9;
            border-radius: 8px;
            font-size: 14px;
            color: #475569;
            font-weight: 500;
            transition: all 0.2s;
        }
        .blog-post-tag:hover {
            background: #e2e8f0;
            transform: translateY(-1px);
        }
        .blog-post-author {
            display: flex;
            gap: 24px;
            padding: 32px;
            margin-top: 50px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            border-radius: 16px;
            border: 2px solid rgba(102, 126, 234, 0.1);
            align-items: center;
        }
        .author-avatar {
            flex-shrink: 0;
        }
        .author-avatar-inner {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
        }
        .author-info {
            flex: 1;
        }
        .author-name {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1a1a1a;
        }
        .author-bio {
            color: var(--text-secondary);
            font-size: 15px;
            line-height: 1.6;
            margin: 0;
        }
        .blog-post-share-bottom {
            margin-top: 50px;
            padding-top: 40px;
            border-top: 2px solid rgba(0, 0, 0, 0.08);
            text-align: center;
        }
        .share-label {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
        }
        .related-posts {
            margin-top: 80px;
            padding-top: 50px;
            border-top: 2px solid rgba(0, 0, 0, 0.1);
        }
        .related-posts h2 {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 40px;
            color: #1a1a1a;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .related-posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 28px;
        }
        .related-post-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.06);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .related-post-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .related-post-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.2);
        }
        .related-post-card:hover::before {
            opacity: 1;
        }
        .related-post-card-header {
            position: relative;
            height: 120px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        }
        .related-post-card-illustration {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.5;
        }
        .related-post-card-content {
            padding: 24px;
            flex: 1;
        }
        .related-post-card a {
            color: #1a1a1a;
            text-decoration: none;
            font-weight: 700;
            font-size: 18px;
            line-height: 1.4;
            display: block;
            margin-bottom: 12px;
            transition: color 0.3s ease;
        }
        .related-post-card a:hover {
            color: #667EEA;
        }
        .related-post-card p {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
        }
        .back-to-blog {
            margin-bottom: 40px;
        }
        .back-to-blog a {
            color: #667EEA;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            transition: all 0.2s;
        }
        .back-to-blog a:hover {
            color: #764BA2;
            transform: translateX(-4px);
        }
        @media (max-width: 1024px) {
            .blog-post-wrapper {
                grid-template-columns: 1fr;
            }
            .blog-post-sidebar {
                position: static;
            }
        }
        @media (max-width: 768px) {
            .blog-post-container {
                padding: 100px 16px 60px;
            }
            .blog-post-header {
                padding: 40px 24px;
            }
            .blog-post-title {
                font-size: 32px;
            }
            .blog-post-content {
                padding: 32px 24px;
                font-size: 17px;
            }
            .blog-post-content h2 {
                font-size: 28px;
            }
            .blog-post-content h3 {
                font-size: 24px;
            }
            .blog-post-meta {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .blog-post-share {
                flex-wrap: wrap;
            }
            .blog-post-author {
                flex-direction: column;
                text-align: center;
                padding: 24px;
            }
            .author-avatar {
                margin: 0 auto;
            }
            .blog-post-share-bottom {
                margin-top: 40px;
                padding-top: 32px;
            }
            .related-posts-grid {
                grid-template-columns: 1fr;
            }
            .back-to-top {
                bottom: 20px;
                right: 20px;
                width: 48px;
                height: 48px;
            }
        }
    </style>
</head>
<body>
    <!-- Reading Progress Indicator -->
    <div class="reading-progress" id="readingProgress"></div>
    
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="/" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="/#how-it-works">Как это работает</a></li>
                    <li><a href="/#buy-business">Купить бизнес</a></li>
                    <li><a href="/blog">Блог</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="/dashboard.php">Продать бизнес</a></li>
                        <?php if (isModerator()): ?>
                            <li><a href="/moderation.php">Модерация</a></li>
                        <?php endif; ?>
                    <?php else: ?>
                        <li><a href="/login.php">Продать бизнес</a></li>
                    <?php endif; ?>
                    <li><a href="/#contact">Контакты</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="/dashboard.php">Личный кабинет</a></li>
                        <li><a href="/logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="/login.php">Войти</a></li>
                        <li><a href="/register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
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
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: inline-block; vertical-align: middle;">
                            <path d="M8 1V4M8 12V15M3 8H1M15 8H13M3.5 3.5L2 2M14 2L12.5 3.5M3.5 12.5L2 14M14 14L12.5 12.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            <circle cx="8" cy="8" r="3" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        <?php echo date('d.m.Y', strtotime($post['published_at'])); ?>
                    </span>
                    <span class="blog-post-reading-time">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: inline-block; vertical-align: middle;">
                            <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M8 4V8L10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                        <?php echo $readingTime; ?> мин чтения
                    </span>
                    <span>
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="display: inline-block; vertical-align: middle;">
                            <path d="M8 3C4 3 1 6 1 8C1 10 4 13 8 13C12 13 15 10 15 8C15 6 12 3 8 3Z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                        </svg>
                        <?php echo number_format($post['views'] ?? 0, 0, '.', ' '); ?> просмотров
                    </span>
                </div>
                <div class="blog-post-share">
                    <a href="https://vk.com/share.php?url=<?php echo urlencode($currentUrl); ?>&title=<?php echo urlencode($post['title']); ?>" 
                       target="_blank" 
                       class="share-btn" 
                       title="Поделиться в VK">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.684 0H8.316C1.592 0 0 1.592 0 8.316v7.368C0 22.408 1.592 24 8.316 24h7.368C22.408 24 24 22.408 24 15.684V8.316C24 1.592 22.408 0 15.684 0zm3.692 17.123h-1.744c-.66 0-.864-.525-2.05-1.727-1.084-1.084-1.57 0-1.57.619v1.108c0 .495-.247.743-.743.743H9.89c-.495 0-.743-.247-.743-.743V9.89c0-.495.247-.743.743-.743h1.108c.495 0 .743.247.743.743v.495c.495-.495 1.084-1.108 2.05-1.108h2.05c.495 0 .743.247.743.743v1.108c0 .495-.247.743-.743.743h-1.108c-.495 0-.743.247-.743.743v2.05c0 .495.247.743.743.743h2.05c.495 0 .743.247.743.743v1.108c0 .495-.247.743-.743.743z"/>
                        </svg>
                    </a>
                    <a href="https://t.me/share/url?url=<?php echo urlencode($currentUrl); ?>&text=<?php echo urlencode($post['title']); ?>" 
                       target="_blank" 
                       class="share-btn" 
                       title="Поделиться в Telegram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.559z"/>
                        </svg>
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($currentUrl); ?>" 
                       target="_blank" 
                       class="share-btn" 
                       title="Поделиться в Facebook">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                </div>
            </div>

            <div class="blog-post-wrapper">
                <div class="blog-post-content" id="blogContent">
                    <?php echo $post['content']; ?>
                </div>
                
                <?php if (!empty($toc)): ?>
                    <div class="blog-post-sidebar">
                        <div class="blog-toc">
                            <h3>Содержание</h3>
                            <ul>
                                <?php foreach ($toc as $item): ?>
                                    <li>
                                        <a href="#<?php echo htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8'); ?>" 
                                           class="toc-link" 
                                           data-level="<?php echo $item['level']; ?>">
                                            <?php echo htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
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

            <!-- Author Section -->
            <div class="blog-post-author">
                <div class="author-avatar">
                    <div class="author-avatar-inner">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                </div>
                <div class="author-info">
                    <h3 class="author-name">SmartBizSell</h3>
                    <p class="author-bio">Эксперты в области M&A сделок, оценки бизнеса и инвестиций</p>
                </div>
            </div>

            <!-- Share Buttons Bottom -->
            <div class="blog-post-share-bottom">
                <p class="share-label">Поделиться статьей:</p>
                <div class="blog-post-share">
                    <a href="https://vk.com/share.php?url=<?php echo urlencode($currentUrl); ?>&title=<?php echo urlencode($post['title']); ?>" 
                       target="_blank" 
                       class="share-btn" 
                       title="Поделиться в VK">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.684 0H8.316C1.592 0 0 1.592 0 8.316v7.368C0 22.408 1.592 24 8.316 24h7.368C22.408 24 24 22.408 24 15.684V8.316C24 1.592 22.408 0 15.684 0zm3.692 17.123h-1.744c-.66 0-.864-.525-2.05-1.727-1.084-1.084-1.57 0-1.57.619v1.108c0 .495-.247.743-.743.743H9.89c-.495 0-.743-.247-.743-.743V9.89c0-.495.247-.743.743-.743h1.108c.495 0 .743.247.743.743v.495c.495-.495 1.084-1.108 2.05-1.108h2.05c.495 0 .743.247.743.743v1.108c0 .495-.247.743-.743.743h-1.108c-.495 0-.743.247-.743.743v2.05c0 .495.247.743.743.743h2.05c.495 0 .743.247.743.743v1.108c0 .495-.247.743-.743.743z"/>
                        </svg>
                    </a>
                    <a href="https://t.me/share/url?url=<?php echo urlencode($currentUrl); ?>&text=<?php echo urlencode($post['title']); ?>" 
                       target="_blank" 
                       class="share-btn" 
                       title="Поделиться в Telegram">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.559z"/>
                        </svg>
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($currentUrl); ?>" 
                       target="_blank" 
                       class="share-btn" 
                       title="Поделиться в Facebook">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                        </svg>
                    </a>
                </div>
            </div>
        </article>

        <?php if (!empty($relatedPosts)): ?>
            <div class="related-posts">
                <h2>Похожие статьи</h2>
                <div class="related-posts-grid">
                    <?php foreach ($relatedPosts as $related): ?>
                        <div class="related-post-card">
                            <div class="related-post-card-header">
                                <?php if (!empty($post['category'])): ?>
                                    <div class="related-post-card-illustration">
                                        <?php 
                                        // Используем функцию из blog.php, если доступна, иначе простой SVG
                                        if (function_exists('generateBlogCategoryIllustration')) {
                                            echo generateBlogCategoryIllustration($post['category'], $related['id']);
                                        } else {
                                            echo '<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="rel-grad-' . $related['id'] . '" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#667EEA;stop-opacity:0.3" /><stop offset="100%" style="stop-color:#764BA2;stop-opacity:0.2" /></linearGradient></defs><rect width="100%" height="100%" fill="url(#rel-grad-' . $related['id'] . ')" /></svg>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="related-post-card-content">
                                <a href="/blog/<?php echo htmlspecialchars($related['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($related['title'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if (!empty($related['excerpt'])): ?>
                                    <p>
                                        <?php echo htmlspecialchars(mb_substr($related['excerpt'], 0, 120) . '...', ENT_QUOTES, 'UTF-8'); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Back to Top Button -->
    <button class="back-to-top" id="backToTop" aria-label="Наверх">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 15l-6-6-6 6"/>
        </svg>
    </button>

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
    <script>
        // Обработка скролла для навигации
        (function() {
            const navbar = document.querySelector('.navbar');
            if (!navbar) return;
            
            let lastScroll = 0;
            window.addEventListener('scroll', () => {
                const currentScroll = window.pageYOffset;
                
                if (currentScroll > 50) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
                
                lastScroll = currentScroll;
            });
        })();

        // Reading Progress Indicator
        (function() {
            const progressBar = document.getElementById('readingProgress');
            if (!progressBar) return;

            function updateProgress() {
                const windowHeight = window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                const scrollPercent = (scrollTop / (documentHeight - windowHeight)) * 100;
                progressBar.style.width = Math.min(100, Math.max(0, scrollPercent)) + '%';
            }

            window.addEventListener('scroll', updateProgress);
            updateProgress();
        })();

        // Back to Top Button
        (function() {
            const backToTopBtn = document.getElementById('backToTop');
            if (!backToTopBtn) return;

            function toggleBackToTop() {
                if (window.pageYOffset > 300) {
                    backToTopBtn.classList.add('visible');
                } else {
                    backToTopBtn.classList.remove('visible');
                }
            }

            backToTopBtn.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });

            window.addEventListener('scroll', toggleBackToTop);
            toggleBackToTop();
        })();

        // Table of Contents Highlighting
        (function() {
            const tocLinks = document.querySelectorAll('.toc-link');
            if (tocLinks.length === 0) return;

            const headings = Array.from(tocLinks).map(link => {
                const id = link.getAttribute('href').substring(1);
                return document.getElementById(id);
            }).filter(Boolean);

            function updateActiveTocLink() {
                let current = '';
                headings.forEach((heading, index) => {
                    const rect = heading.getBoundingClientRect();
                    if (rect.top <= 100) {
                        current = heading.id;
                    }
                });

                tocLinks.forEach(link => {
                    link.classList.remove('active');
                    if (link.getAttribute('href').substring(1) === current) {
                        link.classList.add('active');
                    }
                });
            }

            window.addEventListener('scroll', updateActiveTocLink);
            updateActiveTocLink();
        })();
    </script>
</body>
</html>

