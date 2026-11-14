<?php

namespace App\Domains\WebServer\Infrastructure;

use App\Domains\WebServer\Contracts\FtpDriverInterface;

class CliFtpAdapter implements FtpDriverInterface
{
    public function ensureDirectory(string $path): void
    {
        if (! file_exists($path)) {
            @mkdir($path, 0755, true);
        }
    }

    /**
     * @return array{uid: int, gid: int}
     */
    public function resolveUidGid(string $username): array
    {
        $uid = 5001;
        $gid = 5001;

        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam($username);
            if ($pw) {
                $uid = (int) $pw['uid'];
                $gid = (int) $pw['gid'];
            }
        }

        return ['uid' => $uid, 'gid' => $gid];
    }
}
