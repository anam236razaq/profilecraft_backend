-- --------------------------------------------------------
-- Website Templates
-- --------------------------------------------------------
CREATE TABLE templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    thumbnail_url VARCHAR(500) NULL,
    category ENUM('personal', 'portfolio', 'business', 'blog', 'minimal', 'creative') DEFAULT 'personal',
    html_template TEXT NOT NULL,
    css_styles TEXT NOT NULL,
    config_schema JSON NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_premium BOOLEAN DEFAULT FALSE,
    usage_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_category (category),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: template_themes table removed - no longer used
-- Note: website_sections and website_content tables removed - no longer used
