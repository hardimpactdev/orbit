<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gateway\Requests;

use App\Services\Gateway\GatewayRequest;
use App\Services\Gateway\Requests\ListNodesRequest;

it('returns GET method', function (): void {
    $request = new ListNodesRequest;

    expect($request->method())->toBe('GET');
});

it('returns the correct path', function (): void {
    $request = new ListNodesRequest;

    expect($request->path())->toBe('/api/nodes');
});

it('returns empty query when no filters are set', function (): void {
    $request = new ListNodesRequest;

    expect($request->query())->toBe([]);
});

it('includes role filter in query when set', function (): void {
    $request = new ListNodesRequest(role: 'app');

    expect($request->query())->toBe(['role' => 'app']);
});

it('includes environment filter in query when set', function (): void {
    $request = new ListNodesRequest(environment: 'production');

    expect($request->query())->toBe(['environment' => 'production']);
});

it('includes both filters in query when set', function (): void {
    $request = new ListNodesRequest(role: 'app', environment: 'development');

    expect($request->query())->toBe([
        'role' => 'app',
        'environment' => 'development',
    ]);
});

it('excludes null filters from query', function (): void {
    $request = new ListNodesRequest(role: 'gateway');

    expect($request->query())->toBe(['role' => 'gateway']);
});

it('returns empty data array', function (): void {
    $request = new ListNodesRequest;

    expect($request->data())->toBe([]);
});

it('implements GatewayRequest', function (): void {
    $request = new ListNodesRequest;

    expect($request)->toBeInstanceOf(GatewayRequest::class);
});
