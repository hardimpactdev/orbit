@extends('layouts.app')

@section('content')
<div class="p-6">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-white">Servers</h2>
        <a href="{{ route('servers.create') }}"
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Server
        </a>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Host</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Connected</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($servers as $server)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $server->name }}</span>
                                @if($server->is_default)
                                    <span class="ml-2 px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded">Default</span>
                                @endif
                                @if($server->is_local)
                                    <span class="ml-2 px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Local</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                            @if($server->is_local)
                                localhost
                            @else
                                {{ $server->user }}@{{ $server->host }}:{{ $server->port }}
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="w-3 h-3 inline-block rounded-full bg-gray-300" id="status-{{ $server->id }}"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                            {{ $server->last_connected_at?->diffForHumans() ?? 'Never' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <a href="{{ route('servers.show', $server) }}" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                            <a href="{{ route('servers.edit', $server) }}" class="text-gray-600 hover:text-gray-900 mr-3">Edit</a>
                            <form action="{{ route('servers.destroy', $server) }}" method="POST" class="inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('Are you sure?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                            No servers configured yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
