<?php

declare(strict_types=1);

use App\Domains\Security\ValueObjects\ImunifyStatus;

it('marks imunify as not installed when cli state is not_installed even if installed flag is true', function () {
    $vo = ImunifyStatus::fromCliData([
        'installed' => true,
        'status' => 'not_installed',
    ]);

    expect($vo->installed)->toBeFalse()
        ->and($vo->status)->toBe('not_installed')
        ->and($vo->installing)->toBeFalse()
        ->and($vo->uiUrl)->toBe('/imunifyav/');
});

it('marks imunify as active and installed when cli status is active', function () {
    $vo = ImunifyStatus::fromCliData([
        'installed' => true,
        'status' => 'active',
        'pam_service' => 'system-auth',
    ]);

    expect($vo->installed)->toBeTrue()
        ->and($vo->status)->toBe('active')
        ->and($vo->pamService)->toBe('system-auth')
        ->and($vo->toArray())->toMatchArray([
            'installed' => true,
            'status' => 'active',
            'installing' => false,
            'ui_url' => '/imunifyav/',
            'pam_service' => 'system-auth',
        ]);
});

it('reflects installing queue state and includes install log', function () {
    $vo = ImunifyStatus::fromCliData(
        cliData: ['installed' => false, 'status' => 'not_installed'],
        queueState: 'provisioning',
        installLog: 'Downloading imav-deploy.sh...',
    );

    expect($vo->installed)->toBeFalse()
        ->and($vo->installing)->toBeTrue()
        ->and($vo->status)->toBe('installing')
        ->and($vo->installLog)->toBe('Downloading imav-deploy.sh...');
});

it('reflects failed queue state when installation fails', function () {
    $vo = ImunifyStatus::fromCliData(
        cliData: ['installed' => false, 'status' => 'not_installed'],
        queueState: 'failed',
        installLog: 'Error [404]',
        error: 'Imunify installation failed.',
    );

    expect($vo->installed)->toBeFalse()
        ->and($vo->installing)->toBeFalse()
        ->and($vo->status)->toBe('failed')
        ->and($vo->error)->toBe('Imunify installation failed.');
});
