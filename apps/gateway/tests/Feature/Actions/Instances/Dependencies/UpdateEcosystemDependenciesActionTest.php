<?php

declare(strict_types=1);

use App\Actions\Instances\Dependencies\UpdateEcosystemDependenciesAction;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

// The Gateway's part of one ecosystem's update: which Vite+ it trusts and how it reads each answer. The agent's Rust
// tests in apps/agent/src/commands/dependencies_tests.rs prove each probe and update.

function ecosystem_update_instance(): Instance
{
    $project = Project::query()->create(['slug' => 'ecosystem-update', 'name' => 'Ecosystem update', 'repository_url' => 'https://example.test/update.git']);
    $node = Node::query()->create(['name' => 'ecosystem-update', 'public_ssh_host' => '192.0.2.182', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit', 'status' => 'active']);
    orbit_test_set_app_placement_role($node, false);

    return $project->instances()->create([
        'node_id' => $node->id, 'name' => 'development', 'environment' => 'development',
        'checkout_path' => '/home/orbit/project', 'source_layout' => 'checkout', 'status' => 'active',
    ]);
}

it('trusts only a verified Vite+ that Orbit manages', function (?array $vp, ?string $errorCode, ?string $path): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.probe-dependency-update', ['status' => 'present', 'vp' => $vp]);
    $inspection = new UpdateEcosystemDependenciesAction($agents)->inspect(ecosystem_update_instance(), 'pnpm');

    expect($inspection->errorCode)->toBe($errorCode)
        ->and($inspection->toolPath)->toBe($path)
        ->and($agents->calls[0]['input'])->toBe(['directory' => '/home/orbit/project', 'ecosystem' => 'pnpm']);
})->with([
    'account install' => [['path' => '/home/orbit/.local/share/vite-plus/bin/vp', 'version' => '0.3.0'], null, '/home/orbit/.local/share/vite-plus/bin/vp'],
    'unverified version' => [['path' => '/opt/orbit/vite-plus/bin/vp', 'version' => '0.4.0'], 'dependencies.unsupported_delegation', null],
    'unmanaged path' => [['path' => '/tmp/vp', 'version' => '0.3.0'], 'dependencies.unreadable_source', null],
    'none' => [null, 'dependencies.unsupported_delegation', null],
]);

it('reads the update exit status and a runner timeout', function (?int $exitCode, string $status, ?string $errorCode): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.update-dependencies', fn (): array => $exitCode === null
        ? throw new AgentRunnerFailure('instances.update-dependencies', AgentRunnerFailure::Timeout, 'slow')
        : ['exit_code' => $exitCode]);
    $result = new UpdateEcosystemDependenciesAction($agents)->apply(ecosystem_update_instance(), 'npm', '/opt/orbit/vite-plus/bin/vp');

    expect($result->status->value)->toBe($status)
        ->and($result->errorCode)->toBe($errorCode)
        ->and($agents->calls[0]['input'])->toBe(['directory' => '/home/orbit/project', 'ecosystem' => 'npm', 'vp' => '/opt/orbit/vite-plus/bin/vp']);
})->with([
    'succeeded' => [0, 'succeeded', null],
    'failed' => [1, 'failed', 'dependencies.update_failed'],
    'deadline' => [124, 'failed', 'dependencies.update_timeout'],
    'runner timeout' => [null, 'failed', 'dependencies.update_timeout'],
]);
