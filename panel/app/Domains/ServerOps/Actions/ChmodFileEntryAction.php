<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;

class ChmodFileEntryAction
{
    public function __construct(
        private readonly FileManagerDriverInterface $driver,
    ) {}

    public function handle(SystemPath $path, int $octalMode): string
    {
        return $this->driver->chmodEntry($path, $octalMode);
    }
}

