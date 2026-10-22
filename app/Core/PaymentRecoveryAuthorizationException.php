<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/** Authorization failure is never a provider result or a financial diagnostic. */
final class PaymentRecoveryAuthorizationException extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        $code = $previous->getCode() === 428 ? 428 : 403;
        parent::__construct($code === 428 ? 'Confirm your owner credentials again before payment recovery.' : 'Current owner authorization is required for payment recovery.', $code, $previous);
    }
}
