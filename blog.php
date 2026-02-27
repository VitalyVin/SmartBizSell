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
$categories = [];
$selectedCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$normalizedSearchQuery = preg_replace('/\s+/u', ' ', $searchQuery);
if ($normalizedSearchQuery !== null) {
    $searchQuery = trim($normalizedSearchQuery);
}
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$postsPerPage = 15;
$offset = ($currentPage - 1) * $postsPerPage;

if ($blogTableExists) {
    try {
        // Получаем все категории
        $stmt = $pdo->query("SELECT DISTINCT category FROM blog_posts WHERE status = 'published' AND published_at IS NOT NULL AND published_at <= NOW() AND category IS NOT NULL AND category != '' ORDER BY category");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Формируем запрос с фильтрами
        $whereConditions = ["status = 'published'", "published_at IS NOT NULL", "published_at <= NOW()"];
        $params = [];
        
        if (!empty($selectedCategory)) {
            $whereConditions[] = "category = :category";
            $params['category'] = $selectedCategory;
        }
        
        if (!empty($searchQuery)) {
            $whereConditions[] = "(title LIKE :search_title OR excerpt LIKE :search_excerpt OR content LIKE :search_content)";
            $searchValue = '%' . $searchQuery . '%';
            $params['search_title'] = $searchValue;
            $params['search_excerpt'] = $searchValue;
            $params['search_content'] = $searchValue;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Общее количество статей с учетом фильтров
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM blog_posts WHERE {$whereClause}");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $totalPosts = $stmt->fetch()['total'] ?? 0;
        
        // Получаем статьи для текущей страницы
        $stmt = $pdo->prepare("
            SELECT 
                id, title, slug, excerpt, content,
                category, tags, 
                published_at, views,
                meta_title, meta_description
            FROM blog_posts 
            WHERE {$whereClause}
            ORDER BY published_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $postsPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error loading blog posts: " . $e->getMessage());
    }
}

$totalPages = $blogTableExists && $totalPosts > 0 ? ceil($totalPosts / $postsPerPage) : 1;

/**
 * Строит URL блога с сохранением активных фильтров.
 */
function buildBlogUrl(array $overrides = []): string {
    global $selectedCategory, $searchQuery;

    $params = [];
    if ($selectedCategory !== '') {
        $params['category'] = $selectedCategory;
    }
    if ($searchQuery !== '') {
        $params['search'] = $searchQuery;
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    $queryString = http_build_query($params);
    return '/blog' . ($queryString !== '' ? '?' . $queryString : '');
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
    
    // Определяем тему по ключевым словам
    $theme = ['#667EEA', '#764BA2', '#8B5CF6']; // default
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
 * Вычисляет время чтения статьи (примерно)
 */
function estimateReadingTime(?string $content): int {
    if (empty($content)) {
        return 5; // По умолчанию 5 минут
    }
    $wordCount = str_word_count(strip_tags($content));
    if ($wordCount === 0) {
        return 5;
    }
    $readingTime = ceil($wordCount / 200); // 200 слов в минуту
    return max(1, $readingTime);
}

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
            position: relative;
            text-align: center;
            margin-bottom: 60px;
            padding: 80px 40px;
            border-radius: 24px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .blog-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg width="100%" height="100%" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="bg-grad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:%23667EEA;stop-opacity:0.15" /><stop offset="100%" style="stop-color:%23764BA2;stop-opacity:0.1" /></linearGradient></defs><rect width="100%" height="100%" fill="url(%23bg-grad)" /><circle cx="50" cy="50" r="40" fill="%23667EEA" opacity="0.1" /><circle cx="150" cy="150" r="50" fill="%23764BA2" opacity="0.08" /></svg>');
            opacity: 0.6;
            z-index: 0;
        }
        .blog-header > * {
            position: relative;
            z-index: 1;
        }
        .blog-header h1 {
            font-size: 56px;
            font-weight: 800;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
        }
        .blog-header p {
            font-size: 20px;
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto;
            line-height: 1.6;
        }
        .blog-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 40px;
            align-items: center;
        }
        .blog-search {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        .blog-search-form {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .blog-search input {
            flex: 1;
            width: 100%;
            padding: 14px 20px 14px 48px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            font-size: 15px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .blog-search input:focus {
            outline: none;
            border-color: #667EEA;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.2);
            background: white;
        }
        .blog-search svg {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: #667EEA;
            pointer-events: none;
        }
        .blog-search-submit,
        .blog-search-reset {
            border-radius: 10px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        .blog-search-submit {
            padding: 12px 16px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            border-color: transparent;
            color: white;
        }
        .blog-search-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .blog-search-reset {
            padding: 12px 14px;
            background: white;
            color: var(--text-primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .blog-search-reset:hover {
            border-color: #667EEA;
            color: #667EEA;
        }
        .blog-category-filter {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .category-btn {
            padding: 10px 20px;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 20px;
            background: white;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }
        .category-btn:hover {
            border-color: #667EEA;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            transform: translateY(-2px);
        }
        .category-btn.active {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .blog-results-count {
            margin-bottom: 30px;
            color: var(--text-secondary);
            font-size: 15px;
        }
        .blog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
            margin-bottom: 60px;
        }
        .blog-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0, 0, 0, 0.06);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .blog-card::before {
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
        .blog-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.2);
        }
        .blog-card:hover::before {
            opacity: 1;
        }
        .blog-card-header {
            position: relative;
            height: 180px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        }
        .blog-card-illustration {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.6;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .blog-card:hover .blog-card-illustration {
            opacity: 0.8;
            transform: scale(1.05);
        }
        .blog-card-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            padding: 6px 14px;
            background: linear-gradient(135deg, #4FACFE 0%, #00F2FE 100%);
            color: white;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.4);
            z-index: 2;
        }
        .blog-card-category {
            position: absolute;
            top: 16px;
            left: 16px;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            color: #667EEA;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 2;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .blog-card-content {
            padding: 28px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .blog-card-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 14px;
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
            line-height: 1.7;
            margin-bottom: 20px;
            flex: 1;
        }
        .blog-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: var(--text-secondary);
            padding-top: 20px;
            border-top: 2px solid rgba(0, 0, 0, 0.06);
            flex-wrap: wrap;
            gap: 12px;
        }
        .blog-card-date,
        .blog-card-reading-time,
        .blog-card-views {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .blog-card-reading-time {
            color: #667EEA;
            font-weight: 600;
        }
        .blog-pagination {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin-top: 60px;
            flex-wrap: wrap;
        }
        .pagination-link {
            padding: 12px 18px;
            background: white;
            border: 2px solid rgba(102, 126, 234, 0.2);
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
            min-width: 44px;
            text-align: center;
        }
        .pagination-link:hover:not(.disabled) {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-color: transparent;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .pagination-link.active {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        .pagination-link.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
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
        .nav-toggle {
            display: none !important;
        }
        @media (max-width: 768px) {
            .blog-container {
                padding: 100px 16px 60px;
            }
            .blog-header {
                padding: 50px 24px;
            }
            .blog-header h1 {
                font-size: 36px;
            }
            .blog-header p {
                font-size: 16px;
            }
            .blog-filters {
                flex-direction: column;
            }
            .blog-search {
                width: 100%;
            }
            .blog-search-form {
                flex-wrap: wrap;
            }
            .blog-search-submit,
            .blog-search-reset {
                flex: 1;
                text-align: center;
            }
            .blog-category-filter {
                width: 100%;
            }
            .blog-grid {
                grid-template-columns: 1fr;
                gap: 24px;
            }
            .blog-card-header {
                height: 140px;
            }
            .blog-card-content {
                padding: 20px;
            }
            .blog-card-title {
                font-size: 20px;
            }
            .blog-card-meta {
                flex-direction: column;
                align-items: flex-start;
            }
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
            .nav-toggle.active span:nth-child(1) {
                transform: rotate(45deg) translate(7px, 7px);
            }
            .nav-toggle.active span:nth-child(2) {
                opacity: 0;
            }
            .nav-toggle.active span:nth-child(3) {
                transform: rotate(-45deg) translate(7px, -7px);
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
    
    <div class="blog-container">
        <div class="blog-header">
            <h1>Блог SmartBizSell</h1>
            <p>Полезные статьи о продаже и покупке бизнеса, M&A сделках, оценке бизнеса и инвестициях</p>
        </div>

        <div class="blog-filters">
            <div class="blog-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <form method="GET" action="/blog" class="blog-search-form">
                    <?php if (!empty($selectedCategory)): ?>
                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <input type="text" name="search" placeholder="Поиск статей..." value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                    <button type="submit" class="blog-search-submit">Найти</button>
                    <?php if (!empty($searchQuery) || !empty($selectedCategory)): ?>
                        <a href="/blog" class="blog-search-reset">Сбросить</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="blog-category-filter">
                <a href="<?php echo buildBlogUrl(['category' => null, 'page' => null]); ?>" class="category-btn <?php echo empty($selectedCategory) ? 'active' : ''; ?>">
                    Все статьи
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="<?php echo buildBlogUrl(['category' => $cat, 'page' => null]); ?>" 
                       class="category-btn <?php echo $selectedCategory === $cat ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($posts) || !empty($searchQuery) || !empty($selectedCategory)): ?>
            <div class="blog-results-count">
                Найдено статей: <strong><?php echo number_format($totalPosts, 0, '.', ' '); ?></strong>
                <?php if (!empty($searchQuery)): ?>
                    по запросу "<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                <?php endif; ?>
                <?php if (!empty($selectedCategory)): ?>
                    в категории "<?php echo htmlspecialchars($selectedCategory, ENT_QUOTES, 'UTF-8'); ?>"
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($posts)): ?>
            <div class="empty-blog">
                <?php if (!empty($searchQuery) || !empty($selectedCategory)): ?>
                    <h2>По вашему запросу ничего не найдено</h2>
                    <p>Попробуйте изменить формулировку запроса или сбросьте фильтры категорий.</p>
                    <p><a href="/blog" style="color: #667EEA; text-decoration: none; font-weight: 600;">Сбросить фильтры</a></p>
                <?php else: ?>
                    <h2>Статьи скоро появятся</h2>
                    <p>Мы готовим интересные материалы о продаже и покупке бизнеса, M&A сделках и инвестициях.</p>
                    <p><a href="/" style="color: #667EEA; text-decoration: none; font-weight: 600;">← Вернуться на главную</a></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="blog-grid">
                <?php foreach ($posts as $post): 
                    $isNew = (time() - strtotime($post['published_at'])) < (7 * 24 * 60 * 60); // Новое если меньше 7 дней
                    $contentForReading = $post['content'] ?? ($post['excerpt'] ?? '');
                    $readingTime = estimateReadingTime($contentForReading);
                ?>
                    <article class="blog-card">
                        <div class="blog-card-header">
                            <?php if (!empty($post['category'])): ?>
                                <div class="blog-card-illustration">
                                    <?php echo generateBlogCategoryIllustration($post['category'], $post['id']); ?>
                                </div>
                                <div class="blog-card-category"><?php echo htmlspecialchars($post['category'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php if ($isNew): ?>
                                <div class="blog-card-badge">НОВОЕ</div>
                            <?php endif; ?>
                        </div>
                        <div class="blog-card-content">
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
                                <div class="blog-card-reading-time">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                                        <path d="M8 4V8L10 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                    <?php echo $readingTime; ?> мин
                                </div>
                                <div class="blog-card-views">
                                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                        <path d="M8 3C4 3 1 6 1 8C1 10 4 13 8 13C12 13 15 10 15 8C15 6 12 3 8 3Z" stroke="currentColor" stroke-width="1.5"/>
                                        <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.5"/>
                                    </svg>
                                    <?php echo number_format($post['views'] ?? 0, 0, '.', ' '); ?>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="blog-pagination">
                    <?php if ($currentPage > 1): ?>
                        <a href="<?php echo buildBlogUrl(['page' => $currentPage - 1]); ?>" class="pagination-link">← Назад</a>
                    <?php else: ?>
                        <span class="pagination-link disabled">← Назад</span>
                    <?php endif; ?>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == 1 || $i == $totalPages || ($i >= $currentPage - 2 && $i <= $currentPage + 2)): ?>
                            <a href="<?php echo buildBlogUrl(['page' => $i]); ?>" class="pagination-link <?php echo $i == $currentPage ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php elseif ($i == $currentPage - 3 || $i == $currentPage + 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?php echo buildBlogUrl(['page' => $currentPage + 1]); ?>" class="pagination-link">Вперед →</a>
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
    <script>
        // Обработка скролла для навигации (как на главной странице)
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
    </script>
</body>
</html>

