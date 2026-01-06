<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Setting;
use App\Services\DnsResolverService;
use App\Services\LaunchpadService;
use App\Services\SshService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        protected SshService $ssh,
        protected LaunchpadService $launchpad,
        protected DnsResolverService $dnsResolver,
    ) {}

    public function index(): \Inertia\Response
    {
        $servers = Server::all();
        $hasLocalEnvironment = Server::where('is_local', true)->exists();

        return \Inertia\Inertia::render('servers/Index', [
            'servers' => $servers,
            'hasLocalEnvironment' => $hasLocalEnvironment,
        ]);
    }

    public function create(): \Inertia\Response|\Illuminate\Http\RedirectResponse
    {
        // Check if a local environment already exists
        if (Server::where('is_local', true)->exists()) {
            return redirect()->route('servers.index')
                ->with('error', 'A local environment already exists.');
        }

        return \Inertia\Inertia::render('servers/Create', [
            'currentUser' => get_current_user(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'is_local' => 'boolean',
        ]);

        // Prevent creating more than one local environment
        if (($validated['is_local'] ?? false) && Server::where('is_local', true)->exists()) {
            return redirect()->route('servers.index')
                ->with('error', 'A local environment already exists.');
        }

        $server = Server::create($validated);

        return redirect()->route('servers.index')
            ->with('success', "Environment '{$server->name}' added successfully.");
    }

    public function show(Server $server): \Inertia\Response
    {
        // If server is being provisioned or has error, show provisioning view
        if ($server->isProvisioning() || $server->hasError()) {
            $sshPublicKey = Setting::getSshPublicKey();

            return \Inertia\Inertia::render('servers/Provisioning', [
                'server' => $server,
                'sshPublicKey' => $sshPublicKey,
            ]);
        }

        // Only check installation synchronously (fast), load status/sites via AJAX
        $installation = $this->launchpad->checkInstallation($server);
        $editor = Setting::getEditor();

        return \Inertia\Inertia::render('servers/Show', [
            'server' => $server,
            'installation' => $installation,
            'editor' => $editor,
        ]);
    }

    public function edit(Server $server): \Inertia\Response
    {
        return \Inertia\Inertia::render('servers/Edit', [
            'server' => $server,
        ]);
    }

    public function update(Request $request, Server $server)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'user' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'is_local' => 'boolean',
        ]);

        // Prevent converting to local if another local environment exists
        if (($validated['is_local'] ?? false) && ! $server->is_local && Server::where('is_local', true)->exists()) {
            return redirect()->route('servers.edit', $server)
                ->with('error', 'A local environment already exists.');
        }

        $server->update($validated);

        return redirect()->route('servers.index')
            ->with('success', "Environment '{$server->name}' updated successfully.");
    }

    public function destroy(Server $server)
    {
        $name = $server->name;

        // Get the server's TLD before deletion for cleanup
        $tld = null;
        try {
            $config = $this->launchpad->getConfig($server);
            $tld = $config['success'] ? ($config['data']['tld'] ?? null) : null;
        } catch (\Exception $e) {
            // Ignore config fetch errors - server might be unreachable
        }

        // Delete the server
        $server->delete();

        // Clean up DNS resolver if no other servers use this TLD
        if ($tld) {
            try {
                // Check if any remaining servers use this TLD
                // Pass 0 as excludeServerId since the server is already deleted
                $otherServersWithTld = $this->countServersWithTld($tld, 0);
                if ($otherServersWithTld === 0) {
                    $this->dnsResolver->removeResolver($tld);
                }
            } catch (\Exception $e) {
                // Ignore cleanup errors - non-critical
                \Illuminate\Support\Facades\Log::warning("DNS resolver cleanup failed: {$e->getMessage()}");
            }
        }

        return redirect()->route('servers.index')
            ->with('success', "Environment '{$name}' removed successfully.");
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

    public function getConfig(Server $server)
    {
        $result = $this->launchpad->getConfig($server);

        return response()->json($result);
    }

    public function saveConfig(Request $request, Server $server)
    {
        try {
            $validated = $request->validate([
                'paths' => 'required|array',
                'paths.*' => 'required|string',
                'tld' => 'required|string|max:20',
                'default_php_version' => 'required|string|in:8.3,8.4',
            ]);

            // Get current config to check if TLD changed and preserve site-specific settings
            $currentConfig = $this->launchpad->getConfig($server);
            $oldTld = $currentConfig['success'] ? ($currentConfig['data']['tld'] ?? 'test') : null;
            $newTld = $validated['tld'];

            // Preserve existing site-specific settings (like PHP versions per site)
            $configToSave = $validated;
            if ($currentConfig['success'] && isset($currentConfig['data']['sites'])) {
                $configToSave['sites'] = $currentConfig['data']['sites'];
            }

            // Save the config to the server
            $result = $this->launchpad->saveConfig($server, $configToSave);

            if ($result['success']) {
                // Try to update DNS resolver (non-blocking - failures are logged but don't break the save)
                try {
                    $resolverResult = $this->dnsResolver->updateResolver($server, $newTld);

                    // If TLD changed, remove the old resolver
                    if ($oldTld && $oldTld !== $newTld) {
                        $otherServersWithTld = $this->countServersWithTld($oldTld, $server->id);
                        if ($otherServersWithTld === 0) {
                            $this->dnsResolver->removeResolver($oldTld);
                        }
                    }

                    $result['resolver'] = $resolverResult;
                } catch (\Exception $e) {
                    // DNS resolver update failed but config was saved - log and continue
                    \Illuminate\Support\Facades\Log::warning("DNS resolver update failed: {$e->getMessage()}");
                    $result['resolver'] = ['success' => false, 'error' => $e->getMessage()];
                }

                // Rebuild DNS container on the server with the new TLD
                try {
                    $dnsRebuildResult = $this->launchpad->rebuildDns($server, $newTld);
                    $result['dns_rebuild'] = $dnsRebuildResult;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::warning("DNS container rebuild failed: {$e->getMessage()}");
                    $result['dns_rebuild'] = ['success' => false, 'error' => $e->getMessage()];
                }
            }

            return response()->json($result);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("saveConfig failed: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all TLDs for all servers (for conflict detection).
     */
    public function getAllTlds()
    {
        $servers = Server::all();
        $tlds = [];

        foreach ($servers as $server) {
            $config = $this->launchpad->getConfig($server);
            if ($config['success'] && isset($config['data']['tld'])) {
                $tlds[$server->id] = $config['data']['tld'];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $tlds,
        ]);
    }

    /**
     * Count how many servers (excluding the given one) use a specific TLD.
     */
    protected function countServersWithTld(string $tld, int $excludeServerId): int
    {
        $count = 0;
        $servers = Server::where('id', '!=', $excludeServerId)->get();

        foreach ($servers as $server) {
            $config = $this->launchpad->getConfig($server);
            if ($config['success'] && ($config['data']['tld'] ?? 'test') === $tld) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get all worktrees for a server.
     */
    public function worktrees(Server $server)
    {
        $result = $this->launchpad->worktrees($server);

        return response()->json($result);
    }

    /**
     * Unlink a worktree from a site.
     */
    public function unlinkWorktree(Request $request, Server $server)
    {
        $validated = $request->validate([
            'site' => 'required|string',
            'worktree' => 'required|string',
        ]);

        $result = $this->launchpad->unlinkWorktree(
            $server,
            $validated['site'],
            $validated['worktree']
        );

        return response()->json($result);
    }

    /**
     * Refresh worktree detection.
     */
    public function refreshWorktrees(Server $server)
    {
        $result = $this->launchpad->refreshWorktrees($server);

        return response()->json($result);
    }
}
