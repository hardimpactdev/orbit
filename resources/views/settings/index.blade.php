@extends('layouts.app')

@section('content')
<div class="p-6 max-w-2xl">
    <h2 class="text-2xl font-bold text-gray-800 dark:text-white mb-6">Settings</h2>

    <form action="{{ route('settings.update') }}" method="POST" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
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
</div>
@endsection
