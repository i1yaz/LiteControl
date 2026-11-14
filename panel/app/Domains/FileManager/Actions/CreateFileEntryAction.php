<?php

namespace App\Domains\FileManager\Actions;

use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\Shared\ValueObjects\SystemPath;

class CreateFileEntryAction
{
    public function __construct(
        private readonly FileManagerDriverInterface $driver,
    ) {}

    public function handle(SystemPath $path, string $type): void
    {
        $this->driver->createEntry($path, $type);
    }
}
