<?php

declare(strict_types=1);

use App\Domain\Logs\LogReadLimit;
use App\Domain\Logs\LogStreamTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Instances\RemoteInstanceLogReader;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

it('reads bounded whole lines from the application directory for one-shot and live logs', function (string $root, string $suffix, bool $production): void {
    $full = implode("\n", array_map(static fn (int $i): string => sprintf('%04d %s', $i, str_repeat('y', 9_995)), range(1, 500)))."\n";
    $agents = new FakeAgentCommandRunner()->answer('instance.read-application-log', ['text' => substr($full, -LogReadLimit::Bytes)]);
    $reader = new RemoteInstanceLogReader($agents);
    $node = Node::query()->create(['name' => 'app-dev', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.40', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.40']);
    $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/home/orbit/apps/shop/main', 'status' => 'active']);

    $node->roles()->create(['role' => $production ? 'app-prod' : 'app-dev', 'status' => LifecycleStatus::Active]);
    $instance->update([
        'root' => $root,
        'source_is_laravel' => true,
        ...($production ? ['checkout_path' => '/home/shop/releases/initial', 'production_home' => '/home/shop', 'production_user' => 'shop'] : []),
    ]);
    $expected = ($production ? '/home/shop/current' : '/home/orbit/apps/shop/main').$suffix;

    $lines = explode("\n", rtrim($reader->tail($instance, 1000), "\n"));
    $stream = app(LogStreamTargetResolver::class)->forInstance($instance);

    expect($agents->calls[0]['input'])->toBe(['directory' => $expected, 'lines' => 1000, 'max_bytes' => LogReadLimit::Bytes]);
    expect($stream->source->path)->toBe($expected);

    expect(array_filter($lines, static fn (string $line): bool => strlen($line) !== 10_000))->toBe([])
        ->and(end($lines))->toStartWith('0500 ');
})->with([
    'nested development' => ['server/web/public', '/server/web', false],
    'root public development' => ['public', '', false],
    'nested production' => ['server/web/public', '/server/web', true],
    'root public production' => ['public', '', true],
]);
