<?php

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\LogReaderInterface;

class LocalLogReaderAdapter implements LogReaderInterface
{
    /**
     * Efficiently read a page of log lines in reverse (newest first) using fseek chunking.
     * Memory-safe: avoids loading the entire file into memory.
     */
    public function readLogPage(string $filePath, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            return [
                'lines' => [],
                'has_next' => false,
                'has_prev' => false,
                'page' => $page,
                'per_page' => $perPage,
                'file_exists' => false,
            ];
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return [
                'lines' => [],
                'has_next' => false,
                'has_prev' => false,
                'page' => $page,
                'per_page' => $perPage,
                'file_exists' => true,
            ];
        }

        $handle = fopen($filePath, 'rb');
        if (! $handle) {
            return [
                'lines' => [],
                'has_next' => false,
                'has_prev' => false,
                'page' => $page,
                'per_page' => $perPage,
                'file_exists' => true,
            ];
        }

        $targetSkip = ($page - 1) * $perPage;
        $targetCollect = $perPage + 1;

        $chunkSize = 8192;
        $pos = $fileSize;
        $buffer = '';
        $collectedLines = [];

        while ($pos > 0 && count($collectedLines) < ($targetSkip + $targetCollect)) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);
            $chunk = fread($handle, $readSize);
            $buffer = $chunk.$buffer;

            $lines = explode("\n", $buffer);
            $buffer = ($pos > 0) ? array_shift($lines) : '';

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $line = trim($lines[$i], "\r\n");
                if ($line !== '') {
                    $collectedLines[] = $line;
                }
            }
        }

        if ($pos === 0 && trim($buffer) !== '') {
            $collectedLines[] = trim($buffer, "\r\n");
        }

        fclose($handle);

        $pagedSlice = array_slice($collectedLines, $targetSkip, $perPage);
        $hasNext = count($collectedLines) > ($targetSkip + $perPage);
        $hasPrev = $page > 1;

        return [
            'lines' => $pagedSlice,
            'has_next' => $hasNext,
            'has_prev' => $hasPrev,
            'page' => $page,
            'per_page' => $perPage,
            'file_exists' => true,
        ];
    }
}

