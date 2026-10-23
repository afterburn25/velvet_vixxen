<?php
declare(strict_types=1);

return [
    'id'=>'202610220019_recurring_lifecycle',
    'description'=>'Immutable recurring agreements and durable commands and cycles',
    'tables'=>['recurring_agreements','recurring_journal'],
    'up'=>[
        'CREATE TABLE IF NOT EXISTS recurring_agreements (id VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, member_id BIGINT UNSIGNED NOT NULL, subscription_id BIGINT UNSIGNED NOT NULL, scope_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, remote_id VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, record_json LONGTEXT NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_recurring_subscription (subscription_id), UNIQUE KEY uq_recurring_remote (scope_hash,remote_id), INDEX idx_recurring_member (member_id,id), CONSTRAINT fk_recurring_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT, CONSTRAINT fk_recurring_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS recurring_journal (id VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, agreement_id VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, record_kind VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, dedup_hash CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, order_id BIGINT UNSIGNED NULL, record_json LONGTEXT NOT NULL, PRIMARY KEY (id), UNIQUE KEY uq_recurring_dedup (record_kind,dedup_hash), INDEX idx_recurring_agreement (agreement_id,record_kind,id), INDEX idx_recurring_order (order_id), CONSTRAINT fk_recurring_journal_agreement FOREIGN KEY (agreement_id) REFERENCES recurring_agreements(id) ON DELETE RESTRICT, CONSTRAINT fk_recurring_journal_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down'=>['DROP TABLE IF EXISTS recurring_journal','DROP TABLE IF EXISTS recurring_agreements'],
];
