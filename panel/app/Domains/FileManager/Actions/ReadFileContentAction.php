<?php

namespace App\Domains\FileManager\Actions;

use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\Shared\ValueObjects\SystemPath;

class ReadFileContentAction
{
    public function __construct(
        private readonly FileManagerDriverInterface $driver,
    ) {}

    /**
     * @return array{content: string, size: int}
     */
    public function handle(SystemPath $path): array
    {
        return $this->driver->readFile($path);
    }
}
