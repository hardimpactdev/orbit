<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Setting;
use App\Services\LaunchpadService;
use App\Services\SshService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        protected SshService $ssh,
        protected LaunchpadService $launchpad,
    ) {}

    public function index()
    {
        $servers = Server::all();
        return view('servers.index', compact('servers'));
    }

    public function create()
    {
        return view('servers.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'is_local' => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            Server::where('is_default', true)->update(['is_default' => false]);
        }

        $server = Server::create($validated);

        return redirect()->route('servers.index')
            ->with('success', "Server '{$server->name}' added successfully.");
    }

    public function show(Server $server)
    {
        $installation = $this->launchpad->checkInstallation($server);
        $status = $installation['installed'] ? $this->launchpad->status($server) : null;
        $sites = $installation['installed'] ? $this->launchpad->sites($server) : null;
        $editor = Setting::getEditor();

        return view('servers.show', compact('server', 'installation', 'status', 'sites', 'editor'));
    }

    public function edit(Server $server)
    {
        return view('servers.edit', compact('server'));
    }

    public function update(Request $request, Server $server)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'is_local' => 'boolean',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            Server::where('is_default', true)
                ->where('id', '!=', $server->id)
                ->update(['is_default' => false]);
        }

        $server->update($validated);

        return redirect()->route('servers.index')
            ->with('success', "Server '{$server->name}' updated successfully.");
    }

    public function destroy(Server $server)
    {
        $name = $server->name;
        $server->delete();

        return redirect()->route('servers.index')
            ->with('success', "Server '{$name}' removed successfully.");
    }

    public function testConnection(Server $server)
    {
        $result = $this->ssh->testConnection($server);

        if ($result['success']) {
            $server->update(['last_connected_at' => now()]);
        }

        return response()->json($result);
    }

    public function status(Server $server)
    {
        $result = $this->launchpad->status($server);
        return response()->json($result);
    }

    public function sites(Server $server)
    {
        $result = $this->launchpad->sites($server);
        return response()->json($result);
    }

    public function start(Request $request, Server $server)
    {
        $site = $request->input('site');
        $result = $this->launchpad->start($server, $site);
        return response()->json($result);
    }

    public function stop(Request $request, Server $server)
    {
        $site = $request->input('site');
        $result = $this->launchpad->stop($server, $site);
        return response()->json($result);
    }

    public function restart(Request $request, Server $server)
    {
        $site = $request->input('site');
        $result = $this->launchpad->restart($server, $site);
        return response()->json($result);
    }

    public function changePhp(Request $request, Server $server)
    {
        $validated = $request->validate([
            'site' => 'required|string',
            'version' => 'required|string',
        ]);

        $result = $this->launchpad->php($server, $validated['site'], $validated['version']);
        return response()->json($result);
    }

    public function resetPhp(Request $request, Server $server)
    {
        $validated = $request->validate([
            'site' => 'required|string',
        ]);

        $result = $this->launchpad->phpReset($server, $validated['site']);
        return response()->json($result);
    }
}
