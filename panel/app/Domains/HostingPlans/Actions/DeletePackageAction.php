<?php

namespace App\Domains\HostingPlans\Actions;

use App\Models\AuditLog;
use App\Models\Package;
use DomainException;

class DeletePackageAction
{
    public function handle(
        Package $package,
        ?int $actorId,
        ?string $ipAddress,
    ): string {
        if ($package->users()->count() > 0) {
            throw new DomainException("Cannot delete package '{$package->name}' because it has active users assigned.");
        }

        $name = $package->name;
        $package->delete();

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'package.delete',
            'command' => "package delete {$name}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => ['name' => $name],
        ]);

        return $name;
    }
}

