<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SshKey;
use App\Models\TemplateFavorite;
use App\Models\UserPreference;
use App\Services\CliUpdateService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        protected CliUpdateService $cliUpdate,
        protected NotificationService $notifications,
    ) {}

    public function index(): Response
    {
        $editor = Setting::getEditor();
        $editorOptions = Setting::getEditorOptions();
        $terminal = Setting::getTerminal();
        $terminalOptions = Setting::getTerminalOptions();
        $cliStatus = $this->cliUpdate->getStatus();
        $sshKeys = SshKey::orderBy('is_default', 'desc')->orderBy('name')->get();
        $availableSshKeys = Setting::getAvailableSshKeys();
        $templateFavorites = TemplateFavorite::orderByDesc('usage_count')->get();
        $notificationsEnabled = $this->notifications->isEnabled();
        $menuBarEnabled = UserPreference::getValue('menu_bar_enabled', false);

        return Inertia::render('Settings', [
            'editor' => $editor,
            'editorOptions' => $editorOptions,
            'terminal' => $terminal,
            'terminalOptions' => $terminalOptions,
            'cliStatus' => $cliStatus,
            'sshKeys' => $sshKeys,
            'availableSshKeys' => $availableSshKeys,
            'templateFavorites' => $templateFavorites,
            'notificationsEnabled' => $notificationsEnabled,
            'menuBarEnabled' => $menuBarEnabled,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'editor' => 'required|string|in:'.implode(',', array_keys(Setting::getEditorOptions())),
            'terminal' => 'required|string|in:'.implode(',', array_keys(Setting::getTerminalOptions())),
        ]);

        $editorOptions = Setting::getEditorOptions();

        Setting::set('editor_scheme', $validated['editor']);
        Setting::set('editor_name', $editorOptions[$validated['editor']]);
        Setting::set('terminal', $validated['terminal']);

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

    public function storeTemplate(Request $request)
    {
        $validated = $request->validate([
            'repo_url' => 'required|string|max:500',
            'display_name' => 'required|string|max:255',
        ]);

        // Check for duplicate
        if (TemplateFavorite::where('repo_url', $validated['repo_url'])->exists()) {
            return back()->withErrors(['repo_url' => 'This template already exists.']);
        }

        TemplateFavorite::create([
            'repo_url' => $validated['repo_url'],
            'display_name' => $validated['display_name'],
            'usage_count' => 0,
        ]);

        return redirect()->route('settings.index')
            ->with('success', 'Template added successfully.');
    }

    public function updateTemplate(Request $request, TemplateFavorite $template)
    {
        $validated = $request->validate([
            'display_name' => 'required|string|max:255',
        ]);

        $template->update([
            'display_name' => $validated['display_name'],
        ]);

        return redirect()->route('settings.index')
            ->with('success', 'Template updated successfully.');
    }

    public function destroyTemplate(TemplateFavorite $template)
    {
        $template->delete();

        return redirect()->route('settings.index')
            ->with('success', 'Template deleted.');
    }

    public function toggleNotifications(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        if ($validated['enabled']) {
            $this->notifications->enable();
        } else {
            $this->notifications->disable();
        }

        return redirect()->route('settings.index')
            ->with('success', 'Notification settings updated.');
    }

    public function toggleMenuBar(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        UserPreference::setValue('menu_bar_enabled', $validated['enabled']);

        return redirect()->route('settings.index')
            ->with('success', 'Menu bar settings updated. Restart the app for changes to take effect.');
    }
}
