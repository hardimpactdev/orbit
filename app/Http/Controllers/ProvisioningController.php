<?php

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Setting;
use App\Models\SshKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class ProvisioningController extends Controller
{
    public function create(): \Inertia\Response
    {
        $sshKeys = SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = Setting::getAvailableSshKeys();

        return \Inertia\Inertia::render('provisioning/Create', [
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'ssh_public_key' => 'required|string',
        ]);

        // Store the SSH key if it changed
        if ($validated['ssh_public_key'] !== Setting::getSshPublicKey()) {
            Setting::setSshPublicKey($validated['ssh_public_key']);
        }

        // Create the environment record immediately with provisioning status
        $environment = Environment::create([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'user' => $validated['user'],
            'port' => 22,
            'is_local' => false,
            'status' => Environment::STATUS_PROVISIONING,
        ]);

        // Redirect to the environment show page immediately - provisioning runs in background
        return redirect()->route('environments.show', $environment);
    }

    public function run(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'ssh_public_key' => 'required|string',
        ]);

        // Clear old SSH host keys BEFORE starting provisioning
        // This must happen synchronously before the background process starts
        Process::run("ssh-keygen -R {$environment->host} 2>/dev/null");

        // Run provisioning in the background so the HTTP request returns immediately
        $sshKey = $validated['ssh_public_key'];
        $artisanPath = base_path('artisan');

        // Spawn the artisan command in the background
        $command = sprintf(
            'php %s environment:provision %d %s > /dev/null 2>&1 &',
            escapeshellarg($artisanPath),
            $environment->id,
            escapeshellarg((string) $sshKey)
        );

        // Use popen for background execution
        pclose(popen($command, 'r'));

        return response()->json([
            'started' => true,
            'message' => 'Provisioning started in background',
        ]);
    }

    public function status(Environment $environment)
    {
        return response()->json([
            'status' => $environment->status,
            'provisioning_step' => $environment->provisioning_step,
            'provisioning_total_steps' => $environment->provisioning_total_steps,
            'provisioning_log' => $environment->provisioning_log,
            'provisioning_error' => $environment->provisioning_error,
        ]);
    }

    public function checkServer(Request $request)
    {
        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
        ]);

        $result = \App\Services\ProvisioningService::checkExistingSetup(
            $validated['host'],
            $validated['user']
        );

        return response()->json($result);
    }
}
