<?php

namespace App\Domains\ServerOps\Contracts;

use App\Domains\ServerOps\ValueObjects\SystemPath;

interface FileManagerDriverInterface
{
    /**
     * List directory entries sorted with directories first, then files.
     *
     * @return array<int, array{
     *     name: string,
     *     path: string,
     *     is_dir: bool,
     *     size: int,
     *     size_human: string,
     *     permissions: string,
     *     modified_at: string,
     *     extension: string
     * }>
     */
    public function listDirectory(SystemPath $path): array;

    /**
     * Read file contents and size, enforcing a maximum byte limit.
     *
     * @return array{content: string, size: int}
     */
    public function readFile(SystemPath $path, int $maxBytes = 5242880): array;

    /**
     * Write content to an existing file.
     */
    public function writeFile(SystemPath $path, string $content): void;

    /**
     * Create a new file or folder at the given path.
     */
    public function createEntry(SystemPath $path, string $type): void;

    /**
     * Delete a file or directory recursively.
     */
    public function deleteEntry(SystemPath $path): void;

    /**
     * Change permissions (chmod) of a file or directory and return the new 4-digit octal string.
     */
    public function chmodEntry(SystemPath $path, int $octalMode): string;

    /**
     * Ensure a directory exists at the given path.
     */
    public function ensureDirectory(SystemPath $path): void;
}

