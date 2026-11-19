<?php

declare(strict_types=1);

namespace App\Domains\Security\Actions;

use App\Domains\HostingPlans\Models\Package;
use App\Domains\Identity\Models\User;
use App\Domains\WebServer\Models\Domain;

class BuildImunifyIntegrationDataAction
{
    /**
     * Build the CloudLinux Imunify standalone integration JSON payload for a given section.
     *
     * @return array{data: mixed, metadata: array{result: string}}
     */
    public function handle(string $section): array
    {
        $data = match ($section) {
            'panel_info' => $this->buildPanelInfo(),
            'admins' => $this->buildAdmins(),
            'users' => $this->buildUsers(),
            'domains' => $this->buildDomains(),
            'packages' => $this->buildPackages(),
            'resellers' => $this->buildResellers(),
            'db_info' => $this->buildDbInfo(),
            default => throw new \InvalidArgumentException("Unsupported Imunify integration section: {$section}"),
        };

        return [
            'data' => $data,
            'metadata' => [
                'result' => 'ok',
            ],
        ];
    }

    /**
     * @return array{name: string, version: string}
     */
    private function buildPanelInfo(): array
    {
        $versionFile = dirname(base_path()).'/VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '1.0.0';

        return [
            'name' => 'LiteControl',
            'version' => $version !== '' ? $version : '1.0.0',
        ];
    }

    /**
     * @return array<int, array{name: string, email: string, is_main: bool}>
     */
    private function buildAdmins(): array
    {
        $admins = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->get();

        if ($admins->isEmpty()) {
            return [[
                'name' => 'root',
                'email' => 'root@localhost',
                'is_main' => true,
            ]];
        }

        $result = [];
        foreach ($admins as $index => $admin) {
            $result[] = [
                'name' => $index === 0 ? 'root' : ($admin->username ?: 'admin'.$admin->id),
                'email' => $admin->email ?: 'root@localhost',
                'is_main' => $index === 0,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildUsers(): array
    {
        $users = User::query()
            ->with(['package', 'domains'])
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($users as $user) {
            $primaryDomain = $user->domains->firstWhere('type', 'primary') ?? $user->domains->first();
            $packageName = $user->package?->name ?? 'Default';

            $result[] = [
                'id' => (int) ($user->system_uid ?: (1000 + $user->id)),
                'username' => (string) ($user->username ?: 'user'.$user->id),
                'owner' => 'root',
                'domain' => (string) ($primaryDomain?->domain ?? ''),
                'email' => (string) ($user->email ?: ''),
                'package' => [
                    'name' => $packageName,
                    'owner' => 'root',
                ],
            ];
        }

        return $result;
    }

    private function buildDomains(): object
    {
        $domains = Domain::query()
            ->with('user')
            ->orderBy('id')
            ->get();

        $map = [];
        foreach ($domains as $domain) {
            $username = $domain->user?->username ?: 'litecontrol';
            $docRoot = $domain->document_root ?: "/home/{$username}/domains/{$domain->domain}/public_html";

            $map[$domain->domain] = [
                'document_root' => $docRoot,
                'is_main' => ($domain->type ?? 'primary') === 'primary',
                'owner' => $username,
            ];
        }

        return (object) $map;
    }

    /**
     * @return array<int, array{name: string, creator: string}>
     */
    private function buildPackages(): array
    {
        $packages = Package::query()->orderBy('id')->get();

        if ($packages->isEmpty()) {
            return [[
                'name' => 'Default',
                'creator' => 'root',
            ]];
        }

        return $packages->map(fn (Package $pkg) => [
            'name' => $pkg->name,
            'creator' => 'root',
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildResellers(): array
    {
        return User::query()
            ->where('role', 'reseller')
            ->orderBy('id')
            ->get()
            ->map(fn (User $reseller) => [
                'id' => (int) ($reseller->system_uid ?: (1000 + $reseller->id)),
                'username' => (string) ($reseller->username ?: 'reseller'.$reseller->id),
                'email' => (string) ($reseller->email ?: ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{host: string, port: int}
     */
    private function buildDbInfo(): array
    {
        return [
            'host' => (string) config('database.connections.mysql.host', '127.0.0.1'),
            'port' => (int) config('database.connections.mysql.port', 3306),
        ];
    }
}
