<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;

class DeleteFileEntryAction
{
    public function __construct(
        private readonly FileManagerDriverInterface $driver,
    ) {}

    public function handle(SystemPath $path): void
    {
        $this->driver->deleteEntry($path);
    }
}
