<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class BookingSubmissionLimiter
{
    public function __construct(private readonly string $root, private readonly string $key) {}

    public function consume(string $identity, int $limit = 20, int $window = 900): void
    {
        if ($identity === '' || strlen($this->key) < 32) throw new RuntimeException('Booking protection is temporarily unavailable.');
        $directory = $this->root . '/storage/private/booking-rate-limit';
        if (!\vvx_recovery_safe_path($this->root, $directory, true)
            || (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory))) {
            throw new RuntimeException('Booking protection is temporarily unavailable.');
        }
        $path = $directory . '/' . hash_hmac('sha256', $identity, $this->key) . '.json';
        if (!\vvx_recovery_safe_path($this->root, $path, true) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Booking protection is temporarily unavailable.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) throw new RuntimeException('Booking protection is temporarily unavailable.');
        @chmod($path, 0600);
        try {
            if (!flock($handle, LOCK_EX)) throw new RuntimeException('Booking protection is temporarily unavailable.');
            $record = json_decode(stream_get_contents($handle) ?: '[]', true);
            $now = time();
            $hits = array_values(array_filter(is_array($record) ? $record : [], static fn(mixed $hit): bool => is_int($hit) && $hit > $now - $window));
            if (count($hits) >= $limit) throw new RuntimeException('Too many booking attempts. Please wait 15 minutes before trying again.');
            $hits[] = $now;
            $payload = json_encode($hits, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new RuntimeException('Booking protection is temporarily unavailable.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
