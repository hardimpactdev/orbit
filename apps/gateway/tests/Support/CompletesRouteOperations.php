<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Operation;
use App\Models\Route;
use Illuminate\Testing\TestResponse;

/** Existing Route behavior cases assert the admitted Operation, then read its resulting Gateway state. */
function completesRouteOperations(): void
{
    TestResponse::macro('completeRoute', function (): TestResponse {
        if ($this->status() !== 202 || ! str_starts_with($this->json('data.kind') ?? '', 'route.')) {
            return $this;
        }
        $test = \test();
        $test->routeSwamp ??= new RouteSwampGatewayFixture($test);
        $operation = $test->routeSwamp->finish($this);
        $test->lastRouteOperation = $operation;
        if ($operation->status->value !== 'succeeded') {
            return $test->getJson('/api/v1/operations/'.$operation->id);
        }
        $id = $operation->result['route_id'] ?? $operation->target_id;
        if (! Route::query()->whereKey($id)->exists()) {
            $test->getJson('/api/v1/routes/'.$id)->assertNotFound();

            return $test->getJson('/api/v1/operations/'.$operation->id);
        }

        return $test->getJson('/api/v1/routes/'.$id)->assertOk();
    });
}
