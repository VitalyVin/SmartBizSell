-- Миграция для создания таблицы блога
-- Таблица для хранения статей блога

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT 'Заголовок статьи',
  `slug` varchar(255) NOT NULL COMMENT 'URL-friendly версия заголовка',
  `content` text NOT NULL COMMENT 'Полный текст статьи',
  `excerpt` text DEFAULT NULL COMMENT 'Краткое описание статьи',
  `author_id` int(11) DEFAULT NULL COMMENT 'ID автора (связь с users)',
  `category` varchar(100) DEFAULT NULL COMMENT 'Категория статьи',
  `tags` text DEFAULT NULL COMMENT 'Теги через запятую',
  `meta_title` varchar(255) DEFAULT NULL COMMENT 'SEO заголовок',
  `meta_description` text DEFAULT NULL COMMENT 'SEO описание',
  `keywords` text DEFAULT NULL COMMENT 'Ключевые слова',
  `views` int(11) DEFAULT 0 COMMENT 'Количество просмотров',
  `published_at` datetime DEFAULT NULL COMMENT 'Дата публикации',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата обновления',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
  `status` enum('draft','published','archived') DEFAULT 'draft' COMMENT 'Статус статьи',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `author_id` (`author_id`),
  KEY `published_at` (`published_at`),
  KEY `status` (`status`),
  KEY `category` (`category`),
  CONSTRAINT `blog_posts_ibfk_1` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Статьи блога';

-- Создание индексов для оптимизации поиска
CREATE INDEX idx_blog_posts_published ON blog_posts(published_at, status);
CREATE INDEX idx_blog_posts_category ON blog_posts(category, status);
CREATE FULLTEXT INDEX idx_blog_posts_content ON blog_posts(title, content, excerpt);

