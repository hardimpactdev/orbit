<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('completes a stdin-bound operation token round trip inside the real gateway container', function (): void {
    $docker = new Process(['docker', 'info']);
    $docker->run();

    if (! $docker->isSuccessful()) {
        $this->markTestSkipped('Docker is required for the gateway-container operation-token regression.');
    }

    $image = getenv('ORBIT_GATEWAY_TEST_IMAGE');
    $image = is_string($image) && $image !== '' ? $image : 'orbit-gateway:prepared-current';
    $inspect = new Process(['docker', 'image', 'inspect', $image]);
    $inspect->run();

    if (! $inspect->isSuccessful()) {
        $this->markTestSkipped("Gateway test image '{$image}' is unavailable.");
    }

    $repoRoot = dirname(base_path(), levels: 2);
    $container = 'orbit-gateway-token-'.bin2hex(random_bytes(6));
    $start = new Process([
        'docker',
        'run',
        '--rm',
        '--detach',
        '--name',
        $container,
        '--volume',
        "{$repoRoot}:/srv/orbit",
        '--entrypoint',
        'tail',
        $image,
        '-f',
        '/dev/null',
    ]);
    $start->setTimeout(60);
    $start->run();

    expect($start->isSuccessful())
        ->toBeTrue($start->getErrorOutput());

    $previousContainer = getenv('ORBIT_GATEWAY_CONTAINER');
    putenv("ORBIT_GATEWAY_CONTAINER={$container}");
    config()->set('orbit.local_executor_binary', '/srv/orbit/apps/cli/orbit');

    try {
        $node = Node::factory()
            ->gateway()
            ->managed()
            ->create([
                'name' => 'gateway-main',
                'orbit_path' => '/srv/orbit',
            ]);

        $result = app(RemoteLocalExecutor::class)->runInternal(
            node: $node,
            commandName: 'internal:caddy-config',
            arguments: ['read-global'],
            transportOptions: [
                'input' => '{}',
                'timeout' => 30,
            ],
        );
        expect($result->exitCode)
            ->toBe(0, "stdout:\n{$result->stdout}\nstderr:\n{$result->stderr}");

        $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        $run = DB::table('operation_runs')->latest('created_at')->first();

        expect($payload['success']['data']['exists'] ?? null)
            ->toBeBool()
            ->and($run?->operation_token_consumed_at)
            ->not->toBeNull();
    } finally {
        $stop = new Process(['docker', 'rm', '--force', $container]);
        $stop->run();

        putenv(
            $previousContainer === false
                ? 'ORBIT_GATEWAY_CONTAINER'
                : "ORBIT_GATEWAY_CONTAINER={$previousContainer}",
        );
    }
})->group('slow', 'gateway-container');
