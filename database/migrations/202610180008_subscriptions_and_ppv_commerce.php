<?php
declare(strict_types=1);

return [
    'id' => '202610180008_subscriptions_and_ppv_commerce',
    'description' => 'Payment providers, subscriptions checkout, PPV orders, rentals, coupons, bundles, webhooks, fulfillment, and refunds',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_providers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    test_mode TINYINT(1) NOT NULL DEFAULT 1,
    supports_recurring TINYINT(1) NOT NULL DEFAULT 0,
    settings_json LONGTEXT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_payment_providers_enabled (is_enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS coupons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    discount_type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
    discount_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    scope ENUM('all','subscription','ppv','rental','bundle') NOT NULL DEFAULT 'all',
    minimum_subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    maximum_discount DECIMAL(12,2) NULL,
    usage_limit INT UNSIGNED NULL,
    per_member_limit INT UNSIGNED NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_coupons_active_dates (is_active, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS commerce_bundles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    subscriber_price DECIMAL(12,2) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_commerce_bundles_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS commerce_bundle_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bundle_id BIGINT UNSIGNED NOT NULL,
    item_type ENUM('gallery','video') NOT NULL,
    item_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_bundle_item (bundle_id, item_type, item_id),
    INDEX idx_bundle_items_order (bundle_id, sort_order),
    CONSTRAINT fk_bundle_items_bundle FOREIGN KEY (bundle_id) REFERENCES commerce_bundles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
ALTER TABLE orders
    MODIFY COLUMN status ENUM('pending','processing','paid','failed','refunded','partially_refunded','chargeback','cancelled') NOT NULL DEFAULT 'pending',
    ADD COLUMN refunded_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER grand_total,
    ADD COLUMN provider_key VARCHAR(80) NULL AFTER coupon_code,
    ADD COLUMN provider_checkout_id VARCHAR(255) NULL AFTER provider_key,
    ADD COLUMN idempotency_key VARCHAR(190) NULL AFTER provider_checkout_id,
    ADD COLUMN billing_email VARCHAR(190) NULL AFTER idempotency_key,
    ADD COLUMN metadata_json LONGTEXT NULL AFTER billing_email,
    ADD UNIQUE KEY uniq_orders_idempotency (idempotency_key),
    ADD INDEX idx_orders_provider (provider_key, provider_checkout_id)
SQL,
        <<<'SQL'
ALTER TABLE order_items
    ADD COLUMN fulfillment_status ENUM('pending','fulfilled','failed','revoked') NOT NULL DEFAULT 'pending' AFTER metadata_json
SQL,
        <<<'SQL'
ALTER TABLE payment_transactions
    ADD COLUMN last4 VARCHAR(4) NULL AFTER currency,
    ADD COLUMN brand VARCHAR(80) NULL AFTER last4
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS coupon_redemptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coupon_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('applied','reversed') NOT NULL DEFAULT 'applied',
    created_at DATETIME NOT NULL,
    INDEX idx_coupon_redemptions_coupon (coupon_id, status),
    INDEX idx_coupon_redemptions_member (member_id, coupon_id, status),
    INDEX idx_coupon_redemptions_order (order_id),
    CONSTRAINT fk_coupon_redemptions_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
    CONSTRAINT fk_coupon_redemptions_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    CONSTRAINT fk_coupon_redemptions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_key VARCHAR(80) NOT NULL,
    provider_event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    status ENUM('received','processed','failed','ignored') NOT NULL DEFAULT 'received',
    payload_json LONGTEXT NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_provider_event (provider_key, provider_event_id),
    INDEX idx_webhook_events_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO payment_providers (provider_key,name,is_enabled,test_mode,supports_recurring,settings_json,sort_order,created_at,updated_at)
VALUES ('sandbox','Sandbox Test Gateway',1,1,1,'{}',10,NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name),supports_recurring=VALUES(supports_recurring),updated_at=NOW()
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS payment_webhook_events',
        'DROP TABLE IF EXISTS coupon_redemptions',
        <<<'SQL'
ALTER TABLE payment_transactions
    DROP COLUMN brand,
    DROP COLUMN last4
SQL,
        <<<'SQL'
ALTER TABLE order_items
    DROP COLUMN fulfillment_status
SQL,
        <<<'SQL'
ALTER TABLE orders
    DROP INDEX idx_orders_provider,
    DROP INDEX uniq_orders_idempotency,
    MODIFY COLUMN status ENUM('pending','paid','failed','refunded','partially_refunded','chargeback','cancelled') NOT NULL DEFAULT 'pending',
    DROP COLUMN metadata_json,
    DROP COLUMN billing_email,
    DROP COLUMN idempotency_key,
    DROP COLUMN provider_checkout_id,
    DROP COLUMN provider_key,
    DROP COLUMN refunded_total
SQL,
        'DROP TABLE IF EXISTS commerce_bundle_items',
        'DROP TABLE IF EXISTS commerce_bundles',
        'DROP TABLE IF EXISTS coupons',
        'DROP TABLE IF EXISTS payment_providers',
    ],
];
