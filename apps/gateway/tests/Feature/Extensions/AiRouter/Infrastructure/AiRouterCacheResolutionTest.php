<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

it('resolves the production cache without reading Settings until its first operation', function (): void {
    expect(app(AiRouterState::class)->cacheConnection())->toBeNull();
    app()->instance('env', 'production');
    app()->forgetInstance(AiRouterCache::class);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $cache = app(AiRouterCache::class);

    expect($queries)->toBe([]);
    $cache->put('fixture:snapshot', 'first-value', 60);
    expect($queries)->toHaveCount(1);
    expect($cache->get('fixture:snapshot'))->toBe('first-value');
    $cache->put('fixture:updated', 'second-value');
    expect($cache->get('fixture:updated'))->toBe('second-value');
    expect($queries)->toHaveCount(1);
});
