<?php

namespace App\Actions\Configuration;

use App\Exceptions\System\LcCliException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\System\TuningCliService;

class UpdateTuningAction
{
    public function __construct(
        protected TuningCliService $tuningCli
    ) {}

    /**
     * Update OpenLiteSpeed tuning parameters.
     *
     * @param array<string, mixed> $validated
     * @param User|null $actor
     * @param string|null $ipAddress
     * @return array
     * @throws LcCliException
     */
    public function handle(array $validated, ?User $actor = null, ?string $ipAddress = null): array
    {
        $result = $this->tuningCli->updateTuning($validated);

        AuditLog::create([
            'user_id' => $actor?->id,
            'action' => 'update_tuning',
            'command' => 'lc-cli tuning set',
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode($validated),
        ]);

        return $result;
    }
}

