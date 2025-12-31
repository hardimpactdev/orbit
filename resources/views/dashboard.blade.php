@extends('layouts.app')

@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Dashboard</h2>
        <a href="{{ route('servers.create') }}"
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Server
        </a>
    </div>

    @if($servers->isEmpty())
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-8 text-center">
            <svg class="w-16 h-16 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No servers configured</h3>
            <p class="text-gray-500 dark:text-gray-400 mb-4">Get started by adding your first server.</p>
            <a href="{{ route('servers.create') }}"
               class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add Server
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($servers as $server)
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 hover:shadow-lg transition-shadow"
                     id="server-card-{{ $server->id }}">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $server->name }}</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if($server->is_local)
                                    Local
                                @else
                                    {{ $server->user }}@{{ $server->host }}
                                @endif
                            </p>
                        </div>
                        <div class="flex items-center space-x-2">
                            @if($server->is_default)
                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded">Default</span>
                            @endif
                            <span class="w-3 h-3 rounded-full bg-gray-300" id="status-dot-{{ $server->id }}"></span>
                        </div>
                    </div>

                    <div class="flex space-x-2">
                        <a href="{{ route('servers.show', $server) }}"
                           class="flex-1 text-center py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-200 dark:hover:bg-gray-600">
                            View
                        </a>
                        <button onclick="testConnection({{ $server->id }})"
                                class="flex-1 text-center py-2 text-sm bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-200 rounded hover:bg-blue-200 dark:hover:bg-blue-800">
                            Test
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
    async function testConnection(serverId) {
        const dot = document.getElementById(`status-dot-${serverId}`);
        dot.className = 'w-3 h-3 rounded-full bg-yellow-400 animate-pulse';

        try {
            const response = await fetch(`/servers/${serverId}/test-connection`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });

            const result = await response.json();
            dot.className = result.success
                ? 'w-3 h-3 rounded-full bg-green-500'
                : 'w-3 h-3 rounded-full bg-red-500';
        } catch (error) {
            dot.className = 'w-3 h-3 rounded-full bg-red-500';
        }
    }
</script>
@endsection
