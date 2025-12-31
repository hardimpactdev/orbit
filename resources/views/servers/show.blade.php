@extends('layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-6">
        <a href="{{ route('servers.index') }}" class="text-blue-600 hover:text-blue-800 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to Servers
        </a>
    </div>

    <div class="flex justify-between items-start mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800 dark:text-white">{{ $server->name }}</h2>
            <p class="text-gray-500 dark:text-gray-400">
                @if($server->is_local)
                    Local machine
                @else
                    {{ $server->user }}@{{ $server->host }}:{{ $server->port }}
                @endif
            </p>
        </div>
        <div class="flex space-x-2">
            <a href="{{ route('servers.edit', $server) }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Edit
            </a>
            <button onclick="testConnection()"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Test Connection
            </button>
        </div>
    </div>

    <!-- Connection Status -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mb-6" id="connection-status">
        <div class="flex items-center">
            <span class="w-3 h-3 rounded-full bg-gray-300 mr-3" id="status-dot"></span>
            <span class="text-gray-700 dark:text-gray-300" id="status-text">Connection not tested</span>
        </div>
    </div>

    <!-- Launchpad Installation -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Launchpad Installation</h3>
        @if($installation['installed'])
            <div class="flex items-center text-green-600">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Installed at {{ $installation['path'] }}
            </div>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Version: {{ $installation['version'] }}</p>
        @else
            <div class="flex items-center text-yellow-600">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Launchpad CLI not found
            </div>
            <p class="text-gray-500 dark:text-gray-400 mt-2">
                Install launchpad on this server to manage sites.
            </p>
        @endif
    </div>

    @if($installation['installed'] && $sites && $sites['success'])
        <!-- Sites -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Sites</h3>
            </div>
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Site</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">PHP</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Path</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700" id="sites-list">
                    @forelse($sites['data']['sites'] ?? [] as $site)
                        <tr>
                            <td class="px-6 py-4">
                                <div class="flex items-center">
                                    @if($site['secure'] ?? false)
                                        <svg class="w-4 h-4 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                        </svg>
                                    @else
                                        <svg class="w-4 h-4 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                                        </svg>
                                    @endif
                                    <span class="font-medium text-gray-900 dark:text-white">{{ $site['domain'] ?? $site['name'] . '.test' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <select onchange="changePhpVersion('{{ $site['name'] }}', this.value)"
                                        class="text-sm border border-gray-300 dark:border-gray-600 rounded px-2 py-1 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200">
                                    <option value="8.3" {{ ($site['php_version'] ?? '') === '8.3' ? 'selected' : '' }}>PHP 8.3</option>
                                    <option value="8.4" {{ ($site['php_version'] ?? '') === '8.4' ? 'selected' : '' }}>PHP 8.4</option>
                                </select>
                                @if($site['has_custom_php'] ?? false)
                                    <button type="button"
                                            onclick="resetPhpVersion('{{ $site['name'] }}')"
                                            class="ml-1 text-xs text-gray-500 hover:text-red-600"
                                            title="Reset to default">
                                        <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                    </button>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-gray-500 dark:text-gray-400 text-sm">
                                {{ $site['path'] ?? '' }}
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <button type="button"
                                        onclick="openSite('{{ $site['domain'] ?? $site['name'] . '.test' }}', {{ ($site['secure'] ?? false) ? 'true' : 'false' }})"
                                        class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                    Open
                                </button>
                                <button type="button"
                                        onclick="openInEditor('{{ $site['path'] ?? '' }}')"
                                        class="inline-flex items-center px-3 py-1 bg-gray-600 text-white text-sm rounded hover:bg-gray-700">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                    </svg>
                                    {{ $editor['name'] ?? 'Editor' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                No sites configured.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>

<script>
    const serverId = {{ $server->id }};
    const serverUser = @json($server->user);
    const serverHost = @json($server->host);
    const editorScheme = @json($editor['scheme']);
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    async function testConnection() {
        const dot = document.getElementById('status-dot');
        const text = document.getElementById('status-text');

        dot.className = 'w-3 h-3 rounded-full bg-yellow-400 animate-pulse mr-3';
        text.textContent = 'Testing connection...';

        try {
            const response = await fetch(`/servers/${serverId}/test-connection`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            const result = await response.json();

            dot.className = result.success
                ? 'w-3 h-3 rounded-full bg-green-500 mr-3'
                : 'w-3 h-3 rounded-full bg-red-500 mr-3';
            text.textContent = result.message;
        } catch (error) {
            dot.className = 'w-3 h-3 rounded-full bg-red-500 mr-3';
            text.textContent = 'Connection failed';
        }
    }

    async function openSite(domain, isSecure) {
        const protocol = isSecure ? 'https' : 'http';
        const url = `${protocol}://${domain}`;

        try {
            await fetch('/open-external', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ url }),
            });
        } catch (error) {
            console.error('Failed to open URL:', error);
        }
    }

    async function openInEditor(path) {
        if (!path) {
            alert('No path available for this site');
            return;
        }

        const sshHost = `${serverUser}@${serverHost}`;

        // Format: {editor}://vscode-remote/ssh-remote+user@host/path
        // Add windowId=_blank to open in new window
        const url = `${editorScheme}://vscode-remote/ssh-remote+${sshHost}${path}?windowId=_blank`;

        console.log('Opening editor URL:', url);

        try {
            await fetch('/open-external', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ url }),
            });
        } catch (error) {
            console.error('Failed to open in editor:', error);
        }
    }

    async function changePhpVersion(site, version) {
        try {
            const response = await fetch(`/servers/${serverId}/php`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ site, version }),
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert('Failed to change PHP version: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Failed to change PHP version:', error);
            alert('Failed to change PHP version');
        }
    }

    async function resetPhpVersion(site) {
        try {
            const response = await fetch(`/servers/${serverId}/php/reset`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ site }),
            });
            const result = await response.json();

            if (result.success) {
                location.reload();
            } else {
                alert('Failed to reset PHP version: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Failed to reset PHP version:', error);
            alert('Failed to reset PHP version');
        }
    }

    // Test connection on page load
    testConnection();
</script>
@endsection
