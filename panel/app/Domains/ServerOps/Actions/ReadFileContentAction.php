<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;

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
