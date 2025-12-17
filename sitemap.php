<?php
/**
 * sitemap.php
 * 
 * Динамическая генерация XML sitemap для поисковых систем
 * Включает все публичные страницы сайта
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

header('Content-Type: application/xml; charset=utf-8');

// Базовый URL сайта
$baseUrl = BASE_URL;

// Приоритеты страниц (от 0.0 до 1.0)
$priorities = [
    'index' => 1.0,
    'blog' => 0.8,
    'blog_post' => 0.7,
    'services' => 0.9,
    'about' => 0.6,
    'faq' => 0.7,
    'ai-knowledge-base' => 0.5,
];

// Частота обновления
$changefreq = [
    'index' => 'daily',
    'blog' => 'daily',
    'blog_post' => 'weekly',
    'services' => 'monthly',
    'about' => 'monthly',
    'faq' => 'monthly',
    'ai-knowledge-base' => 'weekly',
];

// Начало XML
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
echo '        xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9' . "\n";
echo '        http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n";

// Главная страница
echo "  <url>\n";
echo "    <loc>{$baseUrl}/</loc>\n";
echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
echo "    <changefreq>{$changefreq['index']}</changefreq>\n";
echo "    <priority>{$priorities['index']}</priority>\n";
echo "  </url>\n";

// Страница блога
echo "  <url>\n";
echo "    <loc>{$baseUrl}/blog</loc>\n";
echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
echo "    <changefreq>{$changefreq['blog']}</changefreq>\n";
echo "    <priority>{$priorities['blog']}</priority>\n";
echo "  </url>\n";

// Страницы услуг
$services = [
    'sell-business' => 'Продажа бизнеса',
    'buy-business' => 'Покупка бизнеса',
    'valuation' => 'Оценка бизнеса',
    'ma-advisory' => 'M&A консалтинг',
];

foreach ($services as $slug => $title) {
    echo "  <url>\n";
    echo "    <loc>{$baseUrl}/services/{$slug}</loc>\n";
    echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
    echo "    <changefreq>{$changefreq['services']}</changefreq>\n";
    echo "    <priority>{$priorities['services']}</priority>\n";
    echo "  </url>\n";
}

// Страница "О нас"
echo "  <url>\n";
echo "    <loc>{$baseUrl}/about</loc>\n";
echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
echo "    <changefreq>{$changefreq['about']}</changefreq>\n";
echo "    <priority>{$priorities['about']}</priority>\n";
echo "  </url>\n";

// FAQ страница
echo "  <url>\n";
echo "    <loc>{$baseUrl}/faq</loc>\n";
echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
echo "    <changefreq>{$changefreq['faq']}</changefreq>\n";
echo "    <priority>{$priorities['faq']}</priority>\n";
echo "  </url>\n";

// AI Knowledge Base (для AI-краулеров)
echo "  <url>\n";
echo "    <loc>{$baseUrl}/ai-knowledge-base</loc>\n";
echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
echo "    <changefreq>{$changefreq['ai-knowledge-base']}</changefreq>\n";
echo "    <priority>{$priorities['ai-knowledge-base']}</priority>\n";
echo "  </url>\n";

// Статьи блога из базы данных
try {
    $pdo = getDBConnection();
    
    // Проверяем, существует ли таблица blog_posts
    $stmt = $pdo->query("SHOW TABLES LIKE 'blog_posts'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("
            SELECT slug, updated_at, published_at 
            FROM blog_posts 
            WHERE published_at IS NOT NULL 
            ORDER BY published_at DESC
        ");
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($posts as $post) {
            $lastmod = !empty($post['updated_at']) ? date('Y-m-d', strtotime($post['updated_at'])) : date('Y-m-d', strtotime($post['published_at']));
            echo "  <url>\n";
            echo "    <loc>{$baseUrl}/blog/{$post['slug']}</loc>\n";
            echo "    <lastmod>{$lastmod}</lastmod>\n";
            echo "    <changefreq>{$changefreq['blog_post']}</changefreq>\n";
            echo "    <priority>{$priorities['blog_post']}</priority>\n";
            echo "  </url>\n";
        }
    }
} catch (PDOException $e) {
    // Таблица блога еще не создана, пропускаем
    error_log("Blog posts table not found: " . $e->getMessage());
}

// Публичные тизеры (опционально, если нужны отдельные URL)
// Можно добавить позже, если будет необходимость

echo '</urlset>';

