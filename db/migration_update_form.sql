-- Миграция для обновления структуры таблицы seller_forms
-- Выполните этот скрипт, если база данных уже создана
-- ВНИМАНИЕ: Проверьте наличие полей перед выполнением команд

USE u3064951_SmartBizSell;

-- Добавление нового поля asset_disclosure (если еще не существует)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'asset_disclosure');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE seller_forms ADD COLUMN asset_disclosure ENUM(''yes'', ''no'') DEFAULT NULL AFTER deal_purpose',
    'SELECT ''Column asset_disclosure already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Переименование полей "Собственная розница" в "Офлайн-продажи"
-- Выполняйте только если поля еще не переименованы
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'own_retail_presence');
SET @sql = IF(@col_exists > 0, 
    'ALTER TABLE `seller_forms`
        CHANGE COLUMN `own_retail_presence`  `offline_sales_presence`  ENUM(''yes'',''no'') DEFAULT NULL,
        CHANGE COLUMN `own_retail_points`    `offline_sales_points`    INT                     DEFAULT NULL,
        CHANGE COLUMN `own_retail_regions`   `offline_sales_regions`   VARCHAR(255)            DEFAULT NULL,
        CHANGE COLUMN `own_retail_area`      `offline_sales_area`      VARCHAR(255)            DEFAULT NULL;',
    'SELECT ''Columns already renamed'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавление новых полей для офлайн-продаж
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'offline_sales_third_party');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE seller_forms ADD COLUMN offline_sales_third_party ENUM(''yes'', ''no'') DEFAULT NULL AFTER offline_sales_area',
    'SELECT ''Column offline_sales_third_party already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'offline_sales_distributors');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE seller_forms ADD COLUMN offline_sales_distributors ENUM(''yes'', ''no'') DEFAULT NULL AFTER offline_sales_third_party',
    'SELECT ''Column offline_sales_distributors already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Удаление старых полей балансовых показателей (если они существуют)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'debt_obligations');
SET @sql = IF(@col_exists > 0, 
    'ALTER TABLE seller_forms DROP COLUMN debt_obligations',
    'SELECT ''Column debt_obligations does not exist'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'cash_balance');
SET @sql = IF(@col_exists > 0, 
    'ALTER TABLE seller_forms DROP COLUMN cash_balance',
    'SELECT ''Column cash_balance does not exist'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'net_assets');
SET @sql = IF(@col_exists > 0, 
    'ALTER TABLE seller_forms DROP COLUMN net_assets',
    'SELECT ''Column net_assets does not exist'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Переименование financial_indicators в financial_results
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'financial_indicators');
SET @sql = IF(@col_exists > 0, 
    'ALTER TABLE seller_forms CHANGE COLUMN financial_indicators financial_results JSON DEFAULT NULL',
    'SELECT ''Column financial_indicators does not exist'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавление поля financial_results_vat
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'financial_results_vat');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE seller_forms ADD COLUMN financial_results_vat ENUM(''with_vat'', ''without_vat'') DEFAULT NULL AFTER production_volumes',
    'SELECT ''Column financial_results_vat already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавление нового поля balance_indicators
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'balance_indicators');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE seller_forms ADD COLUMN balance_indicators JSON DEFAULT NULL AFTER financial_results',
    'SELECT ''Column balance_indicators already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавление поля data_json для сохранения черновиков
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'u3064951_SmartBizSell' 
    AND TABLE_NAME = 'seller_forms' 
    AND COLUMN_NAME = 'data_json');
SET @sql = IF(@col_exists = 0, 
    'ALTER TABLE seller_forms ADD COLUMN data_json JSON DEFAULT NULL AFTER submitted_at',
    'SELECT ''Column data_json already exists'' AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

