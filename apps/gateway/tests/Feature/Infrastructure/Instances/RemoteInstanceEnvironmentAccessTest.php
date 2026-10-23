<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

// The Gateway's part of environment access: what it asks the agent and how it reads each outcome. The agent's Rust
// tests in apps/agent/src/commands/environment_tests.rs prove the walk, the checks, and the rename.

function environment_access_context(bool $production = false): InstanceEnvironmentContext
{
    $node = new Node;
    $node->forceFill(['wireguard_ip' => '10.44.0.9', 'user' => 'orbit']);

    return new InstanceEnvironmentContext(
        instanceId: 1, projectId: 1, nodeId: 1, environment: $production ? 'production' : 'development',
        path: $production ? '/home/orbit-app-1' : '/home/orbit/apps/shop/main', executionUser: $production ? 'orbit-app-1' : 'orbit',
        laravel: true, routeId: 1, routeDomain: 'shop.test', nodeStatus: 'active', node: $node,
    );
}

it('checks and reads the recorded file as its user, and asks a production home to be the user home', function (bool $production): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.read-environment', fn (array $input): array => $input['mode'] === 'read'
        ? ['state' => 'ok', 'content' => base64_encode("APP_KEY=x\n"), 'installed' => false]
        : ['state' => 'ok', 'installed' => false]);
    $access = new RemoteInstanceEnvironmentAccess($agents);
    $context = environment_access_context($production);

    $access->assertEnvironmentReadable($context);
    $access->assertEnvironmentWritable($context, 4096);

    expect($access->read($context))->toBe("APP_KEY=x\n")
        ->and(array_column(array_column($agents->calls, 'input'), 'mode'))->toBe(['read-check', 'write-check', 'read'])
        ->and($agents->calls[1]['input'])->toBe([
            'mode' => 'write-check', 'base' => $context->path, 'user' => $context->executionUser,
            'maximum' => InstanceEnvironmentValidator::MaximumFileBytes, 'require_home' => $production, 'required_capacity' => 4096,
        ]);
})->with(['development' => false, 'production' => true]);

it('maps a missing file, a refusal, and a runner failure of a read to their errors', function (string $state, string $errorCode): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.read-environment', fn (): array => $state === 'runner'
        ? throw new AgentRunnerFailure('instances.read-environment', AgentRunnerFailure::Transport, 'password=secret')
        : ['state' => $state, 'installed' => false]);

    expect(fn () => new RemoteInstanceEnvironmentAccess($agents)->read(environment_access_context()))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe($errorCode)
            ->and($exception->getMessage())->not->toContain('secret'));
})->with([
    'missing' => ['missing', 'env.import_source_missing'],
    'refused' => ['refused', 'env.import_preflight_failed'],
    'runner failure' => ['runner', 'env.import_preflight_failed'],
]);

it('refuses a write destination the agent cannot confirm', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.read-environment', ['state' => 'refused', 'installed' => false]);

    expect(fn () => new RemoteInstanceEnvironmentAccess($agents)->assertEnvironmentWritable(environment_access_context(), 0))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('env.write_preflight_failed'));
});

it('reads each write outcome, and an outcome after the rename as unconfirmed', function (array $outcome, string $expected): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.write-environment', $outcome);
    $access = new RemoteInstanceEnvironmentAccess($agents);

    try {
        $result = $access->write(environment_access_context(), "APP_KEY=secret\n");
        $actual = $result->confirmed ? ($result->changed ? 'changed' : 'unchanged') : 'unconfirmed';
    } catch (ResourceOperationException $exception) {
        expect($exception->getMessage())->not->toContain('secret');
        $actual = $exception->errorCode;
    }

    expect($actual)->toBe($expected)
        ->and($agents->calls[0]['input'])->toMatchArray(['mode' => 'write', 'file' => '.env', 'managed_keys' => [], 'content' => "APP_KEY=secret\n"]);
})->with([
    'changed' => [['state' => 'changed', 'installed' => true], 'changed'],
    'unchanged' => [['state' => 'unchanged', 'installed' => false], 'unchanged'],
    'refused' => [['state' => 'refused', 'installed' => false], 'env.write_failed'],
    'failed before the rename' => [['state' => 'failed', 'installed' => false], 'env.write_failed'],
    'failed after the rename' => [['state' => 'failed', 'installed' => true], 'unconfirmed'],
]);

it('treats a lost write acknowledgement as unconfirmed', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.write-environment', fn (): array => throw new AgentRunnerFailure('instances.write-environment', AgentRunnerFailure::MissingResult, 'lost'));

    expect(new RemoteInstanceEnvironmentAccess($agents)->write(environment_access_context(), "APP_KEY=x\n")->confirmed)->toBeFalse();
});

it('merges the managed keys into .env.testing and leaves a tracked one alone', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('instances.write-environment', ['state' => 'tracked', 'installed' => false]);
    $access = new RemoteInstanceEnvironmentAccess($agents);

    $result = $access->mergeTesting(environment_access_context(), "DB_DATABASE=testing\n", ['DB_DATABASE']);

    expect($result->tracked)->toBeTrue()
        ->and($agents->calls[0]['input'])->toMatchArray(['mode' => 'merge', 'file' => '.env.testing', 'managed_keys' => ['DB_DATABASE']]);

    $agents->answer('instances.write-environment', ['state' => 'tracking_unknown', 'installed' => false]);
    expect(fn () => $access->mergeTesting(environment_access_context(), "DB_DATABASE=testing\n", ['DB_DATABASE']))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('env.testing_tracking_unknown'));
});
