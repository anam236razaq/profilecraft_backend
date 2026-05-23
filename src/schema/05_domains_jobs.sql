-- --------------------------------------------------------
-- Website Custom Domain Configurations
-- --------------------------------------------------------
CREATE TABLE custom_domains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL UNIQUE,
    ssl_cert_url VARCHAR(500) NULL,
    verification_token VARCHAR(255) NULL,
    verification_method ENUM('dns', 'file', 'email') DEFAULT 'dns',
    is_verified BOOLEAN DEFAULT FALSE,
    is_ssl_enabled BOOLEAN DEFAULT FALSE,
    ssl_issued_at TIMESTAMP NULL,
    ssl_expires_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    INDEX idx_domain (domain),
    INDEX idx_is_verified (is_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: website_versions and website_themes tables removed - no longer used

-- --------------------------------------------------------
-- Contact Form Submissions
-- --------------------------------------------------------
CREATE TABLE contact_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id BIGINT UNSIGNED NOT NULL,
    sender_name VARCHAR(255) NOT NULL,
    sender_email VARCHAR(255) NOT NULL,
    sender_phone VARCHAR(50) NULL,
    subject VARCHAR(500) NULL,
    message TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    is_read BOOLEAN DEFAULT FALSE,
    is_replied BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    INDEX idx_website_id (website_id),
    INDEX idx_created_at (created_at),
    INDEX idx_is_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Sync Jobs (track social media sync status)
-- --------------------------------------------------------
CREATE TABLE sync_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    website_id BIGINT UNSIGNED NOT NULL,
    social_account_id BIGINT UNSIGNED NULL,
    job_type ENUM('full_sync', 'incremental_sync', 'content_import', 'profile_update') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    total_items INT UNSIGNED DEFAULT 0,
    processed_items INT UNSIGNED DEFAULT 0,
    error_message TEXT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
