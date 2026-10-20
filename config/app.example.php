<?php
declare(strict_types=1);

return [
    'installed' => false,
    'installation_id' => '',
    'app_name' => 'Nikki Wilder — The Velvet Vixxen',
    'version' => trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')),
    'base_url' => '',
    'timezone' => 'America/Chicago',
    'currency' => 'USD',
    'support_email' => '',
    'storage_mode' => 'mysql',
    'file_store_path' => 'storage/data.json',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'mode' => 'disabled',
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Nikki Wilder',
    ],
    'security' => [
        'app_key' => '',
        'signing_key' => '',
        'media_key' => '',
    ],
    'paths' => [
        'storage' => 'storage',
        'private' => 'storage/private',
        'uploads' => 'uploads',
        'images' => 'images',
        'addons' => 'addons',
        'templates' => 'templates',
    ],
    'canva' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
    ],
];
