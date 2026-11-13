<?php

namespace App\Domains\PanelConfig\Contracts;

use App\Exceptions\System\LcCliException;

interface TuningDriverInterface
{
    /**
     * Retrieve OpenLiteSpeed tuning configuration.
     *
     * @return array<string, mixed>
     */
    public function getTuning(): array;

    /**
     * Update OpenLiteSpeed tuning parameters.
     *
     * @param  array<string, mixed>  $settings  Key-value pairs of tuning directives
     * @return array<string, mixed>
     *
     * @throws LcCliException
     */
    public function updateTuning(array $settings): array;
}

