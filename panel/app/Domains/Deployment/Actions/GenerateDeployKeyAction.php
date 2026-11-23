<?php

declare(strict_types=1);

namespace App\Domains\Deployment\Actions;

use App\Domains\Deployment\Models\DomainDeploymentConfig;
use App\Domains\WebServer\Models\Domain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateDeployKeyAction
{
    public function execute(Domain $domain): DomainDeploymentConfig
    {
        $config = DomainDeploymentConfig::firstOrCreate(
            ['domain_id' => $domain->id],
            ['webhook_token' => Str::random(40)],
        );

        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lc_deploy_key_'.Str::random(16);
        File::ensureDirectoryExists($tmpDir, 0700);
        $keyPath = $tmpDir.DIRECTORY_SEPARATOR.'id_ed25519';

        try {
            $result = Process::timeout(15)->run([
                'ssh-keygen',
                '-t', 'ed25519',
                '-N', '',
                '-C', "litecontrol-deploy@{$domain->domain}",
                '-f', $keyPath,
            ]);

            if (! $result->successful()) {
                throw new RuntimeException(
                    'Failed to generate SSH deploy key: '.trim($result->errorOutput() ?: $result->output())
                );
            }

            $privateKey = trim(File::get($keyPath));
            $publicKey = trim(File::get($keyPath.'.pub'));

            $config->update([
                'deploy_key_public' => $publicKey,
                'deploy_key_private_encrypted' => $privateKey,
            ]);

            return $config->refresh();
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }
}
