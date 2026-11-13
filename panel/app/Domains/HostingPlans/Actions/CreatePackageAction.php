<?php

namespace App\Domains\HostingPlans\Actions;

use App\Domains\HostingPlans\ValueObjects\PackageName;
use App\Domains\HostingPlans\ValueObjects\ResourceQuotas;
use App\Domains\PanelConfig\Models\AuditLog;
use App\Models\Package;

class CreatePackageAction
{
    public function handle(
        PackageName $name,
        ResourceQuotas $quotas,
        ?int $actorId,
        ?string $ipAddress,
    ): Package {
        $attributes = array_merge(['name' => $name->value], $quotas->toArray());

        $package = Package::create($attributes);

        AuditLog::create([
            'user_id' => $actorId,
            'action' => 'package.create',
            'command' => "package add {$package->name}",
            'status' => 'success',
            'ip_address' => $ipAddress,
            'details' => $attributes,
        ]);

        return $package;
    }
}

