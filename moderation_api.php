<?php
/**
 * API для модерации тизеров
 * 
 * Эндпоинты:
 * - save: сохранение отредактированного тизера
 * - approve: одобрение тизера
 * - reject: отклонение с комментарием
 * - publish: публикация на главной странице
 * - list: получение списка тизеров для модерации
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации и прав модератора
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    exit;
}

if (!isModerator()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен. Только модераторы могут выполнять эти действия.']);
    exit;
}

// Создаем таблицу, если её нет
ensurePublishedTeasersTable();

$user = getCurrentUser();
$pdo = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'save':
        case 'approve':
        case 'reject':
            /**
             * Сохранение отредактированного тизера
             * 
             * Поддерживает два формата входных данных:
             * 1. JSON (application/json) - для AJAX запросов
             * 2. FormData (multipart/form-data) - для форм с файлами или большими данными
             * 
             * Параметры:
             * - teaser_id: ID тизера для обновления
             * - moderated_html: Отредактированный HTML тизера
             * - moderation_notes: Заметки модератора (обязательны при отклонении)
             * - status_action: Действие ('save', 'approved', 'rejected')
             */
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                // Парсим JSON данные
                $input = json_decode(file_get_contents('php://input'), true);
                $teaserId = isset($input['teaser_id']) ? (int)$input['teaser_id'] : 0;
                $moderatedHtml = $input['moderated_html'] ?? '';
                $moderationNotes = $input['moderation_notes'] ?? '';
                $cardTitle = isset($input['card_title']) ? trim($input['card_title']) : null;
                $statusAction = $input['status_action'] ?? 'save';
            } else {
                // Парсим FormData или обычный POST
                $teaserId = isset($_POST['teaser_id']) ? (int)$_POST['teaser_id'] : 0;
                $moderatedHtml = $_POST['moderated_html'] ?? '';
                $moderationNotes = $_POST['moderation_notes'] ?? '';
                $cardTitle = isset($_POST['card_title']) && trim($_POST['card_title']) !== '' ? trim($_POST['card_title']) : null;
                $statusAction = $_POST['status_action'] ?? 'save';
            }
            
            if ($teaserId <= 0) {
                throw new Exception('Не указан ID тизера.');
            }
            
            // Определяем статус
            $newStatus = 'pending';
            if ($statusAction === 'approved') {
                $newStatus = 'approved';
            } elseif ($statusAction === 'rejected') {
                $newStatus = 'rejected';
                if (empty($moderationNotes)) {
                    throw new Exception('При отклонении необходимо указать причину.');
                }
            } elseif ($statusAction === 'published') {
                $newStatus = 'published';
            }
            
            // Проверяем существование тизера
            $stmt = $pdo->prepare("SELECT id, seller_form_id FROM published_teasers WHERE id = ?");
            $stmt->execute([$teaserId]);
            $existingTeaser = $stmt->fetch();
            
            if (!$existingTeaser) {
                throw new Exception('Тизер не найден.');
            }
            
            // Обновляем или создаем запись
            $moderatedAt = ($newStatus !== 'pending' && $newStatus !== 'save') ? date('Y-m-d H:i:s') : null;
            $publishedAt = ($newStatus === 'published') ? date('Y-m-d H:i:s') : null;
            
            $stmt = $pdo->prepare("
                UPDATE published_teasers 
                SET 
                    moderated_html = ?,
                    moderation_status = ?,
                    moderation_notes = ?,
                    card_title = ?,
                    moderator_id = ?,
                    moderated_at = ?,
                    published_at = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([
                $moderatedHtml,
                $newStatus,
                $moderationNotes,
                $cardTitle,
                $user['id'],
                $moderatedAt,
                $publishedAt,
                $teaserId
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Тизер успешно сохранен.',
                'status' => $newStatus
            ]);
            break;
            
        case 'publish':
            /**
             * Публикация тизера на главной странице
             * 
             * Логика публикации:
             * 1. Проверяет, что тизер одобрен (status = 'approved')
             * 2. Снимает с публикации все другие тизеры для той же анкеты (seller_form_id)
             * 3. Устанавливает статус 'published' и published_at для текущего тизера
             * 
             * Это гарантирует, что на главной странице будет отображаться только один
             * актуальный тизер для каждой анкеты, избегая дублирования.
             */
            // Поддерживаем как FormData (multipart/form-data), так и JSON
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true);
                $teaserId = isset($input['teaser_id']) ? (int)$input['teaser_id'] : 0;
            } else {
                // FormData или обычный POST
                $teaserId = isset($_POST['teaser_id']) ? (int)$_POST['teaser_id'] : 0;
            }
            
            if ($teaserId <= 0) {
                throw new Exception('Не указан ID тизера.');
            }
            
            // Проверяем, что тизер одобрен (публиковать можно только одобренные тизеры)
            $stmt = $pdo->prepare("SELECT id, moderation_status, seller_form_id FROM published_teasers WHERE id = ?");
            $stmt->execute([$teaserId]);
            $teaser = $stmt->fetch();
            
            if (!$teaser) {
                throw new Exception('Тизер не найден.');
            }
            
            if ($teaser['moderation_status'] !== 'approved') {
                throw new Exception('Можно публиковать только одобренные тизеры.');
            }
            
            // При публикации нового тизера сбрасываем статус опубликованных тизеров для той же анкеты
            // чтобы избежать дублирования на главной странице
            // Старые тизеры возвращаются в статус 'approved', но не 'published'
            $stmt = $pdo->prepare("
                UPDATE published_teasers 
                SET 
                    moderation_status = 'approved',
                    published_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE seller_form_id = ? AND id != ? AND moderation_status = 'published'
            ");
            $stmt->execute([$teaser['seller_form_id'], $teaserId]);
            
            // Публикуем новый тизер
            $stmt = $pdo->prepare("
                UPDATE published_teasers 
                SET 
                    moderation_status = 'published',
                    published_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$teaserId]);
            
            // Наследуем просмотры от всех предыдущих версий тизера этой анкеты
            // Суммируем views всех других строк с тем же seller_form_id
            // Используем два отдельных запроса, чтобы избежать ошибки MySQL 1093
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(views), 0) AS total_views
                FROM published_teasers
                WHERE seller_form_id = ?
                  AND id != ?
            ");
            $stmt->execute([$teaser['seller_form_id'], $teaserId]);
            $totalViews = (int) ($stmt->fetchColumn() ?? 0);

            $stmt = $pdo->prepare("
                UPDATE published_teasers
                SET views = ?
                WHERE id = ?
            ");
            $stmt->execute([$totalViews, $teaserId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Тизер успешно опубликован на главной странице.'
            ]);
            break;
            
        case 'unpublish':
            /**
             * Снятие тизера с публикации
             * 
             * Логика снятия с публикации:
             * 1. Проверяет, что тизер опубликован (status = 'published')
             * 2. Переводит статус в 'pending' (На модерации)
             * 3. Устанавливает published_at в NULL
             * 
             * После этого тизер исчезнет с главной страницы, так как там
             * отображаются только тизеры со статусом 'published'.
             */
            // Поддерживаем как FormData (multipart/form-data), так и JSON
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true);
                $teaserId = isset($input['teaser_id']) ? (int)$input['teaser_id'] : 0;
            } else {
                // FormData или обычный POST
                $teaserId = isset($_POST['teaser_id']) ? (int)$_POST['teaser_id'] : 0;
            }
            
            if ($teaserId <= 0) {
                throw new Exception('Не указан ID тизера.');
            }
            
            // Проверяем, что тизер опубликован
            $stmt = $pdo->prepare("SELECT id, moderation_status FROM published_teasers WHERE id = ?");
            $stmt->execute([$teaserId]);
            $teaser = $stmt->fetch();
            
            if (!$teaser) {
                throw new Exception('Тизер не найден.');
            }
            
            if ($teaser['moderation_status'] !== 'published') {
                throw new Exception('Можно снять с публикации только опубликованные тизеры.');
            }
            
            // Снимаем тизер с публикации, переводя в статус 'pending' (На модерации)
            $stmt = $pdo->prepare("
                UPDATE published_teasers 
                SET 
                    moderation_status = 'pending',
                    published_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$teaserId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Тизер снят с публикации и переведен в статус "На модерации". Карточка удалена с главной страницы.'
            ]);
            break;
            
        case 'list':
            // Получение списка тизеров
            $statusFilter = $_GET['status'] ?? 'all';
            $searchQuery = $_GET['search'] ?? '';
            
            $whereConditions = [];
            $params = [];
            
            if ($statusFilter !== 'all') {
                $whereConditions[] = "pt.moderation_status = ?";
                $params[] = $statusFilter;
            }
            
            if (!empty($searchQuery)) {
                $whereConditions[] = "(sf.asset_name LIKE ? OR u.full_name LIKE ?)";
                $searchParam = '%' . $searchQuery . '%';
                $params[] = $searchParam;
                $params[] = $searchParam;
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $stmt = $pdo->prepare("
                SELECT 
                    pt.id,
                    pt.seller_form_id,
                    pt.moderation_status,
                    pt.moderated_at,
                    pt.published_at,
                    pt.created_at,
                    sf.asset_name,
                    u.full_name as seller_name,
                    u.email as seller_email
                FROM published_teasers pt
                INNER JOIN seller_forms sf ON pt.seller_form_id = sf.id
                INNER JOIN users u ON sf.user_id = u.id
                $whereClause
                ORDER BY pt.created_at DESC
                LIMIT 100
            ");
            
            $stmt->execute($params);
            $teasers = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'teasers' => $teasers
            ]);
            break;
            
        default:
            throw new Exception('Неизвестное действие.');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    error_log("Moderation API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных.'
    ]);
}

