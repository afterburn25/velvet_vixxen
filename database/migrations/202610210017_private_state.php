<?php
declare(strict_types=1);

return [
    'id' => '202610210017_private_state',
    'description' => 'Private transactional operational journals',
    'tables' => ['private_state'],
    'up' => [
        'CREATE TABLE IF NOT EXISTS `private_state` (`namespace` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, `payload_json` LONGTEXT NOT NULL, `revision` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`namespace`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ],
    'down' => ['DROP TABLE IF EXISTS `private_state`'],
];
