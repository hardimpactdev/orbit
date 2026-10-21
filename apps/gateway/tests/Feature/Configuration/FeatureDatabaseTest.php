<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('defers feature database setup until the first query and still migrates the real schema', function (): void {
    expect(RefreshDatabaseState::$lazilyRefreshed)->toBeFalse();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(Schema::hasTable('nodes'))->toBeTrue();

    expect(RefreshDatabaseState::$lazilyRefreshed)->toBeTrue();
    expect($queries)->not->toBeEmpty();
});
