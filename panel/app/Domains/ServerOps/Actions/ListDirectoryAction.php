<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;

class ListDirectoryAction
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
