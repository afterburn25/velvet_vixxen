<?php
declare(strict_types=1);

return [
    'id' => '202610170003_addon_foundation',
    'description' => 'Add-on registry, add-on migrations, licenses, and event jobs',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_key VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    vendor VARCHAR(190) NULL,
    version VARCHAR(64) NOT NULL,
    status ENUM('installed','active','inactive','quarantined','failed') NOT NULL DEFAULT 'installed',
    manifest_json LONGTEXT NOT NULL,
    permissions_json LONGTEXT NULL,
    installed_at DATETIME NOT NULL,
    activated_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_addons_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_migrations (
    addon_key VARCHAR(190) NOT NULL,
    migration_key VARCHAR(190) NOT NULL,
    checksum CHAR(64) NOT NULL,
    batch INT UNSIGNED NOT NULL,
    executed_at DATETIME NOT NULL,
    PRIMARY KEY (addon_key, migration_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_licenses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_key VARCHAR(190) NOT NULL,
    license_key_hash CHAR(64) NULL,
    license_type VARCHAR(40) NOT NULL DEFAULT 'free',
    status VARCHAR(40) NOT NULL DEFAULT 'inactive',
    domain_name VARCHAR(255) NULL,
    installation_id VARCHAR(190) NULL,
    expires_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_addon_installation (addon_key, installation_id),
    INDEX idx_addon_license_status (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS scheduled_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_key VARCHAR(190) NOT NULL,
    source_type VARCHAR(40) NOT NULL DEFAULT 'core',
    source_key VARCHAR(190) NULL,
    payload_json LONGTEXT NULL,
    status ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_jobs_queue (status, available_at),
    INDEX idx_jobs_source (source_type, source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS scheduled_jobs',
        'DROP TABLE IF EXISTS addon_licenses',
        'DROP TABLE IF EXISTS addon_migrations',
        'DROP TABLE IF EXISTS addons',
    ],
];
