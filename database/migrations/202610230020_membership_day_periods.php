<?php
declare(strict_types=1);

return [
    'id' => '202610230020_membership_day_periods',
    'description' => 'Explicit fixed-day membership periods; preserve all existing plans and purchases',
    'tables' => [],
    'up' => ["ALTER TABLE membership_plans MODIFY billing_interval ENUM('month','year','one_time','day') NOT NULL DEFAULT 'month'"],
    // Do not narrow an ENUM which may contain accepted day-based purchases.
    // Full disposable-schema rollback subsequently drops this table in 004.
    'down' => [],
];
