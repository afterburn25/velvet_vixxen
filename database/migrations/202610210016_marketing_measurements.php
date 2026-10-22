<?php
declare(strict_types=1);

return [
    'id' => '202610210016_marketing_measurements',
    'description' => 'Rebuildable indexed consented measurement reports with source revision binding',
    'tables' => ['marketing_measurement_state', 'marketing_measurement_records'],
    'up' => [
        'CREATE TABLE IF NOT EXISTS `marketing_measurement_state` (`id` TINYINT UNSIGNED NOT NULL, `revision` BIGINT UNSIGNED NOT NULL, `snapshot_sha256` CHAR(64) NOT NULL, `header_sha256` CHAR(64) NOT NULL, `projection_sha256` CHAR(64) NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_measurement_records` (`kind` VARCHAR(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `entry_key` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `occurred_at` BIGINT UNSIGNED NOT NULL, `event_name` VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `campaign_id` VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `referral_code` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `actor_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `payload_sha256` CHAR(64) NOT NULL, `payload_json` LONGTEXT NOT NULL, PRIMARY KEY (`kind`,`entry_key`), KEY `idx_measurement_date` (`kind`,`occurred_at`), KEY `idx_measurement_campaign` (`campaign_id`,`kind`,`occurred_at`), KEY `idx_measurement_referral` (`referral_code`,`kind`,`occurred_at`), KEY `idx_measurement_event` (`event_name`,`kind`,`occurred_at`), KEY `idx_measurement_actor` (`actor_hash`,`kind`,`occurred_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => ['DROP TABLE IF EXISTS `marketing_measurement_records`', 'DROP TABLE IF EXISTS `marketing_measurement_state`'],
];
