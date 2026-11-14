<?php

namespace App\Domains\HostingPlans\Actions;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\HostingPlans\ValueObjects\PackageName;
use App\Domains\HostingPlans\ValueObjects\ResourceQuotas;
use App\Domains\PanelConfig\Models\AuditLog;

class UpdatePackageAction
{
    public function handle(
        Package $package,
        PackageName $name,
        ResourceQuotas $quotas,
        ?int $actorId,
        ?string $ipAddress,
    ): Package {
        $attributes = array_merge(['name' => $name->value], $quotas->toArray());

        $package->update($attributes);

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'package.update',
            'command' => "package update {$package->name}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => $attributes,
        ]);

        return $package;
    }
}
