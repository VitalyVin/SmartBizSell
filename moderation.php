<?php
/**
 * Интерфейс модерации тизеров
 * 
 * Функциональность:
 * - Список тизеров на модерацию с фильтрацией
 * - Редактирование HTML тизера
 * - Одобрение, отклонение и публикация тизеров
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Проверка авторизации и прав модератора
if (!isLoggedIn()) {
    redirectToLogin();
}

if (!isModerator()) {
    http_response_code(403);
    die('Доступ запрещен. Только модераторы могут просматривать эту страницу.');
}

// Создаем таблицу, если её нет
ensurePublishedTeasersTable();

$user = getCurrentUser();
$pdo = getDBConnection();

// Получаем параметры фильтрации
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Получаем ID тизера для редактирования
$teaserId = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Получаем список тизеров для модерации
$teasers = [];
try {
    $whereConditions = [];
    $params = [];
    
    if ($statusFilter !== 'all') {
        $whereConditions[] = "pt.moderation_status = ?";
        $params[] = $statusFilter;
    } else {
        $whereConditions[] = "pt.moderation_status IS NOT NULL";
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
            pt.moderated_html,
            pt.moderation_notes,
            pt.moderated_at,
            pt.published_at,
            pt.created_at,
            sf.asset_name,
            sf.status as form_status,
            u.full_name as seller_name,
            u.email as seller_email,
            sf.data_json
        FROM published_teasers pt
        INNER JOIN seller_forms sf ON pt.seller_form_id = sf.id
        INNER JOIN users u ON sf.user_id = u.id
        $whereClause
        ORDER BY pt.created_at DESC
        LIMIT 100
    ");
    
    $stmt->execute($params);
    $teasers = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching teasers for moderation: " . $e->getMessage());
}

// Если передан ID, получаем данные конкретного тизера для редактирования
$currentTeaser = null;
$originalHtml = null;
if ($teaserId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pt.*,
                sf.asset_name,
                sf.data_json,
                u.full_name as seller_name
            FROM published_teasers pt
            INNER JOIN seller_forms sf ON pt.seller_form_id = sf.id
            INNER JOIN users u ON sf.user_id = u.id
            WHERE pt.id = ?
        ");
        $stmt->execute([$teaserId]);
        $currentTeaser = $stmt->fetch();
        
        if ($currentTeaser) {
            // Извлекаем оригинальный HTML из data_json
            $formData = json_decode($currentTeaser['data_json'], true);
            if (is_array($formData) && !empty($formData['teaser_snapshot']['html'])) {
                $originalHtml = $formData['teaser_snapshot']['html'];
            }
            
            // Если есть отредактированная версия, используем её
            if (!empty($currentTeaser['moderated_html'])) {
                $originalHtml = $currentTeaser['moderated_html'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching teaser for editing: " . $e->getMessage());
    }
}

// Статистика
$stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'published' => 0,
    'total' => 0
];

try {
    $stmt = $pdo->query("
        SELECT moderation_status, COUNT(*) as count
        FROM published_teasers
        GROUP BY moderation_status
    ");
    $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($statusCounts as $status => $count) {
        $stats[$status] = (int)$count;
        $stats['total'] += (int)$count;
    }
} catch (PDOException $e) {
    error_log("Error fetching moderation stats: " . $e->getMessage());
}

$statusLabels = [
    'pending' => 'На модерации',
    'approved' => 'Одобрено',
    'rejected' => 'Отклонено',
    'published' => 'Опубликовано'
];

$statusColors = [
    'pending' => '#FF9500',
    'approved' => '#34C759',
    'rejected' => '#FF3B30',
    'published' => '#007AFF'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Модерация тизеров - SmartBizSell</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/moderation.php">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1"></script>
    <style>
        .moderation-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        body.moderation-page {
            background: #f5f5f7;
            padding-top: 96px; /* компенсируем фиксированный navbar */
        }
        @media (max-width: 768px) {
            body.moderation-page {
                padding-top: 120px;
            }
        }
        .moderation-header {
            margin-bottom: 32px;
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .moderation-header p {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 4px 0 0 0;
        }
        .ai-provider-selector {
            margin-top: 0;
            display: flex !important;
            align-items: center !important;
            gap: 12px !important;
            flex-wrap: wrap !important;
        }
        .ai-provider-selector label {
            font-size: 14px !important;
            color: var(--text-secondary) !important;
            font-weight: 500 !important;
            white-space: nowrap !important;
        }
        #ai-provider {
            padding: 10px 16px !important;
            border: 2px solid rgba(0, 0, 0, 0.1) !important;
            border-radius: 8px !important;
            font-size: 14px !important;
            background: white !important;
            cursor: pointer !important;
            min-width: 220px !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
        }
        #ai-provider:hover {
            border-color: rgba(0, 122, 255, 0.3) !important;
        }
        #provider-status {
            font-size: 12px !important;
            color: var(--text-secondary) !important;
            padding: 6px 12px !important;
            background: rgba(0, 0, 0, 0.05) !important;
            border-radius: 6px !important;
            white-space: nowrap !important;
        }
        @media (max-width: 768px) {
            .moderation-header > div {
                flex-direction: column !important;
                align-items: flex-start !important;
            }
            .ai-provider-selector {
                width: 100% !important;
                justify-content: flex-start !important;
                margin-top: 16px !important;
            }
            #ai-provider {
                flex: 1 !important;
                min-width: auto !important;
            }
        }
        
        /* Стили для табов */
        .moderation-tab.active {
            color: var(--primary-color) !important;
            border-bottom-color: var(--primary-color) !important;
        }
        .moderation-tab-content {
            display: none;
        }
        .moderation-tab-content.active {
            display: block;
        }
        @media (max-width: 768px) {
            .moderation-tabs {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            .moderation-tab {
                white-space: nowrap;
                flex-shrink: 0;
            }
        }
        .moderation-header h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .moderation-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.08);
        }
        .stat-card__value {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .stat-card__label {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .moderation-filters {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }
        .moderation-filters select,
        .moderation-filters input {
            padding: 10px 16px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            font-size: 14px;
        }
        .teasers-list {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .teaser-item {
            padding: 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .teaser-item:last-child {
            border-bottom: none;
        }
        .teaser-item:hover {
            background: rgba(0, 0, 0, 0.02);
        }
        .teaser-info {
            flex: 1;
        }
        .teaser-info h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .teaser-info p {
            font-size: 14px;
            color: var(--text-secondary);
            margin: 4px 0;
        }
        .teaser-actions {
            display: flex;
            gap: 8px;
        }
        .editor-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 24px;
        }
        .editor-panel {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .editor-panel h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 16px;
        }
        .editor-textarea {
            width: 100%;
            min-height: 600px;
            padding: 16px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            resize: vertical;
        }
        .preview-container {
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            padding: 16px;
            min-height: 600px;
            overflow-y: auto;
            background: #f8f9fa;
        }
        .moderation-checklist {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .moderation-checklist h4 {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        .checklist-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .checklist-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        @media (max-width: 1024px) {
            .editor-container {
                grid-template-columns: 1fr;
            }
        }
        
        /* Стили для мобильного меню */
        @media (max-width: 768px) {
            .nav-toggle.active span:nth-child(1) {
                transform: rotate(45deg) translate(5px, 5px);
            }
            .nav-toggle.active span:nth-child(2) {
                opacity: 0;
            }
            .nav-toggle.active span:nth-child(3) {
                transform: rotate(-45deg) translate(7px, -6px);
            }
            
            /* Убеждаемся, что меню не исчезает при открытии */
            .nav-menu.active {
                z-index: 1001;
            }
        }
    </style>
</head>
<body class="moderation-page">
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="index.php">Главная</a></li>
                    <li><a href="dashboard.php">Личный кабинет</a></li>
                    <li><a href="moderation.php">Модерация</a></li>
                    <li><a href="users_list.php">Пользователи</a></li>
                    <li><a href="logout.php">Выйти</a></li>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <div class="moderation-container">
        <div class="moderation-header">
            <h1>Модерация</h1>
            <p>Управление тизерами и блогом</p>
        </div>
        
        <!-- Табы для переключения между разделами -->
        <div class="moderation-tabs" style="display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 2px solid rgba(0, 0, 0, 0.1);">
            <button class="moderation-tab active" data-tab="teasers" style="padding: 12px 24px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: 16px; font-weight: 600; color: var(--text-secondary); transition: all 0.3s;">
                Модерация тизеров
            </button>
            <button class="moderation-tab" data-tab="blog" style="padding: 12px 24px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: 16px; font-weight: 600; color: var(--text-secondary); transition: all 0.3s;">
                Управление блогом
            </button>
        </div>
        
        <!-- Контент модерации тизеров -->
        <div id="teasers-tab" class="moderation-tab-content active">
        <!-- Переключатель AI провайдера -->
        <div class="ai-provider-section" style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">
            <div class="ai-provider-selector" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <label for="ai-provider" style="font-size: 14px; color: var(--text-secondary); font-weight: 500; white-space: nowrap;">AI Провайдер:</label>
                <select id="ai-provider" style="padding: 10px 16px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; background: white; cursor: pointer; min-width: 220px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <option value="together">Together.ai</option>
                    <option value="alibaba">Alibaba Cloud Qwen 3 Max</option>
                </select>
                <span id="provider-status" style="font-size: 12px; color: var(--text-secondary); padding: 6px 12px; background: rgba(0, 0, 0, 0.05); border-radius: 6px; white-space: nowrap;"></span>
            </div>
        </div>

        <!-- Статистика -->
        <div class="moderation-stats">
            <div class="stat-card">
                <div class="stat-card__value" style="color: #FF9500;"><?php echo $stats['pending']; ?></div>
                <div class="stat-card__label">На модерации</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__value" style="color: #34C759;"><?php echo $stats['approved']; ?></div>
                <div class="stat-card__label">Одобрено</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__value" style="color: #FF3B30;"><?php echo $stats['rejected']; ?></div>
                <div class="stat-card__label">Отклонено</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__value" style="color: #007AFF;"><?php echo $stats['published']; ?></div>
                <div class="stat-card__label">Опубликовано</div>
            </div>
            <div class="stat-card">
                <div class="stat-card__value"><?php echo $stats['total']; ?></div>
                <div class="stat-card__label">Всего</div>
            </div>
        </div>

        <?php if ($teaserId && $currentTeaser): ?>
            <!-- Информация об активе -->
            <div style="margin-bottom: 24px; padding: 16px; background: rgba(0, 0, 0, 0.02); border-radius: 8px;">
                <h2 style="margin: 0 0 8px;"><?php echo htmlspecialchars($currentTeaser['asset_name'] ?? 'Актив', ENT_QUOTES, 'UTF-8'); ?></h2>
                <div style="font-size: 14px; color: var(--text-secondary);">
                    Продавец: <?php echo htmlspecialchars($currentTeaser['seller_name'] ?? 'Не указан', ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
            
            <!-- Редактор тизера -->
            <div class="editor-container">
                <div class="editor-panel">
                    <h3>Редактирование HTML</h3>
                        <form id="moderation-form" onsubmit="return false;">
                        <input type="hidden" id="teaser_id" value="<?php echo $teaserId; ?>">
                        
                        <div style="margin-bottom: 16px;">
                            <label for="card_title" style="display: block; margin-bottom: 8px; font-weight: 600;">Название карточки:</label>
                            <input 
                                type="text" 
                                id="card_title" 
                                name="card_title"
                                value="<?php echo htmlspecialchars($currentTeaser['card_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                placeholder="Например: производитель одежды"
                                style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; box-sizing: border-box;"
                            >
                            <small style="color: var(--text-secondary); font-size: 12px; display: block; margin-top: 4px;">
                                Если не указано, будет использоваться название компании или "Актив"
                            </small>
                        </div>
                        
                        <textarea 
                            name="moderated_html" 
                            class="editor-textarea" 
                            id="teaser-html-editor"
                            required
                        ><?php echo htmlspecialchars($originalHtml ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        
                        <div style="margin-top: 16px;">
                            <label for="moderation_notes" style="display: block; margin-bottom: 8px; font-weight: 600;">Заметки модератора:</label>
                            <textarea 
                                name="moderation_notes" 
                                id="moderation_notes"
                                rows="3"
                                style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px;"
                                placeholder="Введите заметки или комментарии..."
                            ><?php echo htmlspecialchars($currentTeaser['moderation_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        
                        <div class="moderation-checklist">
                            <h4>Чек-лист проверки:</h4>
                            <div class="checklist-item">
                                <input type="checkbox" id="check-blocks" name="checks[]" value="blocks">
                                <label for="check-blocks">Все обязательные блоки присутствуют</label>
                            </div>
                            <div class="checklist-item">
                                <input type="checkbox" id="check-text" name="checks[]" value="text">
                                <label for="check-text">Качество текста (читаемость, отсутствие ошибок)</label>
                            </div>
                            <div class="checklist-item">
                                <input type="checkbox" id="check-financial" name="checks[]" value="financial">
                                <label for="check-financial">Корректность финансовых данных</label>
                            </div>
                            <div class="checklist-item">
                                <input type="checkbox" id="check-contacts" name="checks[]" value="contacts">
                                <label for="check-contacts">Наличие контактной информации</label>
                            </div>
                            <div class="checklist-item">
                                <input type="checkbox" id="check-design" name="checks[]" value="design">
                                <label for="check-design">Соответствие стандартам оформления</label>
                            </div>
                        </div>
                        
                        <div id="moderation-message" style="margin-top: 16px; padding: 12px; border-radius: 8px; display: none;"></div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-primary" onclick="saveTeaserModeration('approved')">
                                Сохранить и одобрить
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="saveTeaserModeration('save')">
                                Сохранить изменения
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="rejectTeaser()">
                                Отклонить
                            </button>
                            <?php if ($currentTeaser['moderation_status'] === 'approved'): ?>
                            <button type="button" class="btn btn-primary" onclick="publishTeaser()" style="background: linear-gradient(135deg, #10B981 0%, #059669 100%);">
                                Опубликовать
                            </button>
                            <?php endif; ?>
                            <?php if ($currentTeaser['moderation_status'] === 'published'): ?>
                            <button type="button" class="btn btn-secondary" onclick="unpublishTeaser()" style="background: linear-gradient(135deg, #FF9500 0%, #FF6B00 100%); color: white;">
                                Снять с публикации
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap; padding: 16px; background: rgba(0, 122, 255, 0.05); border-radius: 8px; border: 1px solid rgba(0, 122, 255, 0.2);">
                            <a href="view_form.php?id=<?php echo $currentTeaser['seller_form_id']; ?>" 
                               class="btn btn-secondary" 
                               target="_blank"
                               style="display: inline-flex; align-items: center; gap: 8px;">
                                📋 Просмотреть анкету
                            </a>
                            <a href="dashboard.php?form_id=<?php echo $currentTeaser['seller_form_id']; ?>" 
                               class="btn btn-secondary" 
                               target="_blank"
                               style="display: inline-flex; align-items: center; gap: 8px;">
                                📊 Просмотреть финансовую модель (DCF)
                            </a>
                        </div>
                    </form>
                </div>
                
                <div class="editor-panel">
                    <h3>Предпросмотр</h3>
                    <div class="preview-container" id="teaser-preview">
                        <?php echo $originalHtml ?? ''; ?>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 24px;">
                <a href="moderation.php" class="btn btn-secondary">← Вернуться к списку</a>
            </div>
        <?php else: ?>
            <!-- Фильтры -->
            <div class="moderation-filters">
                <form method="GET" style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center; flex: 1;">
                    <select name="status" style="flex: 0 0 auto;">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>Все статусы</option>
                        <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>На модерации</option>
                        <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>Одобрено</option>
                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Отклонено</option>
                        <option value="published" <?php echo $statusFilter === 'published' ? 'selected' : ''; ?>>Опубликовано</option>
                    </select>
                    
                    <input 
                        type="text" 
                        name="search" 
                        placeholder="Поиск по названию актива или продавцу..."
                        value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                        style="flex: 1; min-width: 200px;"
                    >
                    
                    <button type="submit" class="btn btn-primary">Применить фильтры</button>
                    <a href="moderation.php" class="btn btn-secondary">Сбросить</a>
                </form>
            </div>

            <!-- Список тизеров -->
            <div class="teasers-list">
                <?php if (empty($teasers)): ?>
                    <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                        <p>Тизеры не найдены</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($teasers as $teaser): ?>
                        <div class="teaser-item">
                            <div class="teaser-info">
                                <h3><?php echo htmlspecialchars($teaser['asset_name'] ?: 'Без названия', ENT_QUOTES, 'UTF-8'); ?></h3>
                                <p><strong>Продавец:</strong> <?php echo htmlspecialchars($teaser['seller_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($teaser['seller_email'], ENT_QUOTES, 'UTF-8'); ?>)</p>
                                <p><strong>Статус анкеты:</strong> <?php echo htmlspecialchars($teaser['form_status'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><strong>Создан:</strong> <?php echo date('d.m.Y H:i', strtotime($teaser['created_at'])); ?></p>
                                <?php if ($teaser['moderated_at']): ?>
                                    <p><strong>Отмодерирован:</strong> <?php echo date('d.m.Y H:i', strtotime($teaser['moderated_at'])); ?></p>
                                <?php endif; ?>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                <span class="status-badge" style="background: <?php echo $statusColors[$teaser['moderation_status']] ?? '#86868B'; ?>; color: white; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    <?php echo $statusLabels[$teaser['moderation_status']] ?? $teaser['moderation_status']; ?>
                                </span>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <a href="moderation.php?id=<?php echo $teaser['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 14px;">
                                        <?php echo $teaser['moderation_status'] === 'pending' ? 'Модерировать' : 'Редактировать'; ?>
                                    </a>
                                    <?php if ($teaser['moderation_status'] === 'published'): ?>
                                    <button 
                                        type="button" 
                                        class="btn btn-secondary" 
                                        onclick="quickUnpublish(<?php echo $teaser['id']; ?>, '<?php echo htmlspecialchars($teaser['asset_name'] ?: 'Тизер', ENT_QUOTES, 'UTF-8'); ?>')"
                                        style="padding: 8px 16px; font-size: 14px; background: linear-gradient(135deg, #FF9500 0%, #FF6B00 100%); color: white; border: none;"
                                        title="Снять с публикации"
                                    >
                                        Снять с публикации
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
        <!-- Конец контента модерации тизеров -->
        
        <!-- Контент управления блогом -->
        <div id="blog-tab" class="moderation-tab-content">
            <div style="background: white; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
                    <h2 style="margin: 0; font-size: 24px; font-weight: 700;">Статьи блога</h2>
                    <button id="create-blog-post-btn" style="padding: 12px 24px; background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s; white-space: nowrap;">
                        + Создать статью
                    </button>
                </div>

                <!-- Фильтры -->
                <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                    <select id="blog-status-filter" style="padding: 10px 16px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; min-width: 150px;">
                        <option value="all">Все статусы</option>
                        <option value="draft">Черновики</option>
                        <option value="published">Опубликовано</option>
                        <option value="archived">Архив</option>
                    </select>
                    <input type="text" id="blog-search" placeholder="Поиск по заголовку..." style="flex: 1; min-width: 200px; padding: 10px 16px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px;">
                </div>

                <!-- Список статей -->
                <div id="blog-posts-list" style="min-height: 200px;">
                    <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        Загрузка статей...
                    </div>
                </div>
            </div>

            <!-- Модальное окно для создания/редактирования статьи -->
            <div id="blog-post-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(4px); z-index: 10000; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
                <div style="background: white; border-radius: 20px; max-width: 900px; width: 100%; max-height: 90vh; overflow-y: auto; position: relative; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);">
                    <div style="position: sticky; top: 0; background: white; padding: 24px; border-bottom: 1px solid rgba(0, 0, 0, 0.1); display: flex; justify-content: space-between; align-items: center; z-index: 1;">
                        <h3 id="blog-modal-title" style="margin: 0; font-size: 24px; font-weight: 700;">Создать статью</h3>
                        <button id="close-blog-modal" style="background: none; border: none; font-size: 28px; cursor: pointer; color: var(--text-secondary); padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; line-height: 1;">&times;</button>
                    </div>
                    <form id="blog-post-form" style="padding: 24px;">
                        <input type="hidden" id="blog-post-id" value="">
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Заголовок *</label>
                            <input type="text" id="blog-post-title" required style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 16px; box-sizing: border-box;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Краткое описание (excerpt)</label>
                            <textarea id="blog-post-excerpt" rows="3" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; resize: vertical; box-sizing: border-box; font-family: inherit;"></textarea>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Содержание (HTML) *</label>
                            <textarea id="blog-post-content" required rows="15" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; font-family: 'Courier New', monospace; resize: vertical; box-sizing: border-box;"></textarea>
                            <small style="color: var(--text-secondary); font-size: 12px; display: block; margin-top: 4px;">Можно использовать HTML теги для форматирования</small>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Категория</label>
                                <input type="text" id="blog-post-category" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600;">Теги (через запятую)</label>
                                <input type="text" id="blog-post-tags" placeholder="тег1, тег2, тег3" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">SEO заголовок</label>
                            <input type="text" id="blog-post-meta-title" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">SEO описание</label>
                            <textarea id="blog-post-meta-description" rows="2" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; resize: vertical; box-sizing: border-box; font-family: inherit;"></textarea>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Ключевые слова</label>
                            <input type="text" id="blog-post-keywords" placeholder="ключевое слово1, ключевое слово2" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                        </div>

                        <div style="margin-bottom: 24px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Статус</label>
                            <select id="blog-post-status" style="width: 100%; padding: 12px; border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-size: 14px; box-sizing: border-box;">
                                <option value="draft">Черновик</option>
                                <option value="published">Опубликовано</option>
                                <option value="archived">Архив</option>
                            </select>
                        </div>

                        <div id="blog-post-message" style="margin-bottom: 16px; padding: 12px; border-radius: 8px; display: none;"></div>

                        <div style="display: flex; gap: 12px; justify-content: flex-end;">
                            <button type="button" id="cancel-blog-post" style="padding: 12px 24px; background: white; color: var(--text-primary); border: 2px solid rgba(0, 0, 0, 0.1); border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                                Отмена
                            </button>
                            <button type="submit" style="padding: 12px 24px; background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s;">
                                Сохранить
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- Конец контента управления блогом -->
    </div>

    <script>
        // Обновление предпросмотра при редактировании
        const editor = document.getElementById('teaser-html-editor');
        const preview = document.getElementById('teaser-preview');
        
        if (editor && preview) {
            let chartInitTimeout;
            editor.addEventListener('input', function() {
                preview.innerHTML = this.value;
                
                // Переинициализируем графики после обновления HTML
                // Используем debounce, чтобы не вызывать слишком часто
                clearTimeout(chartInitTimeout);
                chartInitTimeout = setTimeout(function() {
                    if (typeof initTeaserCharts === 'function') {
                        initTeaserCharts();
                    }
                }, 500);
            });
        }
        
        async function saveTeaserModeration(statusAction) {
            const teaserId = document.getElementById('teaser_id').value;
            const moderatedHtml = document.getElementById('teaser-html-editor').value;
            const moderationNotes = document.getElementById('moderation_notes').value;
            const cardTitle = document.getElementById('card_title')?.value || '';
            const messageDiv = document.getElementById('moderation-message');
            
            if (!teaserId) {
                alert('Ошибка: не указан ID тизера.');
                return;
            }
            
            if (statusAction === 'rejected' && !moderationNotes.trim()) {
                alert('При отклонении необходимо указать причину.');
                return;
            }
            
            // Показываем сообщение о загрузке
            messageDiv.style.display = 'block';
            messageDiv.style.background = 'rgba(59, 130, 246, 0.1)';
            messageDiv.style.border = '1px solid rgba(59, 130, 246, 0.3)';
            messageDiv.style.color = '#1e40af';
            messageDiv.textContent = 'Сохранение...';
            
            try {
                const formData = new FormData();
                formData.append('teaser_id', teaserId);
                formData.append('moderated_html', moderatedHtml);
                formData.append('moderation_notes', moderationNotes);
                formData.append('card_title', cardTitle);
                formData.append('status_action', statusAction);
                
                const response = await fetch('moderation_api.php?action=save', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    messageDiv.style.background = 'rgba(16, 185, 129, 0.1)';
                    messageDiv.style.border = '1px solid rgba(16, 185, 129, 0.3)';
                    messageDiv.style.color = '#059669';
                    messageDiv.textContent = '✓ ' + result.message;
                    
                    // Обновляем страницу через 2 секунды
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(result.message || 'Ошибка при сохранении');
                }
            } catch (error) {
                console.error('Error saving moderation:', error);
                messageDiv.style.background = 'rgba(239, 68, 68, 0.1)';
                messageDiv.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                messageDiv.style.color = '#dc2626';
                messageDiv.textContent = '✗ Ошибка: ' + error.message;
            }
        }
        
        function rejectTeaser() {
            const notes = prompt('Укажите причину отклонения:');
            if (notes !== null && notes.trim()) {
                const notesField = document.getElementById('moderation_notes');
                notesField.value = notes;
                saveTeaserModeration('rejected');
            }
        }
        
        async function publishTeaser() {
            if (!confirm('Опубликовать тизер на главной странице?')) {
                return;
            }
            
            const teaserId = document.getElementById('teaser_id').value;
            const messageDiv = document.getElementById('moderation-message');
            
            if (!teaserId) {
                alert('Ошибка: не указан ID тизера.');
                return;
            }
            
            messageDiv.style.display = 'block';
            messageDiv.style.background = 'rgba(59, 130, 246, 0.1)';
            messageDiv.style.border = '1px solid rgba(59, 130, 246, 0.3)';
            messageDiv.style.color = '#1e40af';
            messageDiv.textContent = 'Публикация...';
            
            try {
                const formData = new FormData();
                formData.append('teaser_id', teaserId);
                
                const response = await fetch('moderation_api.php?action=publish', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    messageDiv.style.background = 'rgba(16, 185, 129, 0.1)';
                    messageDiv.style.border = '1px solid rgba(16, 185, 129, 0.3)';
                    messageDiv.style.color = '#059669';
                    messageDiv.textContent = '✓ ' + result.message;
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    throw new Error(result.message || 'Ошибка при публикации');
                }
            } catch (error) {
                console.error('Error publishing teaser:', error);
                messageDiv.style.background = 'rgba(239, 68, 68, 0.1)';
                messageDiv.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                messageDiv.style.color = '#dc2626';
                messageDiv.textContent = '✗ Ошибка: ' + error.message;
            }
        }
        
        /**
         * Быстрое снятие тизера с публикации из списка
         * 
         * @param {number} teaserId ID тизера
         * @param {string} assetName Название актива (для подтверждения)
         */
        async function quickUnpublish(teaserId, assetName) {
            if (!confirm(`Снять тизер "${assetName}" с публикации? Карточка будет удалена с главной страницы, тизер переведен в статус "На модерации".`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('teaser_id', teaserId);
                
                const response = await fetch('moderation_api.php?action=unpublish', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✓ ' + result.message);
                    window.location.reload();
                } else {
                    throw new Error(result.message || 'Ошибка при снятии с публикации');
                }
            } catch (error) {
                console.error('Error unpublishing teaser:', error);
                alert('✗ Ошибка: ' + error.message);
            }
        }
        
        // Инициализация мобильного меню
        function initMobileMenu() {
            const navToggle = document.querySelector('.nav-toggle');
            const navMenu = document.querySelector('.nav-menu');
            
            if (navToggle && navMenu) {
                // Удаляем старые обработчики, если они есть
                const newToggle = navToggle.cloneNode(true);
                navToggle.parentNode.replaceChild(newToggle, navToggle);
                
                newToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    navMenu.classList.toggle('active');
                    newToggle.classList.toggle('active');
                });
                
                // Закрытие меню при клике на ссылку
                const navLinks = navMenu.querySelectorAll('a');
                navLinks.forEach(link => {
                    link.addEventListener('click', function() {
                        navMenu.classList.remove('active');
                        newToggle.classList.remove('active');
                    });
                });
                
                // Закрытие меню при клике вне его (но не при клике на элементы внутри меню)
                document.addEventListener('click', function(e) {
                    if (navMenu.classList.contains('active') && 
                        !navMenu.contains(e.target) && 
                        !newToggle.contains(e.target)) {
                        navMenu.classList.remove('active');
                        newToggle.classList.remove('active');
                    }
                });
            }
        }
        
        // Инициализируем при загрузке DOM
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initMobileMenu);
        } else {
            initMobileMenu();
        }
    </script>
    
    <script>
        // Управление выбором AI провайдера
        (function() {
            const providerSelect = document.getElementById('ai-provider');
            const providerStatus = document.getElementById('provider-status');
            
            if (!providerSelect || !providerStatus) return;
            
            // Функция для сохранения выбора в localStorage
            function saveProviderToLocalStorage(provider) {
                try {
                    localStorage.setItem('ai_provider', provider);
                    localStorage.setItem('ai_provider_timestamp', Date.now().toString());
                } catch (e) {
                    console.warn('Не удалось сохранить выбор провайдера в localStorage:', e);
                }
            }
            
            // Функция для получения выбора из localStorage
            function getProviderFromLocalStorage() {
                try {
                    const saved = localStorage.getItem('ai_provider');
                    const timestamp = localStorage.getItem('ai_provider_timestamp');
                    // Проверяем, что выбор не старше 30 дней
                    if (saved && timestamp && (Date.now() - parseInt(timestamp)) < 30 * 24 * 60 * 60 * 1000) {
                        if (['together', 'alibaba'].includes(saved)) {
                            return saved;
                        }
                    }
                } catch (e) {
                    console.warn('Не удалось прочитать выбор провайдера из localStorage:', e);
                }
                return null;
            }
            
            // Получаем текущий провайдер: сначала из PHP (сессия), затем из localStorage
            let currentProvider = '<?php echo getCurrentAIProvider(); ?>';
            const savedProvider = getProviderFromLocalStorage();
            
            // Если в сессии нет выбора, но есть в localStorage, синхронизируем с сервером
            if (currentProvider === 'together' && savedProvider && savedProvider !== 'together') {
                // Восстанавливаем выбор из localStorage
                currentProvider = savedProvider;
                providerSelect.value = currentProvider;
                updateProviderStatus(currentProvider);
                
                // Синхронизируем с сервером в фоне
                fetch('set_ai_provider.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        provider: currentProvider
                    })
                }).catch(err => console.warn('Не удалось синхронизировать выбор провайдера:', err));
            } else if (currentProvider) {
                providerSelect.value = currentProvider;
                updateProviderStatus(currentProvider);
                // Сохраняем в localStorage для резервной копии
                saveProviderToLocalStorage(currentProvider);
            } else if (savedProvider) {
                // Если нет в сессии, используем сохраненный выбор
                currentProvider = savedProvider;
                providerSelect.value = currentProvider;
                updateProviderStatus(currentProvider);
            }
            
            // Обработчик изменения провайдера
            providerSelect.addEventListener('change', function() {
                const selectedProvider = this.value;
                
                // Сразу сохраняем в localStorage
                saveProviderToLocalStorage(selectedProvider);
                
                // Показываем индикатор загрузки
                providerStatus.textContent = 'Сохранение...';
                providerStatus.style.color = '#007AFF';
                
                // Отправляем запрос на сервер
                fetch('set_ai_provider.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        provider: selectedProvider
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateProviderStatus(selectedProvider);
                        providerStatus.textContent = '✓ Сохранено';
                        providerStatus.style.color = '#34C759';
                        
                        // Через 2 секунды убираем сообщение
                        setTimeout(() => {
                            updateProviderStatus(selectedProvider);
                        }, 2000);
                    } else {
                        providerStatus.textContent = '✗ Ошибка';
                        providerStatus.style.color = '#FF3B30';
                        alert('Ошибка при сохранении: ' + (data.message || 'Неизвестная ошибка'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    providerStatus.textContent = '✗ Ошибка';
                    providerStatus.style.color = '#FF3B30';
                    alert('Ошибка при сохранении провайдера');
                });
            });
            
            function updateProviderStatus(provider) {
                const providerNames = {
                    'together': 'Together.ai',
                    'alibaba': 'Alibaba Cloud Qwen 3 Max'
                };
                providerStatus.textContent = providerNames[provider] || provider;
                providerStatus.style.color = 'var(--text-secondary)';
            }
        })();
        
        // Управление табами
        (function() {
            document.querySelectorAll('.moderation-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;
                    
                    // Обновляем активные табы
                    document.querySelectorAll('.moderation-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Показываем нужный контент
                    document.querySelectorAll('.moderation-tab-content').forEach(c => c.classList.remove('active'));
                    const targetContent = document.getElementById(targetTab + '-tab');
                    if (targetContent) {
                        targetContent.classList.add('active');
                    }
                    
                    // Загружаем статьи, если переключились на блог
                    if (targetTab === 'blog') {
                        loadBlogPosts();
                    }
                });
            });
        })();
        
        // Управление блогом
        let blogPosts = [];
        
        function loadBlogPosts() {
            const status = document.getElementById('blog-status-filter')?.value || 'all';
            const search = document.getElementById('blog-search')?.value || '';
            
            fetch(`blog_api.php?action=list&status=${status}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        blogPosts = data.posts || [];
                        
                        // Фильтрация по поиску
                        if (search) {
                            blogPosts = blogPosts.filter(post => 
                                post.title.toLowerCase().includes(search.toLowerCase())
                            );
                        }
                        
                        renderBlogPosts();
                    } else {
                        const list = document.getElementById('blog-posts-list');
                        if (list) {
                            list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-secondary);">Ошибка загрузки статей: ' + (data.message || 'Неизвестная ошибка') + '</div>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading blog posts:', error);
                    const list = document.getElementById('blog-posts-list');
                    if (list) {
                        list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--accent-color);">Ошибка загрузки статей</div>';
                    }
                });
        }
        
        function renderBlogPosts() {
            const list = document.getElementById('blog-posts-list');
            if (!list) return;
            
            if (blogPosts.length === 0) {
                list.innerHTML = '<div style="text-align: center; padding: 40px; color: var(--text-secondary);">Статей не найдено</div>';
                return;
            }
            
            list.innerHTML = blogPosts.map(post => `
                <div style="padding: 20px; border-bottom: 1px solid rgba(0, 0, 0, 0.08); display: flex; justify-content: space-between; align-items: center; transition: background 0.2s;" onmouseover="this.style.background='rgba(0,0,0,0.02)'" onmouseout="this.style.background=''">
                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 8px; font-size: 18px; font-weight: 700; color: var(--text-primary);">${escapeHtml(post.title)}</h3>
                        ${post.excerpt ? `<p style="margin: 0 0 4px; color: var(--text-secondary); font-size: 14px; line-height: 1.5;">${escapeHtml(post.excerpt)}</p>` : ''}
                        <div style="display: flex; gap: 16px; margin-top: 8px; font-size: 12px; color: var(--text-secondary); flex-wrap: wrap;">
                            <span>Статус: <strong style="color: ${getStatusColor(post.status)}">${getStatusLabel(post.status)}</strong></span>
                            ${post.category ? `<span>Категория: ${escapeHtml(post.category)}</span>` : ''}
                            <span>Создано: ${formatDate(post.created_at)}</span>
                            ${post.published_at ? `<span>Опубликовано: ${formatDate(post.published_at)}</span>` : ''}
                            ${post.views > 0 ? `<span>Просмотров: ${post.views}</span>` : ''}
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px; flex-shrink: 0;">
                        <button onclick="editBlogPost(${post.id})" style="padding: 8px 16px; background: var(--primary-color); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform=''">
                            Редактировать
                        </button>
                        <button onclick="deleteBlogPost(${post.id})" style="padding: 8px 16px; background: var(--accent-color); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform=''">
                            Удалить
                        </button>
                    </div>
                </div>
            `).join('');
        }
        
        function getStatusLabel(status) {
            const labels = {
                'draft': 'Черновик',
                'published': 'Опубликовано',
                'archived': 'Архив'
            };
            return labels[status] || status;
        }
        
        function getStatusColor(status) {
            const colors = {
                'draft': '#FF9500',
                'published': '#34C759',
                'archived': '#86868B'
            };
            return colors[status] || '#86868B';
        }
        
        function formatDate(dateString) {
            if (!dateString) return '—';
            const date = new Date(dateString);
            return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Создание статьи
        const createBlogPostBtn = document.getElementById('create-blog-post-btn');
        if (createBlogPostBtn) {
            createBlogPostBtn.addEventListener('click', function() {
                document.getElementById('blog-modal-title').textContent = 'Создать статью';
                const form = document.getElementById('blog-post-form');
                if (form) form.reset();
                document.getElementById('blog-post-id').value = '';
                document.getElementById('blog-post-status').value = 'draft';
                document.getElementById('blog-post-message').style.display = 'none';
                document.getElementById('blog-post-modal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            });
        }
        
        // Закрытие модального окна
        const closeBlogModalBtn = document.getElementById('close-blog-modal');
        const cancelBlogPostBtn = document.getElementById('cancel-blog-post');
        
        function closeBlogModal() {
            document.getElementById('blog-post-modal').style.display = 'none';
            document.body.style.overflow = '';
        }
        
        if (closeBlogModalBtn) {
            closeBlogModalBtn.addEventListener('click', closeBlogModal);
        }
        if (cancelBlogPostBtn) {
            cancelBlogPostBtn.addEventListener('click', closeBlogModal);
        }
        
        // Закрытие при клике на overlay
        const blogModal = document.getElementById('blog-post-modal');
        if (blogModal) {
            blogModal.addEventListener('click', function(e) {
                if (e.target === blogModal) {
                    closeBlogModal();
                }
            });
        }
        
        // Редактирование статьи
        window.editBlogPost = function(id) {
            // Показываем модальное окно с индикатором загрузки
            document.getElementById('blog-modal-title').textContent = 'Редактировать статью';
            document.getElementById('blog-post-id').value = id;
            document.getElementById('blog-post-message').style.display = 'block';
            document.getElementById('blog-post-message').style.background = 'rgba(59, 130, 246, 0.1)';
            document.getElementById('blog-post-message').style.border = '1px solid rgba(59, 130, 246, 0.3)';
            document.getElementById('blog-post-message').style.color = '#1e40af';
            document.getElementById('blog-post-message').textContent = 'Загрузка статьи...';
            document.getElementById('blog-post-modal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Загружаем полную статью через API
            fetch(`blog_api.php?action=get&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.post) {
                        const post = data.post;
                        document.getElementById('blog-post-title').value = post.title || '';
                        document.getElementById('blog-post-excerpt').value = post.excerpt || '';
                        document.getElementById('blog-post-content').value = post.content || '';
                        document.getElementById('blog-post-category').value = post.category || '';
                        document.getElementById('blog-post-tags').value = post.tags || '';
                        document.getElementById('blog-post-meta-title').value = post.meta_title || '';
                        document.getElementById('blog-post-meta-description').value = post.meta_description || '';
                        document.getElementById('blog-post-keywords').value = post.keywords || '';
                        document.getElementById('blog-post-status').value = post.status || 'draft';
                        document.getElementById('blog-post-message').style.display = 'none';
                    } else {
                        document.getElementById('blog-post-message').style.background = '#f8d7da';
                        document.getElementById('blog-post-message').style.border = '1px solid #f5c6cb';
                        document.getElementById('blog-post-message').style.color = '#721c24';
                        document.getElementById('blog-post-message').textContent = '✗ Ошибка загрузки статьи: ' + (data.message || 'Неизвестная ошибка');
                    }
                })
                .catch(error => {
                    console.error('Error loading post:', error);
                    document.getElementById('blog-post-message').style.background = '#f8d7da';
                    document.getElementById('blog-post-message').style.border = '1px solid #f5c6cb';
                    document.getElementById('blog-post-message').style.color = '#721c24';
                    document.getElementById('blog-post-message').textContent = '✗ Ошибка загрузки статьи';
                });
        };
        
        // Удаление статьи
        window.deleteBlogPost = function(id) {
            if (!confirm('Вы уверены, что хотите удалить эту статью?')) return;
            
            fetch(`blog_api.php?action=delete&id=${id}`, { method: 'DELETE' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadBlogPosts();
                    } else {
                        alert('Ошибка удаления: ' + (data.message || 'Неизвестная ошибка'));
                    }
                })
                .catch(error => {
                    console.error('Error deleting post:', error);
                    alert('Ошибка удаления статьи');
                });
        };
        
        // Сохранение статьи
        const blogPostForm = document.getElementById('blog-post-form');
        if (blogPostForm) {
            blogPostForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const postId = document.getElementById('blog-post-id').value;
                const isEdit = !!postId;
                
                const data = {
                    title: document.getElementById('blog-post-title').value,
                    excerpt: document.getElementById('blog-post-excerpt').value,
                    content: document.getElementById('blog-post-content').value,
                    category: document.getElementById('blog-post-category').value,
                    tags: document.getElementById('blog-post-tags').value,
                    meta_title: document.getElementById('blog-post-meta-title').value,
                    meta_description: document.getElementById('blog-post-meta-description').value,
                    keywords: document.getElementById('blog-post-keywords').value,
                    status: document.getElementById('blog-post-status').value
                };
                
                const url = isEdit 
                    ? `blog_api.php?action=update&id=${postId}`
                    : 'blog_api.php?action=create';
                const method = isEdit ? 'PUT' : 'POST';
                
                const messageDiv = document.getElementById('blog-post-message');
                messageDiv.style.display = 'block';
                messageDiv.style.background = 'rgba(59, 130, 246, 0.1)';
                messageDiv.style.border = '1px solid rgba(59, 130, 246, 0.3)';
                messageDiv.style.color = '#1e40af';
                messageDiv.textContent = 'Сохранение...';
                
                fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        messageDiv.style.background = '#d4edda';
                        messageDiv.style.border = '1px solid #c3e6cb';
                        messageDiv.style.color = '#155724';
                        messageDiv.textContent = '✓ ' + (data.message || 'Статья сохранена');
                        setTimeout(() => {
                            closeBlogModal();
                            loadBlogPosts();
                        }, 1000);
                    } else {
                        messageDiv.style.background = '#f8d7da';
                        messageDiv.style.border = '1px solid #f5c6cb';
                        messageDiv.style.color = '#721c24';
                        messageDiv.textContent = '✗ ' + (data.message || 'Ошибка сохранения');
                    }
                })
                .catch(error => {
                    console.error('Error saving post:', error);
                    messageDiv.style.background = '#f8d7da';
                    messageDiv.style.border = '1px solid #f5c6cb';
                    messageDiv.style.color = '#721c24';
                    messageDiv.textContent = '✗ Ошибка сохранения статьи';
                });
            });
        }
        
        // Фильтры и поиск
        const blogStatusFilter = document.getElementById('blog-status-filter');
        const blogSearch = document.getElementById('blog-search');
        
        if (blogStatusFilter) {
            blogStatusFilter.addEventListener('change', loadBlogPosts);
        }
        
        if (blogSearch) {
            let searchTimeout;
            blogSearch.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    loadBlogPosts();
                }, 500);
            });
        }
    </script>
    
    <script src="script.js?v=<?php echo time(); ?>"></script>
    <script>
        // Инициализация графиков в предпросмотре тизера
        document.addEventListener('DOMContentLoaded', function() {
            // Проверяем, есть ли контейнер предпросмотра с тизером
            const previewContainer = document.getElementById('teaser-preview');
            if (previewContainer) {
                // Инициализируем графики после небольшой задержки для рендеринга DOM
                setTimeout(function() {
                    if (typeof initTeaserCharts === 'function') {
                        initTeaserCharts();
                    }
                }, 300);
            }
        });
    </script>
</body>
</html>

