<?php
declare(strict_types=1);

namespace App\Core;

/** Transport outcome, not a claim that a recipient received an email. */
final class MailDeliveryException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $uncertain = false,
        public readonly bool $retryable = true,
        public readonly bool $canceled = false,
    ) {
        parent::__construct($message);
    }
}
