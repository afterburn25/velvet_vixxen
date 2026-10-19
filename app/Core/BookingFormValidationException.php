<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class BookingFormValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Please correct the highlighted fields and submit again.');
    }
}
