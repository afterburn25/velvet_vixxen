<?php
declare(strict_types=1);

return [
    'id' => '202610180007_members_and_entitlements',
    'description' => 'Member verification, recovery, device sessions, account history, email queue, and synchronized media state',
    'up' => [
        <<<'SQL'
ALTER TABLE members
    ADD COLUMN preferences_json LONGTEXT NULL AFTER last_login_at,
    ADD COLUMN failed_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER preferences_json,
    ADD COLUMN locked_until DATETIME NULL AFTER failed_login_count,
    ADD COLUMN last_seen_at DATETIME NULL AFTER locked_until
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS member_auth_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    token_type ENUM('email_verification','password_reset') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_member_tokens_lookup (member_id, token_type, used_at, expires_at),
    CONSTRAINT fk_member_tokens_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS member_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(190) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    last_seen_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    INDEX idx_member_sessions_member (member_id, revoked_at, last_seen_at),
    CONSTRAINT fk_member_sessions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS member_account_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(190) NOT NULL,
    context_json LONGTEXT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_member_events_member (member_id, created_at),
    INDEX idx_member_events_key (event_key, created_at),
    CONSTRAINT fk_member_events_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS mail_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NULL,
    recipient_email VARCHAR(190) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    template_key VARCHAR(100) NOT NULL,
    body_text LONGTEXT NOT NULL,
    metadata_json LONGTEXT NULL,
    status ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    INDEX idx_mail_outbox_status (status, created_at),
    INDEX idx_mail_outbox_member (member_id, created_at),
    CONSTRAINT fk_mail_outbox_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE member_media_state
    ADD COLUMN view_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_favorite,
    ADD COLUMN completed_at DATETIME NULL AFTER last_viewed_at,
    ADD COLUMN metadata_json LONGTEXT NULL AFTER completed_at
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS mail_outbox',
        'DROP TABLE IF EXISTS member_account_events',
        'DROP TABLE IF EXISTS member_sessions',
        'DROP TABLE IF EXISTS member_auth_tokens',
        <<<'SQL'
ALTER TABLE member_media_state
    DROP COLUMN metadata_json,
    DROP COLUMN completed_at,
    DROP COLUMN view_count
SQL,
        <<<'SQL'
ALTER TABLE members
    DROP COLUMN last_seen_at,
    DROP COLUMN locked_until,
    DROP COLUMN failed_login_count,
    DROP COLUMN preferences_json
SQL,
    ],
];
