<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Security\Actions\BuildImunifyIntegrationDataAction;
use Illuminate\Console\Command;

class ImunifyIntegrationDataCommand extends Command
{
    protected $signature = 'imunify:integration-data {section : Section to output (panel_info|admins|users|domains|packages|resellers|db_info)}';

    protected $description = 'Output CloudLinux ImunifyAV/Imunify360 integration contract JSON for the requested section';

    public function handle(BuildImunifyIntegrationDataAction $action): int
    {
        $section = (string) $this->argument('section');

        try {
            $payload = $action->handle($section);
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $this->line((string) json_encode([
                'data' => null,
                'metadata' => [
                    'result' => $e->getMessage(),
                ],
            ], JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
