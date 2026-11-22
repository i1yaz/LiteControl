<?php

declare(strict_types=1);

use App\Domains\ServerOps\Infrastructure\CliModuleAdapter;
use App\Domains\ServerOps\ModuleRegistry;
use App\Support\Cli\LcCliService;

it('maps every lc-cli module registry key and legacy alias to a module supported by scripts/ubuntu/commands/module.sh', function (): void {
    $scriptPath = dirname(__DIR__, 5).'/scripts/ubuntu/commands/module.sh';
    expect(is_file($scriptPath))->toBeTrue("Expected CLI module script at {$scriptPath}");

    $script = file_get_contents($scriptPath);
    expect($script)->toBeString();

    preg_match('/SUPPORTED_MODULES=\(([^)]+)\)/', $script, $matches);
    expect($matches)->toHaveKey(1);

    preg_match_all('/"([^"]+)"/', $matches[1], $modMatches);
    $supportedInBash = $modMatches[1];

    expect($supportedInBash)->toContain('ftp', 'dns', 'mail', 'modsecurity', 'imunifyav');

    // Modules managed by dedicated mail-stack jobs rather than `lc-cli module`
    $dedicatedMailModules = ['mail_core', 'mail_filter', 'clamav', 'mail_webmail'];

    $cliBackedKeys = array_values(array_diff(
        array_keys(ModuleRegistry::definitions()),
        $dedicatedMailModules
    ));

    foreach (array_merge($cliBackedKeys, ['powerdns']) as $key) {
        $normalized = ModuleRegistry::normalizeKey($key);
        expect($supportedInBash)->toContain($normalized);
    }
});

it('normalizes legacy powerdns key to dns when calling LcCliService', function (): void {
    $lcCli = Mockery::mock(LcCliService::class);
    $lcCli->shouldReceive('run')
        ->once()
        ->with('module', ['install', 'dns'])
        ->andReturn(['status' => 'success', 'data' => ['name' => 'dns', 'installed' => true]]);

    $lcCli->shouldReceive('run')
        ->once()
        ->with('module', ['status', 'dns'])
        ->andReturn(['status' => 'success', 'data' => ['name' => 'dns', 'installed' => true, 'status' => 'active']]);

    $lcCli->shouldReceive('run')
        ->once()
        ->with('module', ['remove', 'dns'])
        ->andReturn(['status' => 'success', 'data' => ['name' => 'dns', 'installed' => false]]);

    $adapter = new CliModuleAdapter($lcCli);

    expect($adapter->installModule('powerdns'))->toBe(['name' => 'dns', 'installed' => true]);
    expect($adapter->getModuleStatus('powerdns'))->toBe(['name' => 'dns', 'installed' => true, 'status' => 'active']);
    expect($adapter->removeModule('powerdns'))->toBe(['name' => 'dns', 'installed' => false]);
});
