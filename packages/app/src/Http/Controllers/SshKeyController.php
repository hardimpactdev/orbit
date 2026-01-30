<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Setting;
use HardImpact\Orbit\Core\Models\SshKey;
use Illuminate\Http\Request;

class SshKeyController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'public_key' => 'required|string',
        ]);

        // Validate it looks like an SSH public key
        if (! str_starts_with(trim((string) $validated['public_key']), 'ssh-')) {
            return back()->withErrors(['public_key' => 'Invalid SSH public key format.']);
        }

        $key = SshKey::create([
            'name' => $validated['name'],
            'public_key' => trim((string) $validated['public_key']),
            'is_default' => SshKey::count() === 0, // First key is default
        ]);

        return redirect()->route('settings.index')
            ->with('success', "SSH key '{$key->name}' added successfully.");
    }

    public function update(Request $request, SshKey $sshKey)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'public_key' => 'required|string',
        ]);

        if (! str_starts_with(trim((string) $validated['public_key']), 'ssh-')) {
            return back()->withErrors(['public_key' => 'Invalid SSH public key format.']);
        }

        $sshKey->update([
            'name' => $validated['name'],
            'public_key' => trim((string) $validated['public_key']),
        ]);

        return redirect()->route('settings.index')
            ->with('success', "SSH key '{$sshKey->name}' updated successfully.");
    }

    public function destroy(SshKey $sshKey)
    {
        $name = $sshKey->name;
        $wasDefault = $sshKey->is_default;

        $sshKey->delete();

        // If deleted key was default, set a new default
        if ($wasDefault) {
            $newDefault = SshKey::first();
            $newDefault?->update(['is_default' => true]);
        }

        return redirect()->route('settings.index')
            ->with('success', "SSH key '{$name}' deleted successfully.");
    }

    public function setDefault(SshKey $sshKey)
    {
        $sshKey->setAsDefault();

        return redirect()->route('settings.index')
            ->with('success', "'{$sshKey->name}' is now the default SSH key.");
    }

    public function getAvailableKeys()
    {
        return response()->json(Setting::getAvailableSshKeys());
    }
}
