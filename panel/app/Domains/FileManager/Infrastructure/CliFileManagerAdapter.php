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

        $rawItems = is_array($res['data']['items'] ?? null)
            ? $res['data']['items']
            : (is_array($res['data'] ?? null) ? $res['data'] : []);

        $baseSubpath = trim($path->subpath, '/');
        $items = [];

        foreach ($rawItems as $item) {
            if (! is_array($item) || ! isset($item['name'])) {
                continue;
            }

            $name = (string) $item['name'];
            $isDir = ($item['type'] ?? '') === 'dir' || (bool) ($item['is_dir'] ?? false);
            $size = (int) ($item['size'] ?? 0);
            $relPath = $baseSubpath !== '' ? "{$baseSubpath}/{$name}" : $name;

            $items[] = [
                'name' => $name,
                'path' => $relPath,
                'is_dir' => $isDir,
                'size' => $size,
                'size_human' => $isDir ? '—' : $this->formatBytes($size),
                'extension' => $isDir ? '' : strtolower((string) pathinfo($name, PATHINFO_EXTENSION)),
                'permissions' => (string) ($item['permissions'] ?? ($isDir ? '0755' : '0644')),
                'modified_at' => (string) ($item['modified_at'] ?? ''),
            ];
        }

        return $items;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), 2).' '.$units[$power];
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
