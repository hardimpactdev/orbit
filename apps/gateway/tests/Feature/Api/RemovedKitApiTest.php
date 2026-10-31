<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('returns 404 for retired executor and Gateway resume endpoints', function (string $method, string $path): void {
    $this->json($method, '/api/v1/'.$path)->assertNotFound()->assertJsonPath('error.code', 'http.404');
})->with([
    ['GET', 'operations/1/instance-lifecycle'],
    ['GET', 'operation-executor/claimable'],
    ['POST', 'operation-executor/claim'],
    ['GET', 'operation-executor/1/children'],
    ['GET', 'operation-executor/1/receipts/install'],
    ['POST', 'operation-executor/1/heartbeat'],
    ['POST', 'operation-executor/1/step'],
    ['POST', 'operation-executor/1/complete'],
    ['POST', 'operation-executor/1/run'],
    ['POST', 'operation-executor/1/children'],
    ['POST', 'operation-executor/1/wait'],
    ['GET', 'task-vm-executor/1/lifecycle'],
    ['GET', 'task-vm-executor/1/runtime'],
    ['POST', 'operations/1/resume'],
]);

it('does not register the retired executor health command', function (): void {
    expect(Artisan::all())->not->toHaveKey('orbit:operations-check');
});
