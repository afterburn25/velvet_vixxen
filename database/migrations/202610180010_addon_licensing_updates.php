<?php
declare(strict_types=1);

return [
    'id' => '202610180010_addon_licensing_updates',
    'description' => 'Commercial add-on licenses, activations, signed releases, downloads, and update history',
    'up' => [
        <<<'SQL'
ALTER TABLE addon_licenses
    ADD COLUMN license_key_encrypted TEXT NULL AFTER license_key_hash,
    ADD COLUMN license_key_prefix VARCHAR(40) NULL AFTER license_key_encrypted,
    ADD COLUMN product_key VARCHAR(190) NULL AFTER status,
    ADD COLUMN server_url VARCHAR(500) NULL AFTER product_key,
    ADD COLUMN public_key TEXT NULL AFTER server_url,
    ADD COLUMN updates_expires_at DATETIME NULL AFTER expires_at,
    ADD COLUMN support_expires_at DATETIME NULL AFTER updates_expires_at,
    ADD COLUMN grace_until DATETIME NULL AFTER support_expires_at,
    ADD COLUMN last_successful_at DATETIME NULL AFTER last_checked_at,
    ADD COLUMN last_error TEXT NULL AFTER last_successful_at,
    ADD COLUMN response_signature TEXT NULL AFTER last_error,
    ADD COLUMN update_channel VARCHAR(32) NOT NULL DEFAULT 'stable' AFTER response_signature
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_license_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_key VARCHAR(190) NOT NULL UNIQUE,
    addon_key VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    license_type ENUM('free','trial','one_time','monthly','annual','lifetime') NOT NULL DEFAULT 'lifetime',
    billing_interval VARCHAR(40) NOT NULL DEFAULT 'one_time',
    duration_days INT UNSIGNED NOT NULL DEFAULT 0,
    site_limit INT UNSIGNED NOT NULL DEFAULT 1,
    updates_days INT UNSIGNED NOT NULL DEFAULT 365,
    support_days INT UNSIGNED NOT NULL DEFAULT 365,
    grace_days INT UNSIGNED NOT NULL DEFAULT 7,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_addon_license_products_addon (addon_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_license_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_key VARCHAR(190) NOT NULL,
    addon_key VARCHAR(190) NOT NULL,
    license_key_hash CHAR(64) NOT NULL UNIQUE,
    license_key_prefix VARCHAR(40) NOT NULL,
    customer_email VARCHAR(255) NULL,
    status ENUM('active','suspended','expired','revoked','refunded') NOT NULL DEFAULT 'active',
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    updates_expires_at DATETIME NULL,
    support_expires_at DATETIME NULL,
    max_activations INT UNSIGNED NOT NULL DEFAULT 1,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_addon_license_keys_product (product_key, status),
    INDEX idx_addon_license_keys_customer (customer_email, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_license_activations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id BIGINT UNSIGNED NOT NULL,
    addon_key VARCHAR(190) NOT NULL,
    installation_id VARCHAR(190) NOT NULL,
    domain_name VARCHAR(255) NULL,
    site_url VARCHAR(500) NULL,
    status ENUM('active','deactivated','revoked') NOT NULL DEFAULT 'active',
    activated_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    deactivated_at DATETIME NULL,
    metadata_json LONGTEXT NULL,
    UNIQUE KEY uniq_addon_license_activation (license_id, installation_id),
    INDEX idx_addon_license_activation_addon (addon_key, status),
    INDEX idx_addon_license_activation_domain (domain_name, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_releases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_key VARCHAR(190) NOT NULL,
    version VARCHAR(64) NOT NULL,
    channel ENUM('stable','beta','development') NOT NULL DEFAULT 'stable',
    package_path VARCHAR(500) NOT NULL,
    package_sha256 CHAR(64) NOT NULL,
    package_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    signature_status VARCHAR(32) NOT NULL DEFAULT 'unsigned',
    minimum_core_version VARCHAR(64) NOT NULL DEFAULT '0.6.1',
    maximum_core_version VARCHAR(64) NULL,
    minimum_php_version VARCHAR(64) NOT NULL DEFAULT '8.2.0',
    changelog LONGTEXT NULL,
    release_notes_url VARCHAR(500) NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_addon_release (addon_key, channel, version),
    INDEX idx_addon_releases_feed (addon_key, channel, is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_update_checks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_key VARCHAR(190) NOT NULL,
    installation_id VARCHAR(190) NULL,
    current_version VARCHAR(64) NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    status VARCHAR(40) NOT NULL DEFAULT 'none',
    available_version VARCHAR(64) NULL,
    response_json LONGTEXT NULL,
    checked_at DATETIME NOT NULL,
    INDEX idx_addon_update_checks_key (addon_key, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_license_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM('purchase','renewal','refund','revocation','adjustment') NOT NULL DEFAULT 'purchase',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    reference_key VARCHAR(190) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_addon_license_transactions_license (license_id, created_at),
    INDEX idx_addon_license_transactions_reference (reference_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_download_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    license_id BIGINT UNSIGNED NOT NULL,
    release_id BIGINT UNSIGNED NOT NULL,
    installation_id VARCHAR(190) NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_addon_download_tokens_expiry (expires_at),
    INDEX idx_addon_download_tokens_license (license_id, release_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS addon_download_tokens',
        'DROP TABLE IF EXISTS addon_license_transactions',
        'DROP TABLE IF EXISTS addon_update_checks',
        'DROP TABLE IF EXISTS addon_releases',
        'DROP TABLE IF EXISTS addon_license_activations',
        'DROP TABLE IF EXISTS addon_license_keys',
        'DROP TABLE IF EXISTS addon_license_products',
        'ALTER TABLE addon_licenses DROP COLUMN update_channel, DROP COLUMN response_signature, DROP COLUMN last_error, DROP COLUMN last_successful_at, DROP COLUMN grace_until, DROP COLUMN support_expires_at, DROP COLUMN updates_expires_at, DROP COLUMN public_key, DROP COLUMN server_url, DROP COLUMN product_key, DROP COLUMN license_key_prefix, DROP COLUMN license_key_encrypted',
    ],
];
