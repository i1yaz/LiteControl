<?php

namespace Database\Seeders;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPackage = Package::firstOrCreate(
            ['name' => 'Default'],
            [
                'disk_quota_mb' => 2000,
                'bandwidth_mb' => 20000,
                'max_domains' => 2,
                'max_subdomains' => 5,
                'max_databases' => 5,
                'max_ftp_accounts' => 5,
                'max_email_accounts' => 10,
            ]
        );

        $unlimitedPackage = Package::firstOrCreate(
            ['name' => 'Unlimited'],
            [
                'disk_quota_mb' => 0,
                'bandwidth_mb' => 0,
                'max_domains' => 0,
                'max_subdomains' => 0,
                'max_databases' => 0,
                'max_ftp_accounts' => 0,
                'max_email_accounts' => 0,
            ]
        );

        // Administrator (WHM role) - only user created during installation
        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'System Administrator',
                'email' => 'admin@litecontrol.local',
                'password' => Hash::make('admin123456'),
                'role' => 'admin',
                'package_id' => $unlimitedPackage->id,
                'status' => 'active',
                'system_uid' => 0,
                'home_directory' => '/root',
            ]
        );
    }
}
