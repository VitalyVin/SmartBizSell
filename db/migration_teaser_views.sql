-- Миграция: добавление поля views для счётчика просмотров карточек
-- Дата создания: 2026-02-XX
-- Описание: Добавляет колонку views в таблицу published_teasers для отслеживания количества просмотров карточки (открытий модального окна)

ALTER TABLE published_teasers 
ADD COLUMN views INT UNSIGNED NOT NULL DEFAULT 0 
COMMENT 'Количество просмотров карточки (открытий модального окна)' 
AFTER card_title;
