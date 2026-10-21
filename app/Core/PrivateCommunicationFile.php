<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** No public URL, traversal, symlink or unrelated private vault can become an attachment. */
final class PrivateCommunicationFile
{
    public static function path(string $root, string $stored): string
    {
        if (!str_starts_with($stored, 'private:communications/') || str_contains($stored, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $stored)) throw new RuntimeException('Attachment not found.', 404);
        $parts = explode('/', substr($stored, 8));
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) throw new RuntimeException('Attachment not found.', 404);
        $path = rtrim($root, '/') . '/storage';
        foreach (array_merge(['private'], $parts) as $part) {
            if (is_link($path) || !is_dir($path)) throw new RuntimeException('Attachment not found.', 404);
            $path .= '/' . $part;
        }
        if (is_link($path) || !is_file($path)) throw new RuntimeException('Attachment not found.', 404);
        return $path;
    }

    /** @return resource The checked descriptor is the one the caller must read. */
    public static function open(string $root, array $attachment)
    {
        $path = self::path($root, (string) ($attachment['filePath'] ?? ''));
        $before = lstat($path); $handle = @fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Attachment not found.', 404);
        $opened = fstat($handle); clearstatcache(true, $path); $after = lstat($path);
        if (!is_array($before) || !is_array($opened) || !is_array($after) || is_link($path)
            || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']
            || $after['dev'] !== $opened['dev'] || $after['ino'] !== $opened['ino']
            || ($opened['mode'] & 0170000) !== 0100000
            || (int) ($attachment['fileSize'] ?? -1) !== (int) $opened['size']) {
            fclose($handle); throw new RuntimeException('Attachment changed. Ask the owner to review it.', 409);
        }
        return $handle;
    }
}
