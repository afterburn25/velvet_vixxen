<?php
declare(strict_types=1);

return [
    'id' => '202610190014_merchandise',
    'description' => 'Merchandise catalog, variant inventory and order reservations',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS merchandise_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    requires_shipping TINYINT(1) NOT NULL DEFAULT 1,
    shipping_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    image_ids_json LONGTEXT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_merchandise_public (status, deleted_at, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS merchandise_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(190) NOT NULL,
    sku VARCHAR(100) NOT NULL,
    options_json LONGTEXT NULL,
    price DECIMAL(12,2) NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_merchandise_sku (sku),
    INDEX idx_merchandise_variant_product (product_id, is_enabled, sort_order),
    CONSTRAINT fk_merchandise_variant_product FOREIGN KEY (product_id) REFERENCES merchandise_products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS merchandise_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    status ENUM('reserved','committed','released') NOT NULL DEFAULT 'reserved',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_merchandise_reservation (order_id, variant_id),
    CONSTRAINT fk_merchandise_reservation_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_merchandise_reservation_variant FOREIGN KEY (variant_id) REFERENCES merchandise_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS merchandise_reservations',
        'DROP TABLE IF EXISTS merchandise_variants',
        'DROP TABLE IF EXISTS merchandise_products',
    ],
];
