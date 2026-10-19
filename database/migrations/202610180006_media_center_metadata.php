<?php
declare(strict_types=1);

return [
    'id' => '202610180006_media_center_metadata',
    'description' => 'Add captions and Media Center metadata needed by the public players and admin tools.',
    'up' => [
        <<<'SQL'
ALTER TABLE videos
    ADD COLUMN caption_asset_id BIGINT UNSIGNED NULL AFTER full_video_asset_id,
    ADD INDEX idx_videos_caption (caption_asset_id),
    ADD CONSTRAINT fk_videos_caption FOREIGN KEY (caption_asset_id) REFERENCES media_assets(id) ON DELETE SET NULL
SQL,
    ],
    'down' => [
        <<<'SQL'
ALTER TABLE videos
    DROP FOREIGN KEY fk_videos_caption,
    DROP INDEX idx_videos_caption,
    DROP COLUMN caption_asset_id
SQL,
    ],
];
