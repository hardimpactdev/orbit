<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SshKey;
use App\Services\CliUpdateService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        protected CliUpdateService $cliUpdate,
    ) {}

    public function index(): Response
    {
        $editor = Setting::getEditor();
        $editorOptions = Setting::getEditorOptions();
        $cliStatus = $this->cliUpdate->getStatus();
        $sshKeys = SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = Setting::getAvailableSshKeys();

        return Inertia::render('Settings', [
            'editor' => $editor,
            'editorOptions' => $editorOptions,
            'cliStatus' => $cliStatus,
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'editor' => 'required|string|in:'.implode(',', array_keys(Setting::getEditorOptions())),
        ]);

        $editorOptions = Setting::getEditorOptions();

        Setting::set('editor_scheme', $validated['editor']);
        Setting::set('editor_name', $editorOptions[$validated['editor']]);

        return redirect()->route('settings.index')
            ->with('success', 'Settings saved successfully.');
    }

    public function cliStatus()
    {
        return response()->json($this->cliUpdate->getStatus());
    }

    public function cliInstall()
    {
        $result = $this->cliUpdate->ensureInstalled();

        return response()->json($result);
    }

    public function cliUpdate()
    {
        $result = $this->cliUpdate->checkAndUpdate();

        return response()->json($result);
    }
}
