<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\Requests\ShowNodeRequest;

it('returns GET method', function (): void {
    $request = new ShowNodeRequest('app-1');

    expect($request->method())->toBe('GET');
});

it('returns the correct path with node name', function (): void {
    $request = new ShowNodeRequest('app-1');

    expect($request->path())->toBe('/api/nodes/app-1');
});

it('returns empty query array', function (): void {
    $request = new ShowNodeRequest('app-1');

    expect($request->query())->toBe([]);
});

it('returns empty data array', function (): void {
    $request = new ShowNodeRequest('app-1');

    expect($request->data())->toBe([]);
});

it('implements GatewayRequest', function (): void {
    $request = new ShowNodeRequest('app-1');

    expect($request)->toBeInstanceOf(GatewayRequest::class);
});

it('encodes special characters in node name', function (): void {
    $request = new ShowNodeRequest('node-with-dash');

    expect($request->path())->toBe('/api/nodes/node-with-dash');
});
