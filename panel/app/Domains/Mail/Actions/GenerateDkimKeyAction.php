<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Contracts\MailServerDriverInterface;
use App\Domains\Mail\Jobs\ReconcileMailServerJob;
use App\Domains\Mail\Models\MailDkimKey;
use App\Domains\Mail\Models\MailDomain;
use App\Domains\Mail\Queries\RequiredMailDnsRecordsQuery;
use App\Domains\Mail\ValueObjects\DkimSelector;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use App\Domains\Shared\Events\MailDnsRecordsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GenerateDkimKeyAction
{
    private const ALLOWED_ALGORITHMS = ['rsa2048', 'ed25519'];

    public function __construct(
        private readonly MailServerDriverInterface $driver,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly RequiredMailDnsRecordsQuery $dnsRecordsQuery,
    ) {}

    public function execute(
        User $actor,
        MailDomain $mailDomain,
        DkimSelector $selector,
        string $algorithm = 'rsa2048',
    ): MailDkimKey {
        $algorithm = strtolower(trim($algorithm));
        if (! in_array($algorithm, self::ALLOWED_ALGORITHMS, true)) {
            throw ValidationException::withMessages([
                'algorithm' => "Unsupported DKIM algorithm '{$algorithm}'. Allowed: rsa2048, ed25519.",
            ]);
        }

        if (MailDkimKey::where('mail_domain_id', $mailDomain->id)
            ->where('selector', $selector->value)
            ->exists()) {
            throw ValidationException::withMessages([
                'selector' => "DKIM selector '{$selector->value}' already exists for {$mailDomain->domain}.",
            ]);
        }

        $pendingKey = MailDkimKey::create([
            'mail_domain_id' => $mailDomain->id,
            'selector' => $selector->value,
            'algorithm' => $algorithm,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        try {
            $generated = $this->driver->generateDkimKey(
                $mailDomain->domain,
                $selector->value,
                $algorithm,
            );
        } catch (Throwable $e) {
            $pendingKey->delete();

            throw $e;
        }

        try {
            $activeKey = DB::transaction(function () use ($actor, $mailDomain, $pendingKey, $algorithm, $generated): MailDkimKey {
                MailDkimKey::where('mail_domain_id', $mailDomain->id)
                    ->where('algorithm', $algorithm)
                    ->where('status', 'active')
                    ->where('id', '!=', $pendingKey->id)
                    ->update([
                        'status' => 'retired',
                        'rotated_at' => now(),
                    ]);

                $pendingKey->update([
                    'public_key_txt' => (string) ($generated['public_key_txt'] ?? ''),
                    'private_key_path' => (string) ($generated['private_key_path'] ?? ''),
                    'key_sha256' => (string) ($generated['key_sha256'] ?? ''),
                    'status' => 'active',
                ]);

                if (! $mailDomain->dkim_enabled) {
                    $mailDomain->update(['dkim_enabled' => true]);
                }

                $this->auditLogger->log([
                    'user_id' => $actor->id,
                    'action' => 'mail.dkim.generated',
                    'target_type' => MailDkimKey::class,
                    'target_id' => $pendingKey->id,
                    'metadata' => [
                        'domain' => $mailDomain->domain,
                        'selector' => $pendingKey->selector,
                        'algorithm' => $algorithm,
                        'key_sha256' => $pendingKey->key_sha256,
                    ],
                ]);

                return $pendingKey->refresh();
            });
        } catch (Throwable $e) {
            try {
                $this->driver->removeDkimKey($mailDomain->domain, $selector->value);
            } catch (Throwable $cleanupException) {
                Log::error('Failed compensating DKIM key removal after DB failure', [
                    'domain' => $mailDomain->domain,
                    'selector' => $selector->value,
                    'error' => $cleanupException->getMessage(),
                ]);
            }

            $pendingKey->delete();

            throw $e;
        }

        ReconcileMailServerJob::dispatch('dkim');

        $records = $this->dnsRecordsQuery->execute($mailDomain, activeDkimKey: $activeKey);
        MailDnsRecordsChanged::dispatch($mailDomain->domain, $records);

        return $activeKey;
    }
}
