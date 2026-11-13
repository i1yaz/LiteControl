<?php

namespace App\Services\System;

use App\Exceptions\System\LcCliException;
use App\Support\Cli\LcCliService;

class TuningCliService
{
    public function __construct(
        protected LcCliService $lcCli
    ) {}

    /**
     * Retrieve OpenLiteSpeed tuning configuration.
     *
     * @return array
     */
    public function getTuning(): array
    {
        $res = $this->lcCli->run('tuning', ['get']);

        if (($res['status'] ?? '') === 'success' && isset($res['data']) && is_array($res['data'])) {
            return $res['data'];
        }

        return [];
    }

    /**
     * Update OpenLiteSpeed tuning parameters.
     *
     * @param array<string, mixed> $settings Key-value pairs of tuning directives
     * @return array
     * @throws LcCliException
     */
    public function updateTuning(array $settings): array
    {
        $args = ['set'];
        foreach ($settings as $key => $val) {
            $args[] = "{$key}={$val}";
        }

        $res = $this->lcCli->run('tuning', $args);

        if (($res['status'] ?? '') !== 'success') {
            throw new LcCliException(
                $res['message'] ?? 'Failed to update tuning configuration.',
                'tuning',
                $args,
                $res
            );
        }

        return $res['data'] ?? [];
    }
}

