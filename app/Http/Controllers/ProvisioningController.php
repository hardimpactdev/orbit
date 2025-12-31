<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Setting;
use App\Services\ProvisioningService;
use Illuminate\Http\Request;

class ProvisioningController extends Controller
{
    public function __construct(
        protected ProvisioningService $provisioning,
    ) {}

    public function create()
    {
        $sshPublicKey = Setting::getSshPublicKey();
        $availableSshKeys = Setting::getAvailableSshKeys();

        return view('provisioning.create', compact('sshPublicKey', 'availableSshKeys'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'ssh_public_key' => 'required|string',
        ]);

        // Store the SSH key if it changed
        if ($validated['ssh_public_key'] !== Setting::getSshPublicKey()) {
            Setting::setSshPublicKey($validated['ssh_public_key']);
        }

        // Run provisioning
        $result = $this->provisioning->provision(
            $validated['host'],
            $validated['ssh_public_key']
        );

        if (!$result['success']) {
            return back()
                ->withInput()
                ->with('error', $result['error'])
                ->with('provisioning_log', $result['log']);
        }

        // Create the server record
        $server = Server::create([
            'name' => $validated['name'],
            'host' => $result['server']['host'],
            'user' => $result['server']['user'],
            'port' => $result['server']['port'],
            'is_local' => false,
            'is_default' => Server::count() === 0,
        ]);

        return redirect()->route('servers.show', $server)
            ->with('success', 'Server provisioned and added successfully!')
            ->with('provisioning_log', $result['log']);
    }

    public function provision(Request $request)
    {
        $validated = $request->validate([
            'host' => 'required|string|max:255',
            'ssh_public_key' => 'required|string',
        ]);

        $result = $this->provisioning->provision(
            $validated['host'],
            $validated['ssh_public_key']
        );

        return response()->json($result);
    }
}
