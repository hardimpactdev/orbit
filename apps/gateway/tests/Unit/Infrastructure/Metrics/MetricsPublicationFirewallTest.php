<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsPublicationFirewall;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('sends each complete firewall operation through one agent call', function (string $operation): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.publication-firewall', ['changed' => false]);
    $firewall = new MetricsPublicationFirewall($agents, new CommandDeadline);
    $node = new Node(['name' => 'metrics', 'wireguard_ip' => '10.44.0.3']);
    if ($operation === 'converge') {
        expect($firewall->converge($node, '10.44.0.1'))->toBeFalse();
    } elseif ($operation === 'remove') {
        $firewall->remove($node, '10.44.0.1');
    } else {
        $firewall->abandon($node);
    }
    expect($agents->calls)->toBe([['node' => 'metrics', 'command' => 'metrics.publication-firewall', 'input' => [
        'address' => '10.44.0.3', 'action' => $operation === 'abandon' ? ['operation' => 'abandon'] : ['operation' => $operation, 'gateway' => '10.44.0.1'],
    ]]]);
})->with(['converge', 'remove', 'abandon']);

it('preserves firewall refusals without Gateway rollback', function (string $code, int $status): void {
    $agents = new FakeAgentCommandRunner()->refuse('metrics.publication-firewall', $code);
    try {
        new MetricsPublicationFirewall($agents, new CommandDeadline)->converge(new Node(['wireguard_ip' => '10.44.0.3']), '10.44.0.1');
        $this->fail('Expected refusal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe($status);
    }
    expect($agents->calls)->toHaveCount(1);
})->with([['metrics.publication_firewall_ownership_drift', 409], ['metrics.publication_firewall_apply_failed', 502], ['metrics.remote_command_timed_out', 504]]);

it('refuses invalid publication addresses before sending a command', function (): void {
    $agents = new FakeAgentCommandRunner;
    expect(fn () => new MetricsPublicationFirewall($agents, new CommandDeadline)->converge(new Node(['wireguard_ip' => '10.44.0.3']), 'invalid'))->toThrow(ResourceOperationException::class, 'IPv4');
    expect($agents->calls)->toBe([]);
});

it('keeps firewall execution and parsing in Rust', function (): void {
    expect(file_get_contents(app_path('Infrastructure/Metrics/MetricsPublicationFirewall.php')))->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', 'UfwStatusParser', '<<<');
});
