<?php

namespace App\Domains\FileManager\Actions;

use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\Shared\ValueObjects\SystemPath;

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
