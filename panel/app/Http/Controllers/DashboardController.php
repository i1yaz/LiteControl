<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Domain;
use App\Models\Database as DbModel;
use App\Models\Package;
use App\Services\LcCliService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected LcCliService $cli
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        // Get server statistics from lc-cli (or mock service)
        $serverStatsResponse = $this->cli->run('server', ['stats']);
        $stats = $serverStatsResponse['data'] ?? [
            'cpu_percent' => 0,
            'ram_percent' => 0,
            'disk_percent' => 0,
            'hostname' => gethostname(),
            'server_ip' => $request->server('SERVER_ADDR', '127.0.0.1'),
            'server_time' => now()->toDateTimeString(),
            'uptime_human' => 'Unknown',
            'ols_version' => 'OpenLiteSpeed',
            'os_pretty' => 'Ubuntu Linux',
            'load_avg' => ['1m' => 0, '5m' => 0, '15m' => 0],
        ];

        if ($isAdmin) {
            $counters = [
                'total_users' => User::where('role', 'user')->count(),
                'total_domains' => Domain::count(),
                'total_databases' => DbModel::count(),
                'total_packages' => Package::count(),
            ];

            return Inertia::render('Dashboard', [
                'isAdmin' => true,
                'stats' => $stats,
                'counters' => $counters,
                'recentUsers' => User::with('package')->latest()->take(5)->get(),
            ]);
        }

        // Standard User Dashboard Data
        $user->load('package');
        $userDomains = Domain::where('user_id', $user->id)->get();
        $userDatabases = DbModel::where('user_id', $user->id)->get();

        $userQuotas = [
            'disk_used_mb' => 150, // Mock or calculated
            'disk_limit_mb' => $user->package?->disk_quota_mb ?? 1000,
            'bandwidth_used_mb' => 2400,
            'bandwidth_limit_mb' => $user->package?->bandwidth_mb ?? 10000,
            'domains_count' => $userDomains->count(),
            'domains_limit' => $user->package?->max_domains ?? 1,
            'databases_count' => $userDatabases->count(),
            'databases_limit' => $user->package?->max_databases ?? 5,
        ];

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'stats' => $stats,
            'userQuotas' => $userQuotas,
            'domains' => $userDomains,
        ]);
    }
}

