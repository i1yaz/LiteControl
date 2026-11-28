<?php

namespace App\Domains\ServerOps\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailFilterDriverInterface;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Contracts\WebmailDriverInterface;
use App\Domains\ServerOps\Contracts\ModuleDriverInterface;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Exceptions\System\LcCliException;
use Illuminate\Support\Facades\Cache;

class RemoveModuleAction
{
    public function __construct(
        protected ModuleDriverInterface $moduleDriver,
        protected ?AuditLoggerInterface $auditLogger = null
    ) {}

    /**
     * Remove / uninstall an optional module.
     *
     * @throws LcCliException
     */
    public function handle(string $module, User $actor, ?string $ipAddress = null, bool $purgeData = false): array
    {
        if ($module === 'mail_core') {
            $result = app(MailServerDriverInterface::class)->uninstallCore($purgeData);
        } elseif (in_array($module, ['mail_filter', 'clamav'], true)) {
            $result = app(MailFilterDriverInterface::class)->demoteToLight();
        } elseif ($module === 'mail_webmail') {
            $result = app(WebmailDriverInterface::class)->uninstall();
            Cache::forget('mail_webmail_hostname');
        } else {
            $result = $this->moduleDriver->removeModule($module);
        }

        Cache::forget("module_install_{$module}");
        Cache::forget("module_install_{$module}_error");
        if ($module === 'mail_core') {
            Cache::forget('mail_core_hostname');
            Cache::forget('module_install_mail_filter');
            Cache::forget('module_install_mail_filter_error');
            Cache::forget('module_install_clamav');
            Cache::forget('module_install_clamav_error');
        } elseif ($module === 'mail_filter') {
            Cache::forget('module_install_clamav');
            Cache::forget('module_install_clamav_error');
        }

        ($this->auditLogger ?? app(AuditLoggerInterface::class))->log([
            'user_id' => $actor->id,
            'action' => 'remove_module',
            'command' => "lc-cli module remove {$module}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => json_encode(array_filter([
                'module' => $module,
                'purge_data' => $purgeData ? true : null,
            ])),
        ]);

        return $result;
    }
}
