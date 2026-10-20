<?php
declare(strict_types=1);

return [
    'id' => '202610180005_media_center',
    'description' => 'Add audio and video playlist tables for the Media Center.',
    'up' => [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS audio_playlists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    cover_asset_id BIGINT UNSIGNED NULL,
    status ENUM('draft','scheduled','published','archived','unlisted') NOT NULL DEFAULT 'draft',
    access_mode VARCHAR(40) NOT NULL DEFAULT 'public',
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_audio_playlists_status (status, published_at),
    CONSTRAINT fk_audio_playlists_cover FOREIGN KEY (cover_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS audio_playlist_items (
    playlist_id BIGINT UNSIGNED NOT NULL,
    track_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (playlist_id, track_id),
    INDEX idx_audio_playlist_order (playlist_id, sort_order),
    CONSTRAINT fk_audio_playlist_items_playlist FOREIGN KEY (playlist_id) REFERENCES audio_playlists(id) ON DELETE CASCADE,
    CONSTRAINT fk_audio_playlist_items_track FOREIGN KEY (track_id) REFERENCES audio_tracks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS video_playlists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL UNIQUE,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    cover_asset_id BIGINT UNSIGNED NULL,
    status ENUM('draft','scheduled','published','archived','unlisted') NOT NULL DEFAULT 'draft',
    access_mode VARCHAR(40) NOT NULL DEFAULT 'public',
    published_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_video_playlists_status (status, published_at),
    CONSTRAINT fk_video_playlists_cover FOREIGN KEY (cover_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS video_playlist_items (
    playlist_id BIGINT UNSIGNED NOT NULL,
    video_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (playlist_id, video_id),
    INDEX idx_video_playlist_order (playlist_id, sort_order),
    CONSTRAINT fk_video_playlist_items_playlist FOREIGN KEY (playlist_id) REFERENCES video_playlists(id) ON DELETE CASCADE,
    CONSTRAINT fk_video_playlist_items_video FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ],
    'down' => [
        'DROP TABLE IF EXISTS video_playlist_items',
        'DROP TABLE IF EXISTS video_playlists',
        'DROP TABLE IF EXISTS audio_playlist_items',
        'DROP TABLE IF EXISTS audio_playlists',
    ],
];
