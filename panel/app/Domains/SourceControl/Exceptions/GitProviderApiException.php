<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Exceptions;

use RuntimeException;

class GitProviderApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly bool $reauthRequired = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
