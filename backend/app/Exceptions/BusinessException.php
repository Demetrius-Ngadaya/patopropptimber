<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A rule of the business was broken (not enough stock, credit limit reached ...).
 * Rendered to the client as a 422 with a human readable message.
 */
class BusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'BUSINESS_RULE',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
