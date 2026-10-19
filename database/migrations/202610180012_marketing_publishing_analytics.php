<?php
declare(strict_types=1);

return [
    'id' => '202610180012_marketing_publishing_analytics',
    'description' => 'Marketing, publishing, newsletter, referrals, consent and privacy-conscious analytics foundations',
    'tables' => [
        'marketing_posts',
        'marketing_categories',
        'marketing_post_categories',
        'newsletter_contacts',
        'newsletter_segments',
        'newsletter_segment_members',
        'marketing_campaigns',
        'marketing_campaign_recipients',
        'marketing_banners',
        'marketing_landing_pages',
        'marketing_links',
        'referral_codes',
        'analytics_events',
        'consent_records'
    ],
    'up' => [
        'CREATE TABLE IF NOT EXISTS `marketing_posts` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_posts_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_categories` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_categories_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_post_categories` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_post_categories_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `newsletter_contacts` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_newsletter_contacts_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `newsletter_segments` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_newsletter_segments_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `newsletter_segment_members` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_newsletter_segment_members_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_campaigns` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_campaigns_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_campaign_recipients` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_campaign_recipients_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_banners` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_banners_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_landing_pages` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_landing_pages_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `marketing_links` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_marketing_links_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `referral_codes` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_referral_codes_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `analytics_events` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_analytics_events_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS `consent_records` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `public_id` VARCHAR(80) NULL, `status` VARCHAR(40) NULL, `payload_json` LONGTEXT NULL, `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME NULL, PRIMARY KEY (`id`), KEY `idx_consent_records_status` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ],
    'down' => [
        'DROP TABLE IF EXISTS `consent_records`',
        'DROP TABLE IF EXISTS `analytics_events`',
        'DROP TABLE IF EXISTS `referral_codes`',
        'DROP TABLE IF EXISTS `marketing_links`',
        'DROP TABLE IF EXISTS `marketing_landing_pages`',
        'DROP TABLE IF EXISTS `marketing_banners`',
        'DROP TABLE IF EXISTS `marketing_campaign_recipients`',
        'DROP TABLE IF EXISTS `marketing_campaigns`',
        'DROP TABLE IF EXISTS `newsletter_segment_members`',
        'DROP TABLE IF EXISTS `newsletter_segments`',
        'DROP TABLE IF EXISTS `newsletter_contacts`',
        'DROP TABLE IF EXISTS `marketing_post_categories`',
        'DROP TABLE IF EXISTS `marketing_categories`',
        'DROP TABLE IF EXISTS `marketing_posts`'
    ]
];
