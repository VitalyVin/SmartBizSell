<?php
/**
 * Список блога для адреса /blog/.
 * Каталог blog/ нужен разделам, и nginx открывает его как папку.
 * Без этого файла /blog/ отвечает 403.
 */
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/blog_sections.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (preg_match('#/index\\.php$#', $path)) {
    header('Location: ' . blogListUrl(null, $_GET), true, 301);
    exit;
}
require $root . '/blog.php';
