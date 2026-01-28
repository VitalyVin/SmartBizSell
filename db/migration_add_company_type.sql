-- Миграция: добавление поля company_type для разделения стартапов и зрелых компаний
-- Дата создания: 2026-01-20

-- 1. Добавление поля типа компании (если еще не существует)
-- Примечание: Колонка может быть уже добавлена через ensureSellerFormSchema()
-- Проверяем существование колонки перед добавлением
SET @db_name = DATABASE();
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'seller_forms'
      AND COLUMN_NAME = 'company_type'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE seller_forms 
     ADD COLUMN company_type ENUM(''startup'', ''mature'') DEFAULT NULL 
     COMMENT ''Тип компании: startup - стартап/начинающая, mature - зрелая компания''
     AFTER user_id',
    'SELECT ''Column company_type already exists, skipping ALTER TABLE'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Для существующих анкет: если есть данные за 2022-2024 в financial_results, 
-- считаем зрелой компанией, иначе NULL (определится при следующем редактировании)
-- Проверяем наличие ключей "2022_fact", "2023_fact", "2024_fact" в JSON через поиск строк
UPDATE seller_forms 
SET company_type = 'mature' 
WHERE financial_results IS NOT NULL 
  AND CAST(financial_results AS CHAR) LIKE '%"2022_fact"%'
  AND CAST(financial_results AS CHAR) LIKE '%"2023_fact"%'
  AND CAST(financial_results AS CHAR) LIKE '%"2024_fact"%';
