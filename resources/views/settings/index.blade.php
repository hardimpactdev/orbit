@extends('layouts.app')

@section('content')
<div class="p-6 max-w-2xl">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">Settings</h2>

    <form action="{{ route('settings.update') }}" method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
        @csrf

        <div class="mb-6">
            <label for="editor" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Code Editor
            </label>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                Select your preferred editor for opening remote projects via SSH.
            </p>
            <select name="editor" id="editor"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                @foreach($editorOptions as $scheme => $name)
                    <option value="{{ $scheme }}" {{ $editor['scheme'] === $scheme ? 'selected' : '' }}>
                        {{ $name }}
                    </option>
                @endforeach
            </select>
            @error('editor')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-6">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Requirements</h4>
            <ul class="text-sm text-gray-500 dark:text-gray-400 list-disc list-inside space-y-1">
                <li>Your editor must have the <strong>Remote - SSH</strong> extension installed</li>
                <li>SSH keys must be configured for passwordless authentication</li>
                <li>The editor's URL handler must be registered on your system</li>
            </ul>
        </div>

        <div class="flex justify-end">
            <button type="submit"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                Save Settings
            </button>
        </div>
    </form>

    <!-- SSH Keys Section -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white">SSH Public Keys</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Manage SSH keys for server provisioning.
                </p>
            </div>
            <button type="button" id="addKeyBtn"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm">
                Add Key
            </button>
        </div>

        @if($sshKeys->isEmpty())
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <svg class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
                <p>No SSH keys configured.</p>
                <p class="text-sm mt-1">Add a key to use when provisioning servers.</p>
            </div>
        @else
            <div class="space-y-3">
                @foreach($sshKeys as $key)
                    <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                        <div class="flex justify-between items-start">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-800 dark:text-white">{{ $key->name }}</span>
                                    @if($key->is_default)
                                        <span class="px-2 py-0.5 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded">Default</span>
                                    @endif
                                    <span class="text-xs text-gray-400">{{ $key->key_type }}</span>
                                </div>
                                <div class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $key->public_key }}">
                                    {{ Str::limit($key->public_key, 80) }}
                                </div>
                            </div>
                            <div class="flex items-center gap-1 ml-4">
                                <button type="button" class="copy-key-btn p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                        data-key="{{ $key->public_key }}" title="Copy">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    </svg>
                                </button>
                                @if(!$key->is_default)
                                    <form action="{{ route('ssh-keys.default', $key) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" class="p-1.5 text-gray-400 hover:text-blue-600" title="Set as default">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                            </svg>
                                        </button>
                                    </form>
                                @endif
                                <button type="button" class="edit-key-btn p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                        data-id="{{ $key->id }}" data-name="{{ $key->name }}" data-key="{{ $key->public_key }}" title="Edit">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>
                                <form action="{{ route('ssh-keys.destroy', $key) }}" method="POST" class="inline"
                                      onsubmit="return confirm('Delete this SSH key?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-gray-400 hover:text-red-600" title="Delete">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

@push('modals')
<!-- Add/Edit Key Modal -->
<div id="keyModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
    <div class="bg-white dark:bg-gray-800" style="border-radius: 8px; width: 100%; max-width: 500px; margin: 16px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
        <form id="keyForm" method="POST">
            @csrf
            <input type="hidden" name="_method" id="keyFormMethod" value="POST">

            <div class="border-gray-200 dark:border-gray-700" style="padding: 16px 24px; border-bottom-width: 1px;">
                <h3 class="text-gray-800 dark:text-white" style="font-size: 18px; font-weight: 600;" id="modalTitle">Add SSH Key</h3>
            </div>

            <div style="padding: 24px; display: flex; flex-direction: column; gap: 16px;">
                <div>
                    <label for="keyName" class="text-gray-700 dark:text-gray-300" style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px;">
                        Name
                    </label>
                    <input type="text" name="name" id="keyName" required
                           placeholder="e.g., MacBook Pro, Work Laptop"
                           class="bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                           style="width: 100%; padding: 8px 12px; border-width: 1px; border-radius: 8px;">
                </div>

                @if(count($availableSshKeys) > 0)
                    <div>
                        <label class="text-gray-700 dark:text-gray-300" style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px;">
                            Import from ~/.ssh/
                        </label>
                        <select id="importKeySelect"
                                class="bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                                style="width: 100%; padding: 8px 12px; border-width: 1px; border-radius: 8px;">
                            <option value="">-- Select a key to import --</option>
                            @foreach($availableSshKeys as $filename => $keyInfo)
                                <option value="{{ $keyInfo['content'] }}" data-name="{{ pathinfo($filename, PATHINFO_FILENAME) }}">
                                    {{ $filename }} ({{ $keyInfo['type'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label for="keyPublicKey" class="text-gray-700 dark:text-gray-300" style="display: block; font-size: 14px; font-weight: 500; margin-bottom: 4px;">
                        Public Key
                    </label>
                    <textarea name="public_key" id="keyPublicKey" rows="4" required
                              placeholder="ssh-ed25519 AAAA... user@host"
                              class="bg-white dark:bg-gray-700 text-gray-900 dark:text-white border-gray-300 dark:border-gray-600"
                              style="width: 100%; padding: 8px 12px; border-width: 1px; border-radius: 8px; font-family: monospace; font-size: 14px;"></textarea>
                </div>
            </div>

            <div class="border-gray-200 dark:border-gray-700" style="padding: 16px 24px; border-top-width: 1px; display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" id="cancelKeyBtn"
                        class="text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600"
                        style="padding: 8px 16px; border-width: 1px; border-radius: 8px;">
                    Cancel
                </button>
                <button type="submit"
                        class="bg-blue-600 hover:bg-blue-700 text-white"
                        style="padding: 8px 16px; border-radius: 8px; border: none;">
                    Save Key
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    const modal = document.getElementById('keyModal');
    const form = document.getElementById('keyForm');
    const methodInput = document.getElementById('keyFormMethod');
    const titleEl = document.getElementById('modalTitle');
    const nameInput = document.getElementById('keyName');
    const keyInput = document.getElementById('keyPublicKey');
    const importSelect = document.getElementById('importKeySelect');

    function openModal() {
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    // Add Key button
    document.getElementById('addKeyBtn').addEventListener('click', function() {
        titleEl.textContent = 'Add SSH Key';
        form.action = '{{ route("ssh-keys.store") }}';
        methodInput.value = 'POST';
        nameInput.value = '';
        keyInput.value = '';
        if (importSelect) importSelect.value = '';
        openModal();
    });

    // Cancel button
    document.getElementById('cancelKeyBtn').addEventListener('click', closeModal);

    // Edit buttons
    document.querySelectorAll('.edit-key-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            titleEl.textContent = 'Edit SSH Key';
            form.action = '/ssh-keys/' + this.dataset.id;
            methodInput.value = 'PUT';
            nameInput.value = this.dataset.name;
            keyInput.value = this.dataset.key;
            openModal();
        });
    });

    // Copy buttons
    document.querySelectorAll('.copy-key-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            navigator.clipboard.writeText(this.dataset.key);
        });
    });

    // Import select
    if (importSelect) {
        importSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value) {
                keyInput.value = option.value;
                if (option.dataset.name && !nameInput.value) {
                    nameInput.value = option.dataset.name;
                }
            }
        });
    }

    // Close on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    // Close on backdrop click
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
})();
</script>
@endpush
