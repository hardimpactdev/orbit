<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

it('removes the Gateway executor HTTP surface', function (string $method, string $path): void {
    $this->json($method, '/api/v1/operation-executor'.$path)->assertNotFound();
})->with([
    ['GET', '/claimable'], ['POST', '/claim'], ['POST', '/1/heartbeat'],
    ['POST', '/1/run'], ['POST', '/1/children'], ['GET', '/1/children'], ['POST', '/1/wait'],
]);

it('retains the direct intent and private-material route boundary', function (): void {
    $uris = array_map(fn ($route): string => $route->uri(), Route::getRoutes()->getRoutes());
    expect($uris)->toContain('api/v1/direct-operations/{operation}', 'api/v1/direct-operations/{operation}/private-material', 'api/v1/direct-operations/{operation}/result');
});
