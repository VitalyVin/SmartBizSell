-- Миграция для создания таблицы токенов восстановления пароля
-- Таблица хранит токены для безопасного сброса паролей пользователей

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL COMMENT 'Хеш токена (SHA-256)',
    expires_at TIMESTAMP NOT NULL COMMENT 'Время истечения токена',
    used_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Время использования токена (NULL если не использован)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used_at (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Автоматическая очистка истекших токенов (можно настроить через cron)
-- DELETE FROM password_reset_tokens WHERE expires_at < NOW() OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY));

