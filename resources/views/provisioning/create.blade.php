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

    <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-2">Provision New Server</h2>
    <p class="text-gray-600 dark:text-gray-400 mb-6">
        Set up a fresh server with the complete Launchpad stack. Requires root SSH access.
    </p>

    @if(session('error'))
        <div class="mb-6 p-4 bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-lg">
            <p class="font-semibold">Provisioning Failed</p>
            <p>{{ session('error') }}</p>
        </div>
    @endif

    @if(session('provisioning_log'))
        <div class="mb-6 p-4 bg-gray-100 dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-lg">
            <p class="font-semibold text-gray-800 dark:text-white mb-2">Provisioning Log</p>
            <div class="text-sm font-mono text-gray-600 dark:text-gray-400 space-y-1 max-h-48 overflow-y-auto">
                @foreach(session('provisioning_log') as $entry)
                    @if(isset($entry['step']))
                        <div class="text-blue-600 dark:text-blue-400">{{ $entry['step'] }}</div>
                    @elseif(isset($entry['info']))
                        <div class="text-gray-500">{{ $entry['info'] }}</div>
                    @elseif(isset($entry['error']))
                        <div class="text-red-600 dark:text-red-400">{{ $entry['error'] }}</div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    <form action="{{ route('provision.store') }}" method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6" id="provision-form">
        @csrf

        <div class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Server Name</label>
                <input type="text" name="name" id="name" value="{{ old('name') }}" required
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                       placeholder="Production Server">
                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="host" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Host IP Address</label>
                <input type="text" name="host" id="host" value="{{ old('host') }}" required
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                       placeholder="192.168.1.100">
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Must have root SSH access enabled
                </p>
                @error('host')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="ssh_public_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">SSH Public Key</label>
                @if(count($availableSshKeys) > 0)
                    <select id="ssh_key_select" onchange="updateSshKey()"
                            class="w-full px-3 py-2 mb-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="">Select a key...</option>
                        @foreach($availableSshKeys as $keyPath => $keyContent)
                            <option value="{{ $keyContent }}" {{ $sshPublicKey === $keyContent ? 'selected' : '' }}>
                                {{ basename($keyPath) }}
                            </option>
                        @endforeach
                        <option value="custom">Enter custom key...</option>
                    </select>
                @endif
                <textarea name="ssh_public_key" id="ssh_public_key" rows="3" required
                          class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white font-mono text-sm"
                          placeholder="ssh-rsa AAAA...">{{ old('ssh_public_key', $sshPublicKey) }}</textarea>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    This key will be added to the launchpad user on the server
                </p>
                @error('ssh_public_key')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 rounded-lg">
            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                <strong>Warning:</strong> This will make the following changes to the server:
            </p>
            <ul class="mt-2 text-sm text-yellow-700 dark:text-yellow-300 list-disc list-inside space-y-1">
                <li>Create a <code class="bg-yellow-100 dark:bg-yellow-900 px-1 rounded">launchpad</code> user with sudo access</li>
                <li>Disable SSH password authentication</li>
                <li>Disable root SSH login</li>
                <li>Install Docker</li>
                <li>Install and initialize Launchpad</li>
            </ul>
        </div>

        <div class="mt-6 flex justify-end space-x-3">
            <a href="{{ route('servers.index') }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Cancel
            </a>
            <button type="submit" id="provision-btn"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center">
                <span id="btn-text">Provision Server</span>
                <svg id="btn-spinner" class="hidden ml-2 w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>
        </div>
    </form>
</div>

<script>
    function updateSshKey() {
        const select = document.getElementById('ssh_key_select');
        const textarea = document.getElementById('ssh_public_key');

        if (select.value && select.value !== 'custom') {
            textarea.value = select.value;
        } else if (select.value === 'custom') {
            textarea.value = '';
            textarea.focus();
        }
    }

    document.getElementById('provision-form').addEventListener('submit', function() {
        const btn = document.getElementById('provision-btn');
        const btnText = document.getElementById('btn-text');
        const spinner = document.getElementById('btn-spinner');

        btn.disabled = true;
        btnText.textContent = 'Provisioning...';
        spinner.classList.remove('hidden');
    });
</script>
@endsection
