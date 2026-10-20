<?php
declare(strict_types=1);

return [
    'id' => '202610180011_messaging_booking_requests',
    'description' => 'Private messaging, booking inquiries, content requests, attachments, moderation, and member notifications',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS message_threads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NULL,
    thread_type ENUM('member','content_request','booking') NOT NULL DEFAULT 'member',
    related_type VARCHAR(80) NULL,
    related_id BIGINT UNSIGNED NULL,
    subject VARCHAR(190) NOT NULL,
    status ENUM('open','pending_member','pending_staff','closed','blocked') NOT NULL DEFAULT 'pending_staff',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    assigned_admin_id BIGINT UNSIGNED NULL,
    member_unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    admin_unread_count INT UNSIGNED NOT NULL DEFAULT 0,
    internal_notes TEXT NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_message_threads_member (member_id, status, last_message_at),
    INDEX idx_message_threads_queue (status, priority, last_message_at),
    INDEX idx_message_threads_related (related_type, related_id),
    CONSTRAINT fk_message_threads_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_message_threads_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('member','admin','system') NOT NULL,
    sender_member_id BIGINT UNSIGNED NULL,
    sender_admin_id BIGINT UNSIGNED NULL,
    visibility ENUM('public','internal') NOT NULL DEFAULT 'public',
    body LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_messages_thread (thread_id, id),
    INDEX idx_messages_sender_member (sender_member_id, created_at),
    CONSTRAINT fk_messages_thread FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_member FOREIGN KEY (sender_member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_messages_admin FOREIGN KEY (sender_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS message_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX idx_message_attachments_message (message_id, id),
    CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS saved_replies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(190) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'general',
    body LONGTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_saved_replies_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS communication_blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NULL,
    email VARCHAR(190) NULL,
    scope ENUM('messaging','requests','all') NOT NULL DEFAULT 'all',
    reason TEXT NULL,
    blocked_by_admin_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_communication_blocks_member (member_id, scope, expires_at),
    INDEX idx_communication_blocks_email (email, scope, expires_at),
    CONSTRAINT fk_communication_blocks_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_communication_blocks_admin FOREIGN KEY (blocked_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS content_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    thread_id BIGINT UNSIGNED NULL,
    category ENUM('photo','video','audio','custom','general') NOT NULL DEFAULT 'general',
    title VARCHAR(190) NOT NULL,
    description LONGTEXT NOT NULL,
    budget_min DECIMAL(12,2) NULL,
    budget_max DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    requested_due_at DATETIME NULL,
    status ENUM('submitted','reviewing','quoted','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'submitted',
    quoted_amount DECIMAL(12,2) NULL,
    admin_notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_content_requests_member (member_id, status, created_at),
    INDEX idx_content_requests_queue (status, created_at),
    CONSTRAINT fk_content_requests_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_content_requests_thread FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS booking_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NULL,
    thread_id BIGINT UNSIGNED NULL,
    inquiry_type ENUM('appearance','collaboration','interview','event','media','other') NOT NULL DEFAULT 'appearance',
    contact_name VARCHAR(120) NOT NULL,
    contact_email VARCHAR(190) NOT NULL,
    phone VARCHAR(80) NULL,
    requested_date DATETIME NULL,
    city VARCHAR(190) NULL,
    venue VARCHAR(255) NULL,
    budget VARCHAR(190) NULL,
    details LONGTEXT NOT NULL,
    status ENUM('submitted','reviewing','quoted','accepted','declined','completed','cancelled') NOT NULL DEFAULT 'submitted',
    priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    assigned_admin_id BIGINT UNSIGNED NULL,
    internal_notes TEXT NULL,
    consent_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_booking_requests_member (member_id, status, created_at),
    INDEX idx_booking_requests_queue (status, priority, requested_date),
    INDEX idx_booking_requests_email (contact_email, created_at),
    CONSTRAINT fk_booking_requests_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_requests_thread FOREIGN KEY (thread_id) REFERENCES message_threads(id) ON DELETE SET NULL,
    CONSTRAINT fk_booking_requests_admin FOREIGN KEY (assigned_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS request_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_type ENUM('content_request','booking_request') NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by_type ENUM('member','guest','admin') NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL,
    INDEX idx_request_attachments_request (request_type, request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS member_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(100) NOT NULL,
    title VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    target_url VARCHAR(500) NULL,
    metadata_json LONGTEXT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_member_notifications_member (member_id, read_at, created_at),
    CONSTRAINT fk_member_notifications_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS member_notifications',
        'DROP TABLE IF EXISTS request_attachments',
        'DROP TABLE IF EXISTS booking_requests',
        'DROP TABLE IF EXISTS content_requests',
        'DROP TABLE IF EXISTS communication_blocks',
        'DROP TABLE IF EXISTS saved_replies',
        'DROP TABLE IF EXISTS message_attachments',
        'DROP TABLE IF EXISTS messages',
        'DROP TABLE IF EXISTS message_threads',
    ],
];
