<?php

declare(strict_types=1);

namespace App\Domains\Dns\Infrastructure;

use App\Domains\Dns\Contracts\PdnsDaemonDriverInterface;
use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

final class CliPdnsDaemonAdapter implements PdnsDaemonDriverInterface
{
    public function __construct(
        private readonly LcCliService $lcCli,
    ) {}

    public function isMockMode(): bool
    {
        return $this->lcCli->isMockMode();
    }

    public function getStatus(): array
    {
        $args = ['status'];
        $res = $this->lcCli->run('pdns', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException(
                $res['message'] ?? 'Failed to retrieve PowerDNS daemon status.',
                'pdns',
                $args,
                $res
            );
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function applyConfig(array $payload): array
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $args = ['apply-config', '--payload', $encoded];
        $res = $this->lcCli->run('pdns', $args);

        if (($res['status'] ?? '') !== 'success') {
            $redactedArgs = ['apply-config', '--payload', '[REDACTED]'];

            throw new LcCliException(
                $res['message'] ?? 'Failed to validate and apply PowerDNS daemon configuration.',
                'pdns',
                $redactedArgs,
                $res
            );
        }

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }
}
