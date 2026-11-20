<?php

namespace App\Http\Controllers\WebServer;

use App\Domains\WebServer\Actions\CreateFtpAccountAction;
use App\Domains\WebServer\Actions\DeleteFtpAccountAction;
use App\Domains\WebServer\Actions\UpdateFtpAccountAction;
use App\Domains\WebServer\Actions\UpdateFtpPasswordAction;
use App\Domains\WebServer\Models\Domain;
use App\Domains\WebServer\Models\FtpAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebServer\StoreFtpUserRequest;
use App\Http\Requests\WebServer\UpdateFtpPasswordRequest;
use App\Http\Requests\WebServer\UpdateFtpUserRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FtpController extends Controller
{
    public function __construct(
        protected CreateFtpAccountAction $createFtpAccount,
        protected UpdateFtpAccountAction $updateFtpAccount,
        protected UpdateFtpPasswordAction $updateFtpPassword,
        protected DeleteFtpAccountAction $deleteFtpAccount,
    ) {}

    public function index(Request $request): Response
    {
        $user = Auth::user();

        $domainsQuery = Domain::query()
            ->select(['id', 'domain', 'user_id'])
            ->orderBy('domain');

        if (! $user->isAdmin()) {
            $domainsQuery->where('user_id', $user->id);
        }

        $domains = $domainsQuery->get();
        $allowedDomainIds = $domains->pluck('id')->all();

        $domainFilter = $request->filled('domain_id') ? (int) $request->input('domain_id') : null;
        $search = trim((string) $request->input('search', ''));

        $ftpQuery = FtpAccount::query()
            ->with(['domain:id,domain,user_id', 'user:id,username'])
            ->whereIn('domain_id', $allowedDomainIds);

        if ($domainFilter !== null && in_array($domainFilter, $allowedDomainIds, true)) {
            $ftpQuery->where('domain_id', $domainFilter);
        } else {
            $domainFilter = null;
        }

        if ($search !== '') {
            $ftpQuery->where('username', 'like', '%'.$search.'%');
        }

        $ftpUsers = $ftpQuery
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('WebServer/Ftp', [
            'ftpUsers' => $ftpUsers,
            'domains' => $domains,
            'filters' => [
                'domain_id' => $domainFilter,
                'search' => $search,
            ],
            'connectionInfo' => [
                'host' => $request->server('SERVER_ADDR', '127.0.0.1'),
                'port' => (int) config('litecontrol.ftp_port', env('FTP_PORT', 21)),
            ],
        ]);
    }

    public function store(StoreFtpUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $domain = Domain::findOrFail($validated['domain_id']);

        try {
            $this->createFtpAccount->handle($domain, [
                'username' => $validated['username'],
                'password' => $validated['password'],
                'dir' => $validated['home_directory'] ?? '',
                'quota_mb' => $validated['disk_quota'] ?? 0,
            ], $request->user());

            return back()->with('success', "FTP account created successfully for {$domain->domain}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to create FTP account: '.$e->getMessage());
        }
    }

    public function update(UpdateFtpUserRequest $request, FtpAccount $ftpUser): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->updateFtpAccount->handle($ftpUser, [
                'dir' => $validated['home_directory'] ?? '',
                'quota_mb' => $validated['disk_quota'] ?? 0,
            ], $request->user());

            return back()->with('success', "FTP account '{$ftpUser->username}' updated successfully.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to update FTP account: '.$e->getMessage());
        }
    }

    public function updatePassword(UpdateFtpPasswordRequest $request, FtpAccount $ftpUser): RedirectResponse
    {
        try {
            $this->updateFtpPassword->handle($ftpUser, $request->validated('password'), $request->user());

            return back()->with('success', "Password updated for FTP account '{$ftpUser->username}'.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to update FTP password: '.$e->getMessage());
        }
    }

    public function destroy(Request $request, FtpAccount $ftpUser): RedirectResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $ftpUser->domain->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $username = $ftpUser->username;
        $this->deleteFtpAccount->handle($ftpUser, $user);

        return back()->with('success', "FTP account '{$username}' deleted.");
    }
}
