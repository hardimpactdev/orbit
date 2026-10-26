<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessTarget;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Node;
use App\Models\Process;

function docker_renderer_target(bool $onDemand = false): ProcessTarget
{
    return new ProcessTarget(
        node: new Node(['name' => 'dev']),
        user: 'orbit',
        checkoutPath: '/home/orbit/apps/docs',
        onDemandHostStart: $onDemand,
    );
}

it('renders one Orbit-owned Docker container definition with explicit runtime input', function (): void {
    $process = new Process([
        'name' => 'queue',
        'runtime_config' => [
            'image' => 'redis:8-alpine',
            'command' => ['redis-server', '--save', ''],
            'environment' => ['QUEUE_NAME' => 'critical jobs'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [
                ['source' => 'redis-data', 'target' => '/data', 'read_only' => false],
                ['source' => '/home/orbit/redis.conf', 'target' => '/etc/redis.conf', 'read_only' => true],
            ],
        ],
        'working_directory' => '/data',
        'restart_policy' => 'unless-stopped',
    ]);
    $process->id = 41;

    $renderer = new DockerProcessRenderer;

    expect($renderer->containerName($process))
        ->toBe('orbit-process-41-queue')
        ->and($renderer->containerInput($process, docker_renderer_target()))
        ->toBe([
            'image' => 'redis:8-alpine',
            'command' => ['redis-server', '--save', ''],
            'restart' => 'unless-stopped',
            'working_directory' => '/data',
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'mounts' => [
                ['source' => 'redis-data', 'target' => '/data', 'read_only' => false],
                ['source' => '/home/orbit/redis.conf', 'target' => '/etc/redis.conf', 'read_only' => true],
            ],
            'environment' => ['QUEUE_NAME' => 'critical jobs'],
        ]);
});

it('uses canonical Docker environment order for the definition and specification hashes', function (): void {
    $process = static function (array $environment): Process {
        $process = new Process([
            'name' => 'worker',
            'runtime_config' => ['image' => 'busybox:1', 'command' => ['env'], 'environment' => $environment],
            'working_directory' => '/work',
            'restart_policy' => 'never',
        ]);
        $process->id = 9;

        return $process;
    };
    $first = $process(['ZEBRA' => 'last', 'ALPHA' => 'first']);
    $second = $process(['ALPHA' => 'first', 'ZEBRA' => 'last']);
    $renderer = new DockerProcessRenderer;
    $target = docker_renderer_target();

    expect($renderer->specHash($first, $target))
        ->toBe($renderer->specHash($second, $target))
        ->and($renderer->containerInput($first, $target))
        ->toBe($renderer->containerInput($second, $target))
        ->and(array_keys($renderer->containerInput($first, $target)['environment']))
        ->toBe(['ALPHA', 'ZEBRA']);
});

it('rejects an option-like image from persisted runtime configuration', function (): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => ['image' => '--privileged', 'command' => ['sleep', '60']],
        'working_directory' => '/work',
        'restart_policy' => 'never',
    ]);
    $process->id = 10;

    expect(fn () => new DockerProcessRenderer()->containerInput($process, docker_renderer_target()))
        ->toThrow(InvalidArgumentException::class, 'image');
});

it('rejects unsafe Docker environment input from persisted configuration', function (mixed $environment): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => [
            'image' => 'busybox:1',
            'command' => ['env'],
            'environment' => $environment,
        ],
        'working_directory' => '/work',
        'restart_policy' => 'never',
    ]);
    $process->id = 10;

    expect(fn () => new DockerProcessRenderer()->containerInput($process, docker_renderer_target()))
        ->toThrow(InvalidArgumentException::class, 'environment');
})->with([
    'unsafe name' => [['BAD-NAME' => 'value']],
    'leading digit' => [['1APP_KEY' => 'value']],
    'assignment in name' => [['APP=KEY' => 'value']],
    'line feed' => [['APP_KEY' => "first\nsecond"]],
    'carriage return' => [['APP_KEY' => "first\rsecond"]],
    'null byte' => [['APP_KEY' => "first\0second"]],
    'not a map' => ['APP_KEY=unsafe-shape'],
]);

it('maps common restart policies to Docker restart policies', function (string $policy, string $expected): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => ['image' => 'busybox:1', 'command' => ['sleep', '60']],
        'working_directory' => '/work',
        'restart_policy' => $policy,
    ]);
    $process->id = 2;

    expect(new DockerProcessRenderer()->containerInput($process, docker_renderer_target())['restart'])->toBe($expected);
})->with([
    'never' => ['never', 'no'],
    'on failure' => ['on-failure', 'on-failure'],
    'always' => ['always', 'always'],
    'unless stopped' => ['unless-stopped', 'unless-stopped'],
]);

it('maps on-demand app-dev always to unless-stopped so an idle stop survives daemon restart', function (): void {
    $process = new Process([
        'name' => 'worker',
        'runtime_config' => ['image' => 'busybox:1', 'command' => ['sleep', '60']],
        'working_directory' => '/work',
        'restart_policy' => 'always',
    ]);
    $process->id = 3;

    expect(new DockerProcessRenderer()->containerInput($process, docker_renderer_target(onDemand: true))['restart'])->toBe('unless-stopped');
});
