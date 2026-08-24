<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use NckRtl\Toolbar\Data\Layout\GroupConfig;
use NckRtl\Toolbar\Data\Layout\LayoutConfig;
use NckRtl\Toolbar\Data\ToolbarConfig;
use NckRtl\Toolbar\Data\Tools\AgentationTool;
use NckRtl\Toolbar\Data\Tools\BreakpointIndicatorTool;
use NckRtl\Toolbar\Enums\Layout\Section;
use NckRtl\Toolbar\Toolbar;

class ToolbarConfigProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! class_exists(Toolbar::class)) {
            return;
        }
    }

    public function boot(): void
    {
        if (! class_exists(Toolbar::class)) {
            return;
        }

        if (! $this->app->bound(Toolbar::class)) {
            return;
        }

        $toolbar = $this->app->make(Toolbar::class);
        $this->update($toolbar->config);
    }

    public function update(ToolbarConfig $toolbarConfig): void
    {
        $toolbarConfig
            ->primaryColor('#F53003', '#FFFFFF')
            ->layout(function (LayoutConfig $layout) {
                $group = (new GroupConfig(priority: 20))
                    ->addTool(new BreakpointIndicatorTool(show_pixels: false))
                    ->section(Section::RIGHT);

                // The tool itself ships with the toolbar, but its runtime is served by the
                // optional nckrtl/laravel-toolbar-agentation addon. Without that addon the
                // tool would render and then 404 on its own assets, so only add it when
                // the addon is installed.
                if (self::agentationAddonInstalled()) {
                    $group->addTool(new AgentationTool);
                }

                $layout->addGroup($group);
            });
    }

    public static function agentationAddonInstalled(): bool
    {
        // Referenced as a string: the addon is optional, so the class is absent on a plain
        // `composer install` and static analysis must not require it to resolve.
        return class_exists('NckRtl\\Toolbar\\Agentation\\AgentationServiceProvider');
    }
}
