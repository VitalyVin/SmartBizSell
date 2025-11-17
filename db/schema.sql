-- SmartBizSell Database Schema
-- MySQL 8.0

CREATE DATABASE IF NOT EXISTS smartbizsell CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartbizsell;

-- Таблица пользователей (продавцов)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    company_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_email (email),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица анкет продавцов
CREATE TABLE IF NOT EXISTS seller_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    
    -- I. Детали предполагаемой сделки
    asset_name VARCHAR(500),
    deal_subject VARCHAR(500),
    deal_purpose ENUM('cash-out', 'cash-in') NULL,
    
    -- II. Описание бизнеса компании
    company_description TEXT,
    presence_regions VARCHAR(500),
    products_services TEXT,
    company_brands VARCHAR(500),
    
    -- Собственные производственные мощности
    own_production ENUM('yes', 'no') NULL,
    production_sites_count INT,
    production_sites_region VARCHAR(255),
    production_area VARCHAR(255),
    production_capacity VARCHAR(255),
    production_load VARCHAR(255),
    production_building_ownership ENUM('yes', 'no') NULL,
    production_land_ownership ENUM('yes', 'no') NULL,
    
    -- Контрактное производство
    contract_production_usage ENUM('yes', 'no') NULL,
    contract_production_region VARCHAR(255),
    contract_production_logistics TEXT,
    
    -- Собственная розница
    own_retail_presence ENUM('yes', 'no') NULL,
    own_retail_points INT,
    own_retail_regions VARCHAR(255),
    own_retail_area VARCHAR(255),
    
    -- Онлайн-продажи
    online_sales_presence ENUM('yes', 'no') NULL,
    online_sales_share VARCHAR(255),
    online_sales_channels TEXT,
    
    main_clients TEXT,
    sales_share VARCHAR(255),
    personnel_count INT,
    company_website VARCHAR(500),
    additional_info TEXT,
    
    -- III. Финансовые показатели
    -- Объемы производства (JSON)
    production_volumes JSON,
    
    -- Финансовые показатели (JSON)
    financial_indicators JSON,
    
    -- Балансовые показатели
    debt_obligations DECIMAL(15,2),
    cash_balance DECIMAL(15,2),
    net_assets DECIMAL(15,2),
    financial_source ENUM('RSBU', 'IFRS', 'management') NULL,
    
    -- Статус и метаданные
    status ENUM('draft', 'submitted', 'review', 'approved', 'rejected') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица сессий (опционально, для управления сессиями)
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

