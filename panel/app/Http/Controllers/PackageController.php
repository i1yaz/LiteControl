<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    /**
     * Display a listing of hosting packages.
     */
    public function index(): Response
    {
        $packages = Package::withCount('users')->latest()->get();

        return Inertia::render('Packages/Index', [
            'packages' => $packages,
        ]);
    }

    /**
     * Store a newly created hosting package.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:packages,name'],
            'disk_quota_mb' => ['required', 'integer', 'min:100', 'max:1048576'],
            'bandwidth_mb' => ['required', 'integer', 'min:100', 'max:10485760'],
            'max_domains' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_subdomains' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_ftp_accounts' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_email_accounts' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $package = Package::create($validated);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'package.create',
            'command' => "package add {$package->name}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => $validated,
        ]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' created.");
    }

    /**
     * Update the specified hosting package.
     */
    public function update(Request $request, Package $package)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:packages,name,' . $package->id],
            'disk_quota_mb' => ['required', 'integer', 'min:100', 'max:1048576'],
            'bandwidth_mb' => ['required', 'integer', 'min:100', 'max:10485760'],
            'max_domains' => ['required', 'integer', 'min:1', 'max:1000'],
            'max_subdomains' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_databases' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_ftp_accounts' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_email_accounts' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $package->update($validated);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'package.update',
            'command' => "package update {$package->name}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => $validated,
        ]);

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' updated.");
    }

    /**
     * Remove the specified package from storage.
     */
    public function destroy(Request $request, Package $package)
    {
        if ($package->users()->count() > 0) {
            return back()->with('error', "Cannot delete package '{$package->name}' because it has active users assigned.");
        }

        $name = $package->name;
        $package->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'package.delete',
            'command' => "package delete {$name}",
            'status' => 'success',
            'ip_address' => $request->ip(),
            'details' => ['name' => $name],
        ]);

        return redirect()->route('packages.index')->with('success', "Package '{$name}' deleted.");
    }
}

