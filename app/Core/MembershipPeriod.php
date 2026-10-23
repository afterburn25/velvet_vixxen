<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Calendar months and fixed days remain distinct commercial units. */
final class MembershipPeriod
{
    public static function validate(string $interval, mixed $count): array
    {
        if (!in_array($interval, ['month', 'year', 'one_time', 'day'], true)
            || (!is_int($count) && (!is_string($count) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $count) !== 1))
            || (int) $count < 1 || (int) $count > 120) {
            throw new RuntimeException('Choose a supported billing interval and a whole interval count from 1 to 120.');
        }
        return ['interval' => $interval, 'count' => (int) $count];
    }

    public static function label(string $interval, int $count): string
    {
        self::validate($interval, $count);
        return $interval === 'one_time' ? 'one time' : 'every ' . $count . ' ' . $interval . ($count === 1 ? '' : 's');
    }
}
