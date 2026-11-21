<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Dns\Models\DnsZoneTemplate;
use Illuminate\Database\Seeder;

class DnsZoneTemplateSeeder extends Seeder
{
    public const DEFAULT_TEMPLATE_NAME = DnsZoneTemplate::DEFAULT_TEMPLATE_NAME;

    public function run(): void
    {
        self::seedDefaultTemplate();
    }

    public static function seedDefaultTemplate(): DnsZoneTemplate
    {
        return DnsZoneTemplate::ensureDefaultSeeded();
    }
}
