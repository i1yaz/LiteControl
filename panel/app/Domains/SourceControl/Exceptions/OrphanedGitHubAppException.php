<?php

declare(strict_types=1);

namespace App\Domains\SourceControl\Exceptions;

use RuntimeException;

class OrphanedGitHubAppException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $appSlug,
        public readonly string $deletionUrl,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
