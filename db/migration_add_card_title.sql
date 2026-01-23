-- Миграция: добавление поля card_title в таблицу published_teasers
-- Дата создания: 2025-01-XX
-- Описание: Позволяет модератору задавать кастомное название карточки на главной странице

ALTER TABLE published_teasers 
ADD COLUMN card_title VARCHAR(255) DEFAULT NULL 
COMMENT 'Кастомное название карточки, заданное модератором. Если задано, используется вместо asset_name или "Актив"';
