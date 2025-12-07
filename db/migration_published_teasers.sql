-- Миграция: создание таблицы для модерации и публикации тизеров
-- Дата создания: 2025-01-XX

CREATE TABLE IF NOT EXISTS published_teasers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_form_id INT NOT NULL,
    moderated_html TEXT DEFAULT NULL COMMENT 'Отредактированная версия тизера',
    moderation_status ENUM('pending', 'approved', 'rejected', 'published') DEFAULT 'pending',
    moderator_id INT DEFAULT NULL COMMENT 'ID модератора из таблицы users',
    moderation_notes TEXT DEFAULT NULL COMMENT 'Заметки модератора',
    moderated_at TIMESTAMP NULL DEFAULT NULL,
    published_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (seller_form_id) REFERENCES seller_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (moderator_id) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_seller_form_id (seller_form_id),
    INDEX idx_moderation_status (moderation_status),
    INDEX idx_moderator_id (moderator_id),
    INDEX idx_published_at (published_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

