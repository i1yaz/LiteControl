<?php

namespace App\Domains\WebServer\Contracts;

interface FtpDriverInterface
{
    public function ensureDirectory(string $path): void;

    /**
     * @return array{uid: int, gid: int}
     */
    public function resolveUidGid(string $username): array;
}
