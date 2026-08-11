<?php

declare(strict_types=1);

use App\Services\Support\GatewayActionResult;

it('builds a successful gateway action envelope directly', function (): void {
    $result = GatewayActionResult::success(
        data: ['node' => ['name' => 'app-1']],
        meta: ['request_id' => 'request-1'],
    );

    expect($result->exitCode)
        ->toBe(0)
        ->and($result->successful())
        ->toBeTrue()
        ->and($result->payload)
        ->toBe([
            'success' => [
                'data' => ['node' => ['name' => 'app-1']],
                'meta' => ['request_id' => 'request-1'],
            ],
        ]);
});
