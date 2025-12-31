<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CliUpdateService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function __construct(
        protected CliUpdateService $cliUpdate,
    ) {}

    public function index()
    {
        $editor = Setting::getEditor();
        $editorOptions = Setting::getEditorOptions();
        $cliStatus = $this->cliUpdate->getStatus();

        return view('settings.index', compact('editor', 'editorOptions', 'cliStatus'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'editor' => 'required|string|in:' . implode(',', array_keys(Setting::getEditorOptions())),
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

    public function cliUpdate()
    {
        $result = $this->cliUpdate->checkAndUpdate();
        return response()->json($result);
    }
}
