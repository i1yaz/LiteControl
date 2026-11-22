<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dns;

use App\Domains\Dns\Actions\CreateAutoprimaryAction;
use App\Domains\Dns\Actions\DeleteAutoprimaryAction;
use App\Domains\Dns\Exceptions\PowerDnsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dns\CreateAutoprimaryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PdnsAutoprimaryController extends Controller
{
    /**
     * Register a trusted PowerDNS Autoprimary (Supermaster) via `POST /api/v1/servers/localhost/autoprimaries`.
     */
    public function store(CreateAutoprimaryRequest $request, CreateAutoprimaryAction $action): RedirectResponse
    {
        try {
            $entry = $request->toValueObject();
            $action->handle($entry, $request->user(), $request->ip());

            return redirect()->back()->with(
                'success',
                sprintf('Autoprimary %s (%s) registered.', $entry->ip, $entry->nameserver)
            );
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove a PowerDNS Autoprimary (Supermaster) via `DELETE /api/v1/servers/localhost/autoprimaries/{ip}/{nameserver}`.
     */
    public function destroy(
        Request $request,
        string $ip,
        string $nameserver,
        DeleteAutoprimaryAction $action,
    ): RedirectResponse {
        try {
            $result = $action->handle($ip, $nameserver, $request->user(), $request->ip());

            return redirect()->back()->with(
                'success',
                sprintf('Autoprimary %s (%s) removed.', $result['ip'], $result['nameserver'])
            );
        } catch (InvalidArgumentException|PowerDnsException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
