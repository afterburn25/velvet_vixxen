<?php
declare(strict_types=1);

return [
    'id' => '202610190013_booking_form_builder',
    'description' => 'Preserve booking field definitions and submitted answers independently of later form edits.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS booking_form_submissions (
    booking_request_id BIGINT UNSIGNED PRIMARY KEY,
    definition_revision VARCHAR(64) NOT NULL,
    snapshot_json LONGTEXT NOT NULL,
    values_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_booking_form_submission_request FOREIGN KEY (booking_request_id) REFERENCES booking_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => ['DROP TABLE IF EXISTS booking_form_submissions'],
];
