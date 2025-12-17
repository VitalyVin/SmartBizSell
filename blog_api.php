<?php
/**
 * blog_api.php
 * 
 * API для управления статьями блога
 * Позволяет создавать, редактировать, удалять и публиковать статьи
 * Требует авторизации и прав администратора/модератора
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Отключаем вывод ошибок PHP в ответ
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

ob_start();

// Проверка авторизации
if (!isLoggedIn()) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    ob_end_flush();
    exit;
}

$user = getCurrentUser();
if (!$user) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Сессия недействительна.']);
    ob_end_flush();
    exit;
}

// Проверка прав (только модераторы и администраторы могут управлять блогом)
if (!isModerator()) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав для управления блогом.']);
    ob_end_flush();
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Проверяем существование таблицы blog_posts
    $stmt = $pdo->query("SHOW TABLES LIKE 'blog_posts'");
    if ($stmt->rowCount() == 0) {
        // Создаем таблицу, если её нет
        $migrationSql = file_get_contents(__DIR__ . '/db/migration_blog.sql');
        $pdo->exec($migrationSql);
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    switch ($method) {
        case 'GET':
            handleGet($pdo, $action);
            break;
        case 'POST':
            handlePost($pdo, $action);
            break;
        case 'PUT':
            handlePut($pdo, $action);
            break;
        case 'DELETE':
            handleDelete($pdo, $action);
            break;
        default:
            ob_clean();
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Метод не поддерживается.']);
            ob_end_flush();
    }
} catch (Exception $e) {
    ob_clean();
    error_log("Blog API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
    ob_end_flush();
}

/**
 * Обработка GET запросов
 */
function handleGet($pdo, $action) {
    if ($action === 'list') {
        // Список всех статей
        $status = $_GET['status'] ?? 'all';
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;
        
        $where = "1=1";
        $params = [];
        
        if ($status !== 'all') {
            $where .= " AND status = :status";
            $params['status'] = $status;
        }
        
        // Общее количество
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM blog_posts WHERE $where");
        $stmt->execute($params);
        $total = $stmt->fetch()['total'] ?? 0;
        
        // Статьи
        $stmt = $pdo->prepare("
            SELECT 
                id, title, slug, excerpt, category, tags,
                status, published_at, updated_at, created_at, views,
                meta_title, meta_description, keywords, author_id
            FROM blog_posts 
            WHERE $where
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'posts' => $posts,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        
    } elseif ($action === 'get' && isset($_GET['id'])) {
        // Получение одной статьи
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("SELECT * FROM blog_posts WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$post) {
            ob_clean();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Статья не найдена.']);
            ob_end_flush();
            return;
        }
        
        ob_clean();
        echo json_encode(['success' => true, 'post' => $post], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        
    } else {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный запрос.']);
        ob_end_flush();
    }
}

/**
 * Обработка POST запросов (создание статьи)
 */
function handlePost($pdo, $action) {
    if ($action === 'create') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['title']) || empty($data['content'])) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Заголовок и содержание обязательны.']);
            ob_end_flush();
            return;
        }
        
        // Генерируем slug из заголовка
        $slug = generateSlug($data['title']);
        
        // Проверяем уникальность slug
        $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = :slug");
        $stmt->execute(['slug' => $slug]);
        if ($stmt->fetch()) {
            // Добавляем число к slug, если он уже существует
            $counter = 1;
            do {
                $newSlug = $slug . '-' . $counter;
                $stmt->execute(['slug' => $newSlug]);
                $exists = $stmt->fetch();
                if (!$exists) {
                    $slug = $newSlug;
                    break;
                }
                $counter++;
            } while (true);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO blog_posts (
                title, slug, content, excerpt, category, tags,
                meta_title, meta_description, keywords,
                author_id, status, published_at
            ) VALUES (
                :title, :slug, :content, :excerpt, :category, :tags,
                :meta_title, :meta_description, :keywords,
                :author_id, :status, :published_at
            )
        ");
        
        $stmt->execute([
            'title' => $data['title'],
            'slug' => $slug,
            'content' => $data['content'],
            'excerpt' => $data['excerpt'] ?? null,
            'category' => $data['category'] ?? null,
            'tags' => $data['tags'] ?? null,
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'keywords' => $data['keywords'] ?? null,
            'author_id' => getCurrentUser()['id'],
            'status' => $data['status'] ?? 'draft',
            'published_at' => ($data['status'] === 'published' && !empty($data['published_at'])) 
                ? $data['published_at'] 
                : (($data['status'] === 'published') ? date('Y-m-d H:i:s') : null)
        ]);
        
        $id = $pdo->lastInsertId();
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Статья создана.',
            'id' => $id,
            'slug' => $slug
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        
    } else {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный запрос.']);
        ob_end_flush();
    }
}

/**
 * Обработка PUT запросов (обновление статьи)
 */
function handlePut($pdo, $action) {
    if ($action === 'update' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Проверяем существование статьи
        $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE id = :id");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            ob_clean();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Статья не найдена.']);
            ob_end_flush();
            return;
        }
        
        // Формируем запрос обновления
        $fields = [];
        $params = ['id' => $id];
        
        $allowedFields = ['title', 'content', 'excerpt', 'category', 'tags', 
                          'meta_title', 'meta_description', 'keywords', 'status', 'published_at'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }
        
        // Если изменился заголовок, обновляем slug
        if (isset($data['title'])) {
            $slug = generateSlug($data['title']);
            // Проверяем уникальность
            $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE slug = :slug AND id != :id");
            $stmt->execute(['slug' => $slug, 'id' => $id]);
            if ($stmt->fetch()) {
                $counter = 1;
                do {
                    $newSlug = $slug . '-' . $counter;
                    $stmt->execute(['slug' => $newSlug, 'id' => $id]);
                    if (!$stmt->fetch()) {
                        $slug = $newSlug;
                        break;
                    }
                    $counter++;
                } while (true);
            }
            $fields[] = "slug = :slug";
            $params['slug'] = $slug;
        }
        
        if (empty($fields)) {
            ob_clean();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Нет данных для обновления.']);
            ob_end_flush();
            return;
        }
        
        $sql = "UPDATE blog_posts SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Статья обновлена.'
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        
    } else {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный запрос.']);
        ob_end_flush();
    }
}

/**
 * Обработка DELETE запросов (удаление статьи)
 */
function handleDelete($pdo, $action) {
    if ($action === 'delete' && isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        
        // Проверяем существование статьи
        $stmt = $pdo->prepare("SELECT id FROM blog_posts WHERE id = :id");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            ob_clean();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Статья не найдена.']);
            ob_end_flush();
            return;
        }
        
        // Удаляем статью
        $stmt = $pdo->prepare("DELETE FROM blog_posts WHERE id = :id");
        $stmt->execute(['id' => $id]);
        
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Статья удалена.'
        ], JSON_UNESCAPED_UNICODE);
        ob_end_flush();
        
    } else {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Неверный запрос.']);
        ob_end_flush();
    }
}

/**
 * Генерирует URL-friendly slug из заголовка
 */
function generateSlug($title) {
    // Транслитерация кириллицы в латиницу
    $translit = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
    ];
    
    $title = strtr($title, $translit);
    
    // Преобразуем в нижний регистр
    $title = mb_strtolower($title, 'UTF-8');
    
    // Заменяем пробелы и спецсимволы на дефисы
    $title = preg_replace('/[^a-z0-9]+/', '-', $title);
    
    // Удаляем дефисы в начале и конце
    $title = trim($title, '-');
    
    return $title;
}

