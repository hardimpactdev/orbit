<?php

namespace App\Providers;

use App\Models\UserPreference;
use Native\Laravel\Contracts\ProvidesPhpIni;
use Native\Laravel\Facades\MenuBar;
use Native\Laravel\Facades\Window;
use Native\Laravel\Menu\Menu;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        Window::open()
            ->title('Launchpad')
            ->width(1200)
            ->height(800)
            ->minWidth(800)
            ->minHeight(600)
            ->titleBarHiddenInset()
            ->trafficLightPosition(16, 18)
            ->backgroundColor('#09090b');

        // Create menu bar if enabled in user preferences
        $this->initializeMenuBar();
    }

    /**
     * Initialize menu bar based on user preference.
     */
    protected function initializeMenuBar(): void
    {
        // Check if menu bar is enabled (defaults to false)
        try {
            $menuBarEnabled = UserPreference::getValue('menu_bar_enabled', false);
        } catch (\Exception) {
            // Database might not be ready yet, skip menu bar
            return;
        }

        if (! $menuBarEnabled) {
            return;
        }

        MenuBar::create()
            ->showDockIcon()
            ->withContextMenu(
                Menu::new()
                    ->label('Open Launchpad')
                    ->link(url('/'))
                    ->separator()
                    ->label('New Project')
                    ->link(url('/projects/create'))
                    ->separator()
                    ->label('Settings')
                    ->link(url('/settings'))
                    ->separator()
                    ->quit()
            );
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
