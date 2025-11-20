-- SmartBizSell Database Installation Script
-- MySQL 8.0 / Percona Server 8.0
-- Для reg.ru хостинга
-- 
-- ИНСТРУКЦИЯ:
-- 1. Откройте phpMyAdmin
-- 2. Перейдите на вкладку "SQL"
-- 3. Скопируйте и вставьте весь этот файл
-- 4. Нажмите "Вперед" (Execute)
--
-- ВАЖНО: Если база данных уже существует, удалите строку CREATE DATABASE

-- Создание базы данных
CREATE DATABASE IF NOT EXISTS u3064951_SmartBizSell CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Использование базы данных
USE u3064951_SmartBizSell;

-- Таблица пользователей (продавцов)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    company_name VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_email (email),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица анкет продавцов
CREATE TABLE IF NOT EXISTS seller_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    
    -- I. Детали предполагаемой сделки
    asset_name VARCHAR(500) DEFAULT NULL,
    deal_subject VARCHAR(500) DEFAULT NULL,
    deal_purpose ENUM('cash-out', 'cash-in') DEFAULT NULL,
    asset_disclosure ENUM('yes', 'no') DEFAULT NULL,
    
    -- II. Описание бизнеса компании
    company_description TEXT DEFAULT NULL,
    presence_regions VARCHAR(500) DEFAULT NULL,
    products_services TEXT DEFAULT NULL,
    company_brands VARCHAR(500) DEFAULT NULL,
    
    -- Собственные производственные мощности
    own_production ENUM('yes', 'no') DEFAULT NULL,
    production_sites_count INT DEFAULT NULL,
    production_sites_region VARCHAR(255) DEFAULT NULL,
    production_area VARCHAR(255) DEFAULT NULL,
    production_capacity VARCHAR(255) DEFAULT NULL,
    production_load VARCHAR(255) DEFAULT NULL,
    production_building_ownership ENUM('yes', 'no') DEFAULT NULL,
    production_land_ownership ENUM('yes', 'no') DEFAULT NULL,
    
    -- Контрактное производство
    contract_production_usage ENUM('yes', 'no') DEFAULT NULL,
    contract_production_region VARCHAR(255) DEFAULT NULL,
    contract_production_logistics TEXT DEFAULT NULL,
    
    -- Офлайн-продажи
    offline_sales_presence ENUM('yes', 'no') DEFAULT NULL,
    offline_sales_points INT DEFAULT NULL,
    offline_sales_regions VARCHAR(255) DEFAULT NULL,
    offline_sales_area VARCHAR(255) DEFAULT NULL,
    offline_sales_third_party ENUM('yes', 'no') DEFAULT NULL,
    offline_sales_distributors ENUM('yes', 'no') DEFAULT NULL,
    
    -- Онлайн-продажи
    online_sales_presence ENUM('yes', 'no') DEFAULT NULL,
    online_sales_share VARCHAR(255) DEFAULT NULL,
    online_sales_channels TEXT DEFAULT NULL,
    
    main_clients TEXT DEFAULT NULL,
    sales_share VARCHAR(255) DEFAULT NULL,
    personnel_count INT DEFAULT NULL,
    company_website VARCHAR(500) DEFAULT NULL,
    additional_info TEXT DEFAULT NULL,
    
    -- III. Основные операционные и финансовые показатели
    -- Объемы производства (JSON)
    production_volumes JSON DEFAULT NULL,
    
    -- Финансовые результаты
    financial_results_vat ENUM('with_vat', 'without_vat') DEFAULT NULL,
    financial_results JSON DEFAULT NULL,
    
    -- Балансовые показатели (JSON)
    balance_indicators JSON DEFAULT NULL,
    
    -- Источник финансовых показателей
    financial_source ENUM('RSBU', 'IFRS', 'management') DEFAULT NULL,
    
    -- Статус и метаданные
    status ENUM('draft', 'submitted', 'review', 'approved', 'rejected') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    data_json JSON DEFAULT NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица сессий (опционально, для управления сессиями)
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сообщение об успешном завершении
SELECT 'База данных u3064951_SmartBizSell успешно создана!' AS message;

