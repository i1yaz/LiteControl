<?php

namespace App\Http\Controllers;

use App\Domains\HostingPlans\Actions\CreatePackageAction;
use App\Domains\HostingPlans\Actions\DeletePackageAction;
use App\Domains\HostingPlans\Actions\UpdatePackageAction;
use App\Domains\HostingPlans\ValueObjects\PackageName;
use App\Domains\HostingPlans\ValueObjects\ResourceQuotas;
use App\Models\Package;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function __construct(
        private readonly CreatePackageAction $createPackage,
        private readonly UpdatePackageAction $updatePackage,
        private readonly DeletePackageAction $deletePackage,
    ) {}

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
    public function store(Request $request): RedirectResponse
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

        $package = $this->createPackage->handle(
            new PackageName($validated['name']),
            ResourceQuotas::fromArray($validated),
            Auth::id(),
            $request->ip(),
        );

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' created.");
    }

    /**
     * Update the specified hosting package.
     */
    public function update(Request $request, Package $package): RedirectResponse
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

        $this->updatePackage->handle(
            $package,
            new PackageName($validated['name']),
            ResourceQuotas::fromArray($validated),
            Auth::id(),
            $request->ip(),
        );

        return redirect()->route('packages.index')->with('success', "Package '{$package->name}' updated.");
    }

    /**
     * Remove the specified package from storage.
     */
    public function destroy(Request $request, Package $package): RedirectResponse
    {
        try {
            $name = $this->deletePackage->handle($package, Auth::id(), $request->ip());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('packages.index')->with('success', "Package '{$name}' deleted.");
    }
}
