<?php

namespace App\Domains\FileManager\Infrastructure;

use App\Domains\FileManager\Contracts\FileManagerDriverInterface;
use App\Domains\Shared\ValueObjects\SystemPath;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class CliFileManagerAdapter implements FileManagerDriverInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function listDirectory(SystemPath $path): array
    {
        $args = ['list', $path->fullPath];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to list directory.', 'filemanager', $args, $res);
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function readFile(SystemPath $path, int $maxBytes = 5242880): array
    {
        $args = ['read', $path->fullPath, (string) $maxBytes];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to read file.', 'filemanager', $args, $res);
        }

        return [
            'content' => (string) ($res['data']['content'] ?? ''),
            'size' => (int) ($res['data']['size'] ?? 0),
        ];
    }

    public function writeFile(SystemPath $path, string $content): void
    {
        $args = ['write', $path->fullPath, base64_encode($content)];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to write file.', 'filemanager', $args, $res);
        }
    }

    public function createEntry(SystemPath $path, string $type): void
    {
        $args = ['create', $path->fullPath, $type];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to create entry.', 'filemanager', $args, $res);
        }
    }

    public function deleteEntry(SystemPath $path): void
    {
        $args = ['delete', $path->fullPath];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to delete entry.', 'filemanager', $args, $res);
        }
    }

    public function chmodEntry(SystemPath $path, int $octalMode): string
    {
        $mode = sprintf('%04o', $octalMode);
        $args = ['chmod', $path->fullPath, $mode];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to change permissions.', 'filemanager', $args, $res);
        }

        return (string) ($res['data']['permissions'] ?? $mode);
    }

    public function ensureDirectory(SystemPath $path): void
    {
        $args = ['mkdir', $path->fullPath];
        $res = $this->lcCli->run('filemanager', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException($res['message'] ?? 'Failed to ensure directory.', 'filemanager', $args, $res);
        }
    }
}
