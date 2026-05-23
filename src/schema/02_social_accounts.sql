-- --------------------------------------------------------
-- Social Accounts Table (OAuth connections)
-- --------------------------------------------------------
CREATE TABLE social_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('instagram', 'linkedin', 'github', 'twitter', 'facebook', 'youtube') NOT NULL,
    provider_user_id VARCHAR(255) NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    token_expires_at TIMESTAMP NULL,
    provider_username VARCHAR(255) NULL,
    provider_avatar_url VARCHAR(500) NULL,
    is_connected BOOLEAN DEFAULT TRUE,
    last_fetched_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_user (provider, provider_user_id),
    UNIQUE KEY unique_user_provider (user_id, provider),
    INDEX idx_user_id (user_id),
    INDEX idx_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Social Profile Data (cached profile info from APIs)
-- --------------------------------------------------------
CREATE TABLE social_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_account_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(255) NULL,
    username VARCHAR(255) NULL,
    bio TEXT NULL,
    profile_url VARCHAR(500) NULL,
    profile_image_url VARCHAR(500) NULL,
    location VARCHAR(255) NULL,
    website_url VARCHAR(500) NULL,
    follower_count INT UNSIGNED DEFAULT 0,
    following_count INT UNSIGNED DEFAULT 0,
    post_count INT UNSIGNED DEFAULT 0,
    metadata JSON NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    INDEX idx_social_account_id (social_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Social Posts (imported content)
-- --------------------------------------------------------
CREATE TABLE social_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_account_id BIGINT UNSIGNED NOT NULL,
    post_id VARCHAR(255) NOT NULL,
    post_type ENUM('text', 'image', 'video', 'link', 'album') DEFAULT 'text',
    content TEXT NULL,
    media_url VARCHAR(500) NULL,
    media_thumbnail VARCHAR(500) NULL,
    post_url VARCHAR(500) NULL,
    like_count INT UNSIGNED DEFAULT 0,
    comment_count INT UNSIGNED DEFAULT 0,
    share_count INT UNSIGNED DEFAULT 0,
    posted_at TIMESTAMP NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_post (social_account_id, post_id),
    INDEX idx_posted_at (posted_at),
    INDEX idx_post_type (post_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Social Projects (for GitHub, LinkedIn, etc.)
-- --------------------------------------------------------
CREATE TABLE social_projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_account_id BIGINT UNSIGNED NOT NULL,
    project_id VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    url VARCHAR(500) NULL,
    language VARCHAR(100) NULL,
    stars_count INT UNSIGNED DEFAULT 0,
    forks_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE,
    UNIQUE KEY unique_project (social_account_id, project_id),
    INDEX idx_social_account_id (social_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
