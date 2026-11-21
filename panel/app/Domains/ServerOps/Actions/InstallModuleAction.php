<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Jobs\InstallMailCoreJob;
use App\Domains\Mail\Jobs\InstallMailFilterJob;
use App\Domains\Mail\Jobs\InstallWebmailJob;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\ServerOps\Jobs\InstallModuleJob;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\Cache;

class InstallModuleAction
{
    public function __construct(
        protected ModuleDriverInterface $moduleDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Queue an optional module installation.
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null, ?string $hostname = null): array
    {
        Cache::put("module_install_{$module}", 'pending', now()->addMinutes(30));
        Cache::put('module_install_current', $module, now()->addMinutes(30));
        Cache::forget("module_install_{$module}_error");

        $cleanHostname = is_string($hostname) ? trim($hostname) : '';

        if ($module === 'mail_webmail' && $cleanHostname !== '') {
            Cache::forever('mail_webmail_hostname', $cleanHostname);
        }

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'install_module',
            'command' => "lc-cli module install {$module}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(array_filter([
                'module' => $module,
                'hostname' => $cleanHostname !== '' ? $cleanHostname : null,
            ])),
        ]);

        if ($module === 'mail_core') {
            InstallMailCoreJob::dispatch($cleanHostname);
        } elseif ($module === 'mail_filter') {
            InstallMailFilterJob::dispatch('mail_filter', false);
        } elseif ($module === 'clamav') {
            InstallMailFilterJob::dispatch('clamav', true);
        } elseif ($module === 'mail_webmail') {
            InstallWebmailJob::dispatch($cleanHostname);
        } else {
            InstallModuleJob::dispatch($module);
        }

        return ['status' => 'queued', 'module' => $module];
    }
}
