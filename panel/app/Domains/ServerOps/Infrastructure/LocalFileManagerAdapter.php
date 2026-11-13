<?php

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\FileManagerDriverInterface;
use App\Domains\ServerOps\ValueObjects\SystemPath;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class LocalFileManagerAdapter implements FileManagerDriverInterface
{
    public function listDirectory(SystemPath $path): array
    {
        if (! $path->isDir()) {
            throw new InvalidArgumentException('Target path is not a directory.');
        }

        $items = [];
        $files = scandir($path->fullPath);
        if ($files === false) {
            throw new RuntimeException('Failed to scan directory.');
        }

        $baseSubpath = $path->subpath;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $path->fullPath . DIRECTORY_SEPARATOR . $file;
            $isDir = is_dir($filePath);
            $size = $isDir ? 0 : (@filesize($filePath) ?: 0);
            $perms = substr(sprintf('%o', @fileperms($filePath) ?: 0), -4);
            $mtime = @filemtime($filePath) ? date('Y-m-d H:i:s', filemtime($filePath)) : '';

            $items[] = [
                'name' => $file,
                'path' => trim($baseSubpath . '/' . $file, '/'),
                'is_dir' => $isDir,
                'size' => $size,
                'size_human' => $this->formatBytes($size),
                'permissions' => $perms,
                'modified_at' => $mtime,
                'extension' => $isDir ? '' : strtolower(pathinfo($file, PATHINFO_EXTENSION)),
            ];
        }

        usort($items, function (array $a, array $b): int {
            if ($a['is_dir'] === $b['is_dir']) {
                return strcasecmp($a['name'], $b['name']);
            }

            return $a['is_dir'] ? -1 : 1;
        });

        return $items;
    }

    public function readFile(SystemPath $path, int $maxBytes = 5242880): array
    {
        if (! $path->isFile()) {
            throw new InvalidArgumentException('File not found.');
        }

        $size = filesize($path->fullPath);
        if ($size === false) {
            throw new RuntimeException('Unable to determine file size.');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('File is too large to open in web editor (> 5MB).');
        }

        $content = file_get_contents($path->fullPath);
        if ($content === false) {
            throw new RuntimeException('Failed to read file.');
        }

        return [
            'content' => $content,
            'size' => $size,
        ];
    }

    public function writeFile(SystemPath $path, string $content): void
    {
        if (! $path->isFile()) {
            throw new InvalidArgumentException('File not found.');
        }

        if (file_put_contents($path->fullPath, $content) === false) {
            throw new RuntimeException('Failed to write file.');
        }
    }

    public function createEntry(SystemPath $path, string $type): void
    {
        if ($path->exists()) {
            throw new InvalidArgumentException('Item already exists.');
        }

        if ($type === 'folder') {
            File::makeDirectory($path->fullPath, 0755, true);
            return;
        }

        if (file_put_contents($path->fullPath, '') === false) {
            throw new RuntimeException('Failed to create file.');
        }
    }

    public function deleteEntry(SystemPath $path): void
    {
        if (! $path->exists()) {
            throw new InvalidArgumentException('Target not found.');
        }

        if ($path->isDir()) {
            File::deleteDirectory($path->fullPath);
            return;
        }

        if (! @unlink($path->fullPath)) {
            throw new RuntimeException('Failed to delete file.');
        }
    }

    public function chmodEntry(SystemPath $path, int $octalMode): string
    {
        if (! $path->exists()) {
            throw new InvalidArgumentException('Target file or directory not found.');
        }

        if (! @chmod($path->fullPath, $octalMode)) {
            throw new RuntimeException('Failed to change permissions.');
        }

        return substr(sprintf('%o', @fileperms($path->fullPath) ?: 0), -4);
    }

    public function ensureDirectory(SystemPath $path): void
    {
        if (! $path->exists()) {
            @File::makeDirectory($path->fullPath, 0755, true);
        }
    }

    private function formatBytes(int $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

