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
            // Сохранение отредактированного тизера
            // Поддерживаем как FormData (multipart/form-data), так и JSON
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (strpos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true);
                $teaserId = isset($input['teaser_id']) ? (int)$input['teaser_id'] : 0;
                $moderatedHtml = $input['moderated_html'] ?? '';
                $moderationNotes = $input['moderation_notes'] ?? '';
                $statusAction = $input['status_action'] ?? 'save';
            } else {
                // FormData или обычный POST
                $teaserId = isset($_POST['teaser_id']) ? (int)$_POST['teaser_id'] : 0;
                $moderatedHtml = $_POST['moderated_html'] ?? '';
                $moderationNotes = $_POST['moderation_notes'] ?? '';
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
            // Публикация тизера
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
            
            // Проверяем, что тизер одобрен
            $stmt = $pdo->prepare("SELECT id, moderation_status FROM published_teasers WHERE id = ?");
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
            $stmt = $pdo->prepare("SELECT seller_form_id FROM published_teasers WHERE id = ?");
            $stmt->execute([$teaserId]);
            $teaserData = $stmt->fetch();
            
            if ($teaserData) {
                // Сбрасываем статус других опубликованных тизеров для этой анкеты
                $stmt = $pdo->prepare("
                    UPDATE published_teasers 
                    SET 
                        moderation_status = 'approved',
                        published_at = NULL,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE seller_form_id = ? AND id != ? AND moderation_status = 'published'
                ");
                $stmt->execute([$teaserData['seller_form_id'], $teaserId]);
            }
            
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
            
            echo json_encode([
                'success' => true,
                'message' => 'Тизер успешно опубликован на главной странице.'
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

