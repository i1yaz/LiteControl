<?php

declare(strict_types=1);

namespace App\Domains\Dns\Exceptions;

use RuntimeException;

final class SecondaryZoneReadOnlyException extends RuntimeException
{
    public static function forRecords(string $canonicalZoneId): self
    {
        return new self(
            sprintf('Zone "%s" is configured as a Secondary (read-only) zone. Local record edits are disabled.', $canonicalZoneId)
        );
    }

    public static function forSoa(string $canonicalZoneId): self
    {
        return new self(
            sprintf('Zone "%s" is configured as a Secondary (read-only) zone. SOA settings cannot be modified locally.', $canonicalZoneId)
        );
    }
}
