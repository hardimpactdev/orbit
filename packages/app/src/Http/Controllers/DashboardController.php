<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        // Web mode: redirect to environment show page (injected by ImplicitEnvironment middleware)
        if (! config('orbit.multi_environment')) {
            $environment = $request->route('environment');

            if ($environment instanceof Environment) {
                return redirect()->route('environments.show', $environment);
            }

            // Fallback: find local environment
            $environment = Environment::where('is_local', true)->first()
                ?? Environment::first();

            if ($environment) {
                return redirect()->route('environments.show', $environment);
            }

            // No environment exists - redirect to sites page (will show empty state)
            return redirect()->route('environments.sites');
        }

        // Desktop mode: redirect to default environment
        $defaultEnvironment = Environment::getDefault();

        if ($defaultEnvironment instanceof Environment) {
            return redirect()->route('environments.show', $defaultEnvironment);
        }

        // No default environment - check if any environments exist
        $firstEnvironment = Environment::where('status', 'active')->first();

        if ($firstEnvironment) {
            // Set it as default and redirect
            $firstEnvironment->update(['is_default' => true]);

            return redirect()->route('environments.show', $firstEnvironment);
        }

        // No environments at all - redirect to create
        return redirect()->route('environments.create');
    }
}
