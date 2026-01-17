<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        if (!config('orbit.multi_environment')) {
            return redirect()->route('environments.projects');
        }

        $defaultEnvironment = Environment::getDefault();

        if ($defaultEnvironment instanceof \App\Models\Environment) {
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
