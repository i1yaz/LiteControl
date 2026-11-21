<?php

declare(strict_types=1);

namespace App\Domains\Mail\Actions;

use App\Domains\Identity\Models\User;
use App\Domains\Mail\Models\MailAlias;
use App\Domains\Shared\Contracts\AuditLoggerInterface;
use Illuminate\Support\Facades\DB;

final class DeleteMailAliasAction
{
    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public function execute(User $actor, MailAlias $alias): void
    {
        $aliasId = $alias->id;
        $sourceEmail = $alias->source_email;
        $destinationEmail = $alias->destination_email;

        DB::transaction(function () use ($actor, $alias, $aliasId, $sourceEmail, $destinationEmail): void {
            $alias->delete();

            $this->auditLogger->log([
                'user_id' => $actor->id,
                'action' => 'mail.alias.deleted',
                'target_type' => MailAlias::class,
                'target_id' => $aliasId,
                'metadata' => [
                    'source_email' => $sourceEmail,
                    'destination_email' => $destinationEmail,
                ],
            ]);
        });
    }
}
