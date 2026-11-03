<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Admin;

/**
 * A write that must be refused with a specific HTTP status and machine code
 * (validation error, conflict, not found) rather than a generic 500.
 */
class WriteException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status = 400
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** Named oddly because \Exception::getCode() is final and returns int. */
    public function getCode_(): string
    {
        return $this->errorCode;
    }
}
