<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\User;
use App\Models\Domain;
use App\Domains\Database\Models\Database as DbModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPackage = Package::where('name', 'Default')->first();

        // Standard Hosting Customer
        $client = User::firstOrCreate(
            ['username' => 'client1'],
            [
                'name' => 'John Doe',
                'email' => 'client1@example.com',
                'password' => Hash::make('client123456'),
                'role' => 'user',
                'package_id' => $defaultPackage?->id,
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
