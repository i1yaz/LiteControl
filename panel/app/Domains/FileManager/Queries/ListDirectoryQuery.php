<?php

namespace App\Domains\FileManager\Queries;

use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\Shared\ValueObjects\SystemPath;

class ListDirectoryQuery
{
    public function __construct(
        private readonly FileManagerDriverInterface $driver,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(SystemPath $path, bool $autoProvision = false): array
    {
        if ($autoProvision && ! $path->exists()) {
            $this->driver->ensureDirectory($path);
        }

        return $this->driver->listDirectory($path);
    }
}
