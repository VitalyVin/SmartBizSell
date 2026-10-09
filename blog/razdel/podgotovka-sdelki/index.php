<?php
/**
 * Вход раздела блога для nginx, который не читает .htaccess.
 * Каталог с этим файлом открывается как /blog/razdel/{slug}/.
 */
$sectionSlug = basename(__DIR__);
$root = dirname(__DIR__, 3);
chdir($root);
require_once $root . "/blog_sections.php";
if (blogSectionBySlug($sectionSlug) === null) {
    http_response_code(404);
    header("Content-Type: text/plain; charset=utf-8");
    echo "Раздел не найден";
    exit;
}
$path = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?: "";
if (preg_match("#/index\\.php$#", $path)) {
    $target = blogListUrl($sectionSlug, $_GET);
    header("Location: " . $target, true, 301);
    exit;
}
$_GET["section"] = $sectionSlug;
require $root . "/blog.php";

