<?php
declare(strict_types=1);

namespace App\Core;

final class MediaAccess
{
    public const MODES = [
        'public',
        'registered',
        'subscription',
        'ppv',
        'rental',
        'bundle',
        'complimentary',
        'unlisted',
    ];

    public static function normalize(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return in_array($mode, self::MODES, true) ? $mode : 'public';
    }

    public static function label(string $mode): string
    {
        return match (self::normalize($mode)) {
            'registered' => 'Free members',
            'subscription' => 'Subscription',
            'ppv' => 'Pay per view',
            'rental' => 'Rental',
            'bundle' => 'Bundle',
            'complimentary' => 'Complimentary',
            'unlisted' => 'Unlisted',
            default => 'Public',
        };
    }

    public static function isPublic(string $mode): bool
    {
        return self::normalize($mode) === 'public';
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::MODES as $mode) {
            $options[$mode] = self::label($mode);
        }
        return $options;
    }
}
