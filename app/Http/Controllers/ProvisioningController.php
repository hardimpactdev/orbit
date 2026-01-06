<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class ProvisioningController extends Controller
{
    public function create(): \Inertia\Response
    {
        $sshPublicKey = Setting::getSshPublicKey();
        $availableSshKeys = Setting::getAvailableSshKeys();

        return \Inertia\Inertia::render('provisioning/Create', [
            'sshPublicKey' => $sshPublicKey,
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

        // Create the server record immediately with provisioning status
        $server = Server::create([
            'name' => $validated['name'],
            'host' => $validated['host'],
            'user' => $validated['user'],
            'port' => 22,
            'is_local' => false,
            'status' => Server::STATUS_PROVISIONING,
        ]);

        // Redirect to the server show page immediately - provisioning runs in background
        return redirect()->route('servers.show', $server);
    }

    public function run(Request $request, Server $server)
    {
        $validated = $request->validate([
            'ssh_public_key' => 'required|string',
        ]);

        // Clear old SSH host keys BEFORE starting provisioning
        // This must happen synchronously before the background process starts
        Process::run("ssh-keygen -R {$server->host} 2>/dev/null");

        // Run provisioning in the background so the HTTP request returns immediately
        $sshKey = $validated['ssh_public_key'];
        $artisanPath = base_path('artisan');

        // Spawn the artisan command in the background
        $command = sprintf(
            'php %s server:provision %d %s > /dev/null 2>&1 &',
            escapeshellarg($artisanPath),
            $server->id,
            escapeshellarg((string) $sshKey)
        );

        // Use popen for background execution
        pclose(popen($command, 'r'));

        return response()->json([
            'started' => true,
            'message' => 'Provisioning started in background',
        ]);
    }

    public function status(Server $server)
    {
        return response()->json([
            'status' => $server->status,
            'provisioning_step' => $server->provisioning_step,
            'provisioning_total_steps' => $server->provisioning_total_steps,
            'provisioning_log' => $server->provisioning_log,
            'provisioning_error' => $server->provisioning_error,
        ]);
    }
}
