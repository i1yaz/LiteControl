<?php

namespace App\Domains\Shared\ValueObjects;

use InvalidArgumentException;

final readonly class SystemPath
{
    public string $baseDir;

    public string $subpath;

    public string $fullPath;

    public function __construct(string $baseDir, string $subpath = '', bool $mustExist = false)
    {
        if (trim($baseDir) === '') {
            throw new InvalidArgumentException('Base directory cannot be empty.');
        }

        if (str_contains($subpath, "\0")) {
            throw new InvalidArgumentException('Path contains null bytes.');
        }

        $normalizedSubpath = str_replace('\\', '/', $subpath);
        $segments = explode('/', $normalizedSubpath);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Path traversal is not allowed.');
            }
        }

        $realBase = realpath($baseDir) ?: rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $baseDir), DIRECTORY_SEPARATOR);
        $this->baseDir = $realBase;

        $trimmed = trim($normalizedSubpath, '/');
        $this->subpath = $trimmed;

        $candidate = $trimmed === ''
            ? $realBase
            : $realBase.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $trimmed);

        if (file_exists($candidate)) {
            $realFull = realpath($candidate);
            if (! $realFull || ! $this->isWithinBase($realFull, $realBase)) {
                throw new InvalidArgumentException('Path escapes confined base directory.');
            }
            $this->fullPath = $realFull;

            return;
        }

        if ($mustExist) {
            throw new InvalidArgumentException("Target path does not exist: {$trimmed}");
        }

        if (is_link($candidate)) {
            throw new InvalidArgumentException('Path escapes confined base directory.');
        }

        if (is_dir($realBase)) {
            $ancestor = dirname($candidate);
            while ($ancestor !== $realBase && ! file_exists($ancestor) && $ancestor !== dirname($ancestor)) {
                $ancestor = dirname($ancestor);
            }

            $realAncestor = realpath($ancestor);
            if (! $realAncestor || ! $this->isWithinBase($realAncestor, $realBase)) {
                throw new InvalidArgumentException('Parent path escapes confined base directory.');
            }
        }

        $this->fullPath = $candidate;
    }

    private function isWithinBase(string $path, string $base): bool
    {
        return $path === $base || str_starts_with($path, $base.DIRECTORY_SEPARATOR);
    }

    public function exists(): bool
    {
        return file_exists($this->fullPath);
    }

    public function isDir(): bool
    {
        return is_dir($this->fullPath);
    }

    public function isFile(): bool
    {
        return is_file($this->fullPath);
    }

    public function __toString(): string
    {
        return $this->fullPath;
    }
}
