<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

final class AddonMigrationException extends RuntimeException
{
    public function __construct(string $message, private readonly bool $recoveryRequired, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public function recoveryRequired(): bool
    {
        return $this->recoveryRequired;
    }
}
