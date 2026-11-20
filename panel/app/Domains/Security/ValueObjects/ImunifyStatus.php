<?php

declare(strict_types=1);

namespace App\Domains\Security\ValueObjects;

final readonly class ImunifyStatus
{
    public bool $installed;

    public string $status;

    public bool $installing;

    public function __construct(
        bool $rawInstalled,
        string $rawStatus,
        ?string $queueState = null,
        public string $uiUrl = '/imunifyav/',
        public string $pamService = 'common-auth',
        public ?string $installLog = null,
        public ?string $error = null,
    ) {
        $normalizedStatus = strtolower(trim($rawStatus ?: 'not_installed'));

        $this->installing = in_array($queueState, ['pending', 'provisioning'], true);

        if ($this->installing) {
            $this->status = 'installing';
            $this->installed = $rawInstalled && $normalizedStatus !== 'not_installed';

            return;
        }

        if ($queueState === 'failed' && (! $rawInstalled || $normalizedStatus === 'not_installed')) {
            $this->status = 'failed';
            $this->installed = false;

            return;
        }

        $this->installed = $rawInstalled && $normalizedStatus !== 'not_installed';
        $this->status = $this->installed ? $normalizedStatus : 'not_installed';
    }

    /**
     * @param  array<string, mixed>  $cliData
     */
    public static function fromCliData(
        array $cliData,
        ?string $queueState = null,
        ?string $installLog = null,
        ?string $error = null,
    ): self {
        $rawInstalled = ! empty($cliData['installed']);
        $rawStatus = (string) ($cliData['status'] ?? $cliData['state'] ?? 'not_installed');
        $pamService = (string) ($cliData['pam_service'] ?? (is_file('/etc/pam.d/system-auth') ? 'system-auth' : 'common-auth'));

        return new self(
            rawInstalled: $rawInstalled,
            rawStatus: $rawStatus,
            queueState: $queueState,
            uiUrl: '/imunifyav/',
            pamService: $pamService,
            installLog: $installLog,
            error: $error,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'installed' => $this->installed,
            'status' => $this->status,
            'installing' => $this->installing,
            'ui_url' => $this->uiUrl,
            'pam_service' => $this->pamService,
            'install_log' => $this->installLog,
            'error' => $this->error,
        ];
    }
}
