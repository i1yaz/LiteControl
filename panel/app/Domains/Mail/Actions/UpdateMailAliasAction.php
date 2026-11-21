<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

final class UpdateMailAliasAction
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(User $actor, MailAlias $alias, bool $active): MailAlias
    {
        return DB::transaction(function () use ($actor, $alias, $active): MailAlias {
            $alias->update([
                'active' => $active,
            ]);

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.alias.updated',
                'target_type' => MailAlias::class,
                'target_id' => $alias->id,
                'metadata' => [
                    'source_email' => $alias->source_email,
                    'destination_email' => $alias->destination_email,
                    'active' => $active,
                ],
            ]);

            return $alias->fresh();
        });
    }
}
