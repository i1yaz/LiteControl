<?php

namespace App\Domains\ServerOps\Contracts;

interface LogReaderInterface
{
    /**
     * Efficiently read a page of log lines in reverse (newest first).
     *
     * @return array{lines: array<int, string>, has_next: bool, has_prev: bool, page: int, per_page: int, file_exists: bool}
     */
    public function readLogPage(string $filePath, int $page = 1, int $perPage = 50): array;
}

