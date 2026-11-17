<?php

use App\Domains\WebServer\ValueObjects\SiteTuningConfig;

test('defaults creates a valid SiteTuningConfig', function () {
    $config = SiteTuningConfig::defaults();

    expect($config->maxConns)->toBe(10)
        ->and($config->memHardLimitMb)->toBe(1024)
        ->and($config->phpMemoryLimitMb)->toBe(256)
        ->and($config->phpDisplayErrors)->toBeFalse();
});

test('enforces cross-layer memory invariant', function () {
    expect(fn () => SiteTuningConfig::fromArray([
        'mem_soft_limit_mb' => 256,
        'mem_hard_limit_mb' => 300,
        'php_memory_limit_mb' => 256,
    ]))->toThrow(InvalidArgumentException::class, 'mem_hard_limit_mb must be >= php_memory_limit_mb + 64 MB.');
});

test('enforces post_max_size >= upload_max_filesize', function () {
    expect(fn () => SiteTuningConfig::fromArray([
        'php_upload_max_filesize_mb' => 128,
        'php_post_max_size_mb' => 64,
    ]))->toThrow(InvalidArgumentException::class, 'php_post_max_size_mb must be >= php_upload_max_filesize_mb.');
});

test('renders OLS fragment with units and php_admin_value', function () {
    $config = SiteTuningConfig::defaults();
    $fragment = $config->renderOlsFragment('tenant1', '83');

    expect($fragment)
        ->toContain('extprocessor tenant1 {')
        ->toContain('maxConns                10')
        ->toContain('LSAPI_CHILDREN=10')
        ->toContain('memSoftLimit            512M')
        ->toContain('memHardLimit            1024M')
        ->toContain('path                    /usr/local/lsws/lsphp83/bin/lsphp')
        ->toContain('php_admin_value memory_limit 256M')
        ->toContain('php_admin_flag display_errors Off');
});
