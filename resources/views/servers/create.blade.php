@extends('layouts.app')

@section('content')
<div class="p-6 max-w-2xl">
    <div class="mb-6">
        <a href="{{ route('servers.index') }}" class="text-blue-600 hover:text-blue-800 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
            Back to Servers
        </a>
    </div>

    <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">Add Server</h2>

    <form action="{{ route('servers.store') }}" method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        @csrf

        <div class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                       placeholder="My Server">
                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center mb-4">
                <input type="checkbox" name="is_local" id="is_local" value="1" {{ old('is_local') ? 'checked' : '' }}
                       class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                       onchange="toggleLocalFields()">
                <label for="is_local" class="ml-2 text-sm text-gray-700 dark:text-gray-300">This is a local machine</label>
            </div>

            <div id="remote-fields">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="host" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Host</label>
                        <input type="text" name="host" id="host" value="{{ old('host', 'localhost') }}"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                               placeholder="192.168.1.100 or server.example.com">
                        @error('host')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="port" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Port</label>
                        <input type="number" name="port" id="port" value="{{ old('port', 22) }}"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        @error('port')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label for="user" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">SSH User</label>
                    <input type="text" name="user" id="user" value="{{ old('user', 'root') }}"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                    @error('user')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="flex items-center">
                <input type="checkbox" name="is_default" id="is_default" value="1" {{ old('is_default') ? 'checked' : '' }}
                       class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                <label for="is_default" class="ml-2 text-sm text-gray-700 dark:text-gray-300">Set as default server</label>
            </div>
        </div>

        <div class="mt-6 flex justify-end space-x-3">
            <a href="{{ route('servers.index') }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Cancel
            </a>
            <button type="submit"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Add Server
            </button>
        </div>
    </form>
</div>

<script>
    function toggleLocalFields() {
        const isLocal = document.getElementById('is_local').checked;
        const remoteFields = document.getElementById('remote-fields');
        remoteFields.style.display = isLocal ? 'none' : 'block';
    }

    // Initialize on page load
    toggleLocalFields();
</script>
@endsection
