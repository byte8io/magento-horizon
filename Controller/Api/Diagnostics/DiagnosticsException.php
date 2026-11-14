<?php

declare(strict_types=1);

namespace Byte8\Horizon\Controller\Api\Diagnostics;

/**
 * A refusal the diagnostics endpoints answer with a specific HTTP status and
 * machine code (`{"error": message, "code": CODE}`).
 */
class DiagnosticsException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
