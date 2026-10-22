<?php
declare(strict_types=1);

return [
    'id' => '202610220018_payment_journal',
    'description' => 'Bound payment intents, event receipts and post-commit obligations',
    'tables' => ['payment_journal'],
    'up' => [
        'CREATE TABLE IF NOT EXISTS payment_journal (id VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, order_id BIGINT UNSIGNED NULL, record_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, record_json LONGTEXT NOT NULL, PRIMARY KEY (id), INDEX idx_payment_journal_order (order_id,record_kind), CONSTRAINT fk_payment_journal_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => ['DROP TABLE IF EXISTS payment_journal'],
];
