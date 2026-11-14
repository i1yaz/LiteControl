<?php

namespace App\Domains\ServerOps\Infrastructure;

use App\Domains\ServerOps\Contracts\LogReaderInterface;
use App\Support\Cli\LcCliService;

class CliLogReaderAdapter implements LogReaderInterface
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    public function readLogPage(string $filePath, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(200, $perPage));

        $res = $this->lcCli->run('logs', ['read', $filePath, (string) $page, (string) $perPage]);
        $data = (($res['status'] ?? '') === 'success' && is_array($res['data'] ?? null)) ? $res['data'] : [];

        return [
            'lines' => is_array($data['lines'] ?? null) ? $data['lines'] : [],
            'has_next' => (bool) ($data['has_next'] ?? false),
            'has_prev' => (bool) ($data['has_prev'] ?? false),
            'page' => (int) ($data['page'] ?? $page),
            'per_page' => (int) ($data['per_page'] ?? $perPage),
            'file_exists' => (bool) ($data['file_exists'] ?? false),
        ];
    }
}
