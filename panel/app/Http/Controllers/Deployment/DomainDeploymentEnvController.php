<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deployment;

use App\Domains\WebServer\Models\Domain;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class DomainDeploymentEnvController extends Controller
{
    public function show(Domain $domain): JsonResponse
    {
        $domain->loadMissing('user');
        $linuxUser = $domain->user->username ?? $domain->user->name ?? 'www-data';

        $result = Process::timeout(30)->run([
            'sudo',
            '/usr/local/bin/lc-cli',
            'deploy',
            'env',
            "--user={$linuxUser}",
            "--domain={$domain->domain}",
            '--action=read',
        ]);

        if (! $result->successful()) {
            return response()->json([
                'error' => trim($result->errorOutput() ?: $result->output()) ?: 'Failed to read .env file.',
            ], 500);
        }

        return response()->json([
            'content' => $result->output(),
        ]);
    }

    public function update(Request $request, Domain $domain): RedirectResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:65535'],
        ]);

        $domain->loadMissing('user');
        $linuxUser = $domain->user->username ?? $domain->user->name ?? 'www-data';

        $result = Process::timeout(30)
            ->input($validated['content'])
            ->run([
                'sudo',
                '/usr/local/bin/lc-cli',
                'deploy',
                'env',
                "--user={$linuxUser}",
                "--domain={$domain->domain}",
                '--action=write',
            ]);

        if (! $result->successful()) {
            return back()->withErrors([
                'env' => trim($result->errorOutput() ?: $result->output()) ?: 'Failed to update .env file.',
            ]);
        }

        return back()->with('success', 'Shared .env file updated.');
    }
}
