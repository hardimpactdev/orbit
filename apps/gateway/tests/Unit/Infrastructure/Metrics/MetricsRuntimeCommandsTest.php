<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsRuntimeCommands;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('sends credential input through the typed runner and exposes only its result', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.credentials', ['verified' => false]);
    $commands = new MetricsRuntimeCommands($agents, new CommandDeadline);
    $node = new Node(['name' => 'metrics', 'wireguard_ip' => '10.44.0.3']);
    expect($commands->verify($node, 'active-secret'))->toBeFalse();
    expect($agents->calls[0]['input'])->toBe(['address' => '10.44.0.3', 'action' => ['operation' => 'verify', 'password' => 'active-secret']]);
    $agents->answer('metrics.credentials', ['changed' => true]);
    $commands->apply($node, 'active-secret', 'pending-secret');
    expect($agents->calls[1]['input']['action'])->toBe(['operation' => 'apply', 'active_password' => 'active-secret', 'pending_password' => 'pending-secret']);
});

it('refuses malformed runtime results', function (): void {
    $agents = new FakeAgentCommandRunner()->answerMalformed('metrics.credentials', ['verified' => 'yes']);
    $commands = new MetricsRuntimeCommands($agents, new CommandDeadline);
    expect(fn () => $commands->verify(new Node(['wireguard_ip' => '10.44.0.3']), 'secret'))->toThrow(ResourceOperationException::class, 'invalid result');
});

it('keeps Metrics container execution and transaction decisions in the agent', function (): void {
    foreach (['MetricsRuntimeCommands', 'NativeMetricsContainerRuntime'] as $name) {
        $source = file_get_contents(app_path('Infrastructure/Metrics/'.$name.'.php'));
        expect($source)->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', 'docker', 'snapshotConfiguration', 'restoreConfiguration', '<<<');
    }
});
