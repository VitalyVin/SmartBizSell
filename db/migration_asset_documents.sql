-- Миграция: Создание таблицы для хранения документов активов
-- Дата создания: 2025-01-XX
-- Описание: Таблица для хранения информации о загруженных документах, привязанных к активам (seller_forms)

CREATE TABLE IF NOT EXISTS asset_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_form_id INT NOT NULL COMMENT 'ID анкеты продавца (актива)',
    user_id INT NOT NULL COMMENT 'ID пользователя, загрузившего документ (для проверки прав доступа)',
    file_name VARCHAR(255) NOT NULL COMMENT 'Оригинальное имя файла',
    file_path VARCHAR(500) NOT NULL COMMENT 'Путь к файлу на сервере',
    file_size INT NOT NULL COMMENT 'Размер файла в байтах',
    file_type VARCHAR(100) NOT NULL COMMENT 'MIME тип файла',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время загрузки',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата и время последнего обновления',
    
    -- Внешние ключи
    FOREIGN KEY (seller_form_id) REFERENCES seller_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Индексы для быстрого поиска
    INDEX idx_seller_form_id (seller_form_id),
    INDEX idx_user_id (user_id),
    INDEX idx_uploaded_at (uploaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Документы, привязанные к активам на продажу';

