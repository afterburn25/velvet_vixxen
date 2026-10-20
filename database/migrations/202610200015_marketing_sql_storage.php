<?php
declare(strict_types=1);

return [
    'id' => '202610200015_marketing_sql_storage',
    'description' => 'Transactional marketing record storage with an explicit preservation-checked move',
    'tables' => ['marketing_storage_state', 'marketing_storage_records'],
    'up' => [
        'CREATE TABLE IF NOT EXISTS `marketing_storage_state` (`id` TINYINT UNSIGNED NOT NULL, `storage_id` VARCHAR(64) NOT NULL, `installation_id` VARCHAR(190) NOT NULL, `status` VARCHAR(20) NOT NULL, `header_json` LONGTEXT NOT NULL, `layout_json` LONGTEXT NOT NULL, `revision` BIGINT UNSIGNED NOT NULL, `source_sha256` CHAR(64) NOT NULL, `snapshot_sha256` CHAR(64) NOT NULL, `migrated_at` VARCHAR(40) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_storage_records` (`collection` VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `entry_key_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `entry_key_json` LONGTEXT NOT NULL, `position` BIGINT UNSIGNED NOT NULL, `payload_json` LONGTEXT NOT NULL, PRIMARY KEY (`collection`,`entry_key_hash`), KEY `idx_marketing_storage_order` (`collection`,`position`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => [
        'DROP TABLE IF EXISTS `marketing_storage_records`',
        'DROP TABLE IF EXISTS `marketing_storage_state`',
    ],
];
