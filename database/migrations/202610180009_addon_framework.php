<?php
declare(strict_types=1);

return [
    'id' => '202610180009_addon_framework',
    'description' => 'Validated add-on packages, lifecycle logs, settings, backups, and tasks',
    'up' => [
        <<<'SQL'
ALTER TABLE addons
    ADD COLUMN package_sha256 CHAR(64) NULL AFTER permissions_json,
    ADD COLUMN signature_status VARCHAR(32) NOT NULL DEFAULT 'unsigned' AFTER package_sha256,
    ADD COLUMN installed_path VARCHAR(255) NULL AFTER signature_status,
    ADD COLUMN previous_version VARCHAR(64) NULL AFTER installed_path,
    ADD COLUMN failure_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER previous_version,
    ADD COLUMN last_error TEXT NULL AFTER failure_count
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    addon_key VARCHAR(190) NULL,
    event_key VARCHAR(190) NOT NULL,
    level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_addon_activity_key (addon_key, created_at),
    INDEX idx_addon_activity_event (event_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_settings (
    addon_key VARCHAR(190) NOT NULL,
    setting_key VARCHAR(190) NOT NULL,
    setting_value LONGTEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (addon_key, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_backups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    backup_key VARCHAR(190) NOT NULL UNIQUE,
    addon_key VARCHAR(190) NOT NULL,
    version VARCHAR(64) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    checksum CHAR(64) NOT NULL,
    reason VARCHAR(80) NOT NULL DEFAULT 'update',
    created_at DATETIME NOT NULL,
    INDEX idx_addon_backups_key (addon_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS addon_tasks (
    addon_key VARCHAR(190) NOT NULL,
    task_key VARCHAR(190) NOT NULL,
    schedule_expression VARCHAR(190) NOT NULL DEFAULT 'manual',
    handler VARCHAR(500) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL,
    last_status VARCHAR(40) NULL,
    last_error TEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (addon_key, task_key),
    INDEX idx_addon_tasks_due (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS addon_tasks',
        'DROP TABLE IF EXISTS addon_backups',
        'DROP TABLE IF EXISTS addon_settings',
        'DROP TABLE IF EXISTS addon_activity_log',
        'ALTER TABLE addons DROP COLUMN last_error, DROP COLUMN failure_count, DROP COLUMN previous_version, DROP COLUMN installed_path, DROP COLUMN signature_status, DROP COLUMN package_sha256',
    ],
];
