<?php
declare(strict_types=1);

return [
    'id' => '202610170004_media_and_commerce_foundation',
    'description' => 'Future-ready music, photo, video, subscriptions, PPV, orders, and entitlements schema',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at DATETIME NULL,
    status ENUM('pending','active','suspended','closed') NOT NULL DEFAULT 'pending',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_members_status (status),
    INDEX idx_members_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS membership_plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_key VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    billing_interval ENUM('month','year','one_time') NOT NULL DEFAULT 'month',
    interval_count INT UNSIGNED NOT NULL DEFAULT 1,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    trial_days INT UNSIGNED NOT NULL DEFAULT 0,
    ppv_discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    benefits_json LONGTEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_membership_plans_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    provider_key VARCHAR(80) NULL,
    provider_subscription_id VARCHAR(255) NULL,
    status ENUM('trialing','active','past_due','paused','cancelled','expired') NOT NULL,
    current_period_start DATETIME NULL,
    current_period_end DATETIME NULL,
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    grace_ends_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_subscriptions_member_status (member_id, status),
    INDEX idx_subscriptions_provider (provider_key, provider_subscription_id),
    CONSTRAINT fk_subscriptions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS photo_galleries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    cover_asset_id BIGINT UNSIGNED NULL,
    status ENUM('draft','scheduled','published','archived','unlisted') NOT NULL DEFAULT 'draft',
    access_mode VARCHAR(40) NOT NULL DEFAULT 'public',
    standard_price DECIMAL(12,2) NULL,
    subscriber_price DECIMAL(12,2) NULL,
    rental_price DECIMAL(12,2) NULL,
    rental_hours INT UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    preview_count INT UNSIGNED NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_galleries_status_date (status, published_at),
    INDEX idx_galleries_access (access_mode),
    CONSTRAINT fk_galleries_cover FOREIGN KEY (cover_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS photo_gallery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gallery_id BIGINT UNSIGNED NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    caption TEXT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_preview TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_gallery_asset (gallery_id, asset_id),
    INDEX idx_gallery_items_order (gallery_id, sort_order),
    CONSTRAINT fk_gallery_items_gallery FOREIGN KEY (gallery_id) REFERENCES photo_galleries(id) ON DELETE CASCADE,
    CONSTRAINT fk_gallery_items_asset FOREIGN KEY (asset_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS videos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    poster_asset_id BIGINT UNSIGNED NULL,
    trailer_asset_id BIGINT UNSIGNED NULL,
    full_video_asset_id BIGINT UNSIGNED NULL,
    status ENUM('draft','processing','scheduled','published','archived','unlisted','failed') NOT NULL DEFAULT 'draft',
    access_mode VARCHAR(40) NOT NULL DEFAULT 'public',
    standard_price DECIMAL(12,2) NULL,
    subscriber_price DECIMAL(12,2) NULL,
    rental_price DECIMAL(12,2) NULL,
    rental_hours INT UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    duration_seconds DECIMAL(12,3) NULL,
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    INDEX idx_videos_status_date (status, published_at),
    INDEX idx_videos_access (access_mode),
    CONSTRAINT fk_videos_poster FOREIGN KEY (poster_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_videos_trailer FOREIGN KEY (trailer_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_videos_full FOREIGN KEY (full_video_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS audio_albums (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    cover_asset_id BIGINT UNSIGNED NULL,
    status ENUM('draft','scheduled','published','archived','unlisted') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_audio_albums_cover FOREIGN KEY (cover_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS audio_tracks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id BIGINT UNSIGNED NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    artist VARCHAR(190) NOT NULL DEFAULT 'Nikki Wilder',
    audio_asset_id BIGINT UNSIGNED NOT NULL,
    cover_asset_id BIGINT UNSIGNED NULL,
    lyrics LONGTEXT NULL,
    status ENUM('draft','scheduled','published','archived','unlisted') NOT NULL DEFAULT 'draft',
    access_mode VARCHAR(40) NOT NULL DEFAULT 'public',
    preview_seconds INT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 100,
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_tracks_album_order (album_id, sort_order),
    INDEX idx_tracks_status (status, published_at),
    CONSTRAINT fk_tracks_album FOREIGN KEY (album_id) REFERENCES audio_albums(id) ON DELETE SET NULL,
    CONSTRAINT fk_tracks_audio FOREIGN KEY (audio_asset_id) REFERENCES media_assets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_tracks_cover FOREIGN KEY (cover_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS content_access_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_type VARCHAR(40) NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    rule_type VARCHAR(40) NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    value_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_access_content (content_type, content_id),
    INDEX idx_access_plan (plan_id),
    CONSTRAINT fk_access_plan FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(80) NOT NULL UNIQUE,
    status ENUM('pending','paid','failed','refunded','partially_refunded','chargeback','cancelled') NOT NULL DEFAULT 'pending',
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    tax_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    coupon_code VARCHAR(100) NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_orders_member_created (member_id, created_at),
    INDEX idx_orders_status (status, created_at),
    CONSTRAINT fk_orders_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    item_type VARCHAR(40) NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_order_items_order (order_id),
    INDEX idx_order_items_content (item_type, item_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NULL,
    subscription_id BIGINT UNSIGNED NULL,
    provider_key VARCHAR(80) NOT NULL,
    provider_transaction_id VARCHAR(255) NULL,
    transaction_type VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    provider_payload_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_transactions_order (order_id),
    INDEX idx_transactions_subscription (subscription_id),
    INDEX idx_transactions_provider (provider_key, provider_transaction_id),
    CONSTRAINT fk_transactions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS content_entitlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    content_type VARCHAR(40) NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(40) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_entitlement_lookup (member_id, content_type, content_id, status),
    INDEX idx_entitlement_expiry (status, expires_at),
    CONSTRAINT fk_entitlements_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS member_media_state (
    member_id BIGINT UNSIGNED NOT NULL,
    content_type VARCHAR(40) NOT NULL,
    content_id BIGINT UNSIGNED NOT NULL,
    progress_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
    is_favorite TINYINT(1) NOT NULL DEFAULT 0,
    last_viewed_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (member_id, content_type, content_id),
    INDEX idx_media_state_favorite (member_id, is_favorite),
    CONSTRAINT fk_media_state_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS member_media_state',
        'DROP TABLE IF EXISTS content_entitlements',
        'DROP TABLE IF EXISTS payment_transactions',
        'DROP TABLE IF EXISTS order_items',
        'DROP TABLE IF EXISTS orders',
        'DROP TABLE IF EXISTS content_access_rules',
        'DROP TABLE IF EXISTS audio_tracks',
        'DROP TABLE IF EXISTS audio_albums',
        'DROP TABLE IF EXISTS videos',
        'DROP TABLE IF EXISTS photo_gallery_items',
        'DROP TABLE IF EXISTS photo_galleries',
        'DROP TABLE IF EXISTS subscriptions',
        'DROP TABLE IF EXISTS membership_plans',
        'DROP TABLE IF EXISTS members',
    ],
];
