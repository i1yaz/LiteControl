<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use App\Models\Domain;
use App\Models\Database as DbModel;
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

        // Administrator (WHM role)
        $admin = User::firstOrCreate(
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

        // Standard Hosting Customer
        $client = User::firstOrCreate(
            ['username' => 'client1'],
            [
                'name' => 'John Doe',
                'email' => 'client1@example.com',
                'password' => Hash::make('client123456'),
                'role' => 'user',
                'package_id' => $defaultPackage->id,
                'status' => 'active',
                'system_uid' => 1001,
                'home_directory' => '/home/client1',
            ]
        );

        // Primary Domain for Client
        Domain::firstOrCreate(
            ['domain' => 'client1.com'],
            [
                'user_id' => $client->id,
                'type' => 'primary',
                'document_root' => '/home/client1/client1.com/public_html',
                'php_version' => '83',
                'ssl_enabled' => true,
                'status' => 'active',
            ]
        );

        // Database for Client
        DbModel::firstOrCreate(
            ['db_name' => 'client1_app'],
            [
                'user_id' => $client->id,
                'size_bytes' => 10485760,
            ]
        );
    }
}
