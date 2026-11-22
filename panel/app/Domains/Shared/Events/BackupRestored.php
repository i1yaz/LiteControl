<?php

declare(strict_types=1);

namespace App\Domains\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class BackupRestored
{
    use Dispatchable;

    public function __construct(
        public readonly string $backupPath,
        public readonly ?int $backupId = null,
    ) {}
}
