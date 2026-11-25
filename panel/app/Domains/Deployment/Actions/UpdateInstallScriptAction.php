<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\Deployment\Services\DeploymentPipelineService;

final class UpdateInstallScriptAction
{
    public function __construct(
        private readonly DeploymentPipelineService $pipelineService,
    ) {}

    public function execute(DomainDeploymentConfig $config, ?string $installScript): DomainDeploymentConfig
    {
        $normalized = $this->pipelineService->normalizeScript($installScript);

        $config->update([
            'install_script' => $normalized,
        ]);

        return $config->refresh();
    }
}
