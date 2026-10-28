<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Infrastructure\Tools\AptToolManager;
use App\Infrastructure\Tools\ComposerToolManager;
use App\Infrastructure\Tools\HomebrewCaskToolManager;
use App\Infrastructure\Tools\HomebrewToolManager;
use App\Infrastructure\Tools\NativeToolInspector;
use App\Infrastructure\Tools\NativeToolManagerScopeLock;
use App\Infrastructure\Tools\SwampToolManagerMaterializer;
use App\Infrastructure\Tools\ToolCommandBudget;
use App\Infrastructure\Tools\VpToolManager;
use Illuminate\Support\ServiceProvider;

final class ToolsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ToolInspector::class => NativeToolInspector::class,
        ToolManagerMaterializer::class => SwampToolManagerMaterializer::class,
    ];

    public function register(): void
    {
        $this->app->scoped(ToolManagerScopeLock::class, NativeToolManagerScopeLock::class);
        $this->app->singleton(ToolCommandBudget::class);
        $this->app->singleton(
            ToolManagerRegistry::class,
            static fn (): ToolManagerRegistry => new ToolManagerRegistry([
                app(AptToolManager::class),
                app(VpToolManager::class),
                app(ComposerToolManager::class),
                app(HomebrewToolManager::class),
                app(HomebrewCaskToolManager::class),
            ]),
        );
    }
}
