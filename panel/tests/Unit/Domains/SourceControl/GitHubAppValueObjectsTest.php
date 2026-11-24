<?php

declare(strict_types=1);

use App\Domains\SourceControl\ValueObjects\GitHubAppName;
use App\Domains\SourceControl\ValueObjects\GitHubOrgName;
use App\Domains\SourceControl\ValueObjects\SafeReturnPath;

it('accepts valid relative same-origin return paths', function (string $path): void {
    $vo = new SafeReturnPath($path);

    expect($vo->value)->toBe($path);
})->with([
    '/settings/source-control',
    '/domains/42/deploy',
    '/domains/42/deploy?tab=source&auto=1',
    '/',
]);

it('rejects open-redirect return_to values', function (string $path): void {
    expect(fn () => new SafeReturnPath($path))->toThrow(InvalidArgumentException::class);
})->with([
    'https://evil.com/phish',
    'http://evil.com',
    '//evil.com/path',
    '///evil.com',
    '/\evil.com',
    'javascript:alert(1)',
    'settings/source-control',
    "/settings\r\nLocation: https://evil.com",
    '',
]);

it('accepts valid GitHub org names and rejects invalid org_name strings', function (): void {
    expect((new GitHubOrgName('acme-corp-123'))->value)->toBe('acme-corp-123')
        ->and(GitHubOrgName::fromNullable(null))->toBeNull()
        ->and(GitHubOrgName::fromNullable('   '))->toBeNull();

    foreach (['', 'acme/evil', 'acme.com', 'has space', str_repeat('a', 40), 'org?foo=bar', '../etc'] as $invalid) {
        expect(fn () => new GitHubOrgName($invalid))->toThrow(InvalidArgumentException::class);
    }
});

it('truncates generated GitHub App names to 34 characters or fewer', function (): void {
    $longHost = 'very-long-panel-subdomain.production.eu-west-1.example-hosting-provider.com';
    $appName = GitHubAppName::fromHost($longHost, 'ab12cd');

    expect(strlen($appName->value))->toBeLessThanOrEqual(34)
        ->and($appName->value)->toStartWith('lc-')
        ->and($appName->value)->toEndWith('-ab12cd');

    $shortAppName = GitHubAppName::fromHost('panel.example.com', 'xyz987');
    expect($shortAppName->value)->toBe('lc-panel-example-com-xyz987')
        ->and(strlen($shortAppName->value))->toBeLessThanOrEqual(34);
});
