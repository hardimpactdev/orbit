<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Extensions\ExtensionStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

/** The model router that the tasks extension needs before it can be enabled: ai-router on and set up with a management key. */
final class TaskModelRouter
{
    public static function ready(): void
    {
        app(ExtensionStore::class)->set('ai-router', true);
        app(AiRouterState::class)->enable(1, 'valkey', 'http://10.44.0.3:8317', 'management-key', 'read-token', 'control-token');
    }
}
