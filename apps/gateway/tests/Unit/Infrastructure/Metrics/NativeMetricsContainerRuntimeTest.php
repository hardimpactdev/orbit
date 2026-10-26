<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\MetricsRuntimeCommands;
use App\Infrastructure\Metrics\NativeMetricsContainerRuntime;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('sends the assignment and rendered fleet configuration in one transaction before verifying the credential', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.runtime', ['changed' => true]);
    $credentials = new MetricsCredentialManagerFake;
    $runtime = metricsContainerRuntime($agents, $credentials);
    [$node, $assignment] = metricsRuntimeModels();

    $runtime->converge($node, $assignment);

    expect($agents->commands())->toBe(['metrics.runtime']);
    $input = $agents->calls[0]['input'];
    expect($input['address'])->toBe('10.44.0.3')
        ->and($input['action']['operation'])->toBe('converge')
        ->and($input['action']['assignment'])->toBe(41)
        ->and($input['action']['configuration']['/etc/orbit/metrics/prometheus.yml'])->toContain('gateway', 'metrics-runtime')
        ->and($input['action']['configuration']['/etc/orbit/metrics/grafana/admin-password'])->toBe('runtime-admin-password')
        ->and($credentials->verified)->toBe([$node->id]);
});

it('preserves a typed runtime failure without a second mutation or credential verification', function (string $code): void {
    $agents = new FakeAgentCommandRunner()->refuse('metrics.runtime', $code, ['status' => 502]);
    $credentials = new MetricsCredentialManagerFake;
    [$node, $assignment] = metricsRuntimeModels();
    try {
        metricsContainerRuntime($agents, $credentials)->converge($node, $assignment);
        $this->fail('Expected refusal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code)->and($exception->status)->toBe(502);
    }
    expect($agents->commands())->toBe(['metrics.runtime'])->and($credentials->verified)->toBe([]);
})->with(['metrics.container_convergence_failed', 'metrics.container_cleanup_failed', 'metrics.runtime_rollback_failed']);

it('keeps committed runtime state when credential verification fails', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.runtime', ['changed' => true]);
    $credentials = new MetricsCredentialManagerFake;
    $credentials->verificationFailure = new RuntimeException('credential verification failed');
    [$node, $assignment] = metricsRuntimeModels();
    expect(fn () => metricsContainerRuntime($agents, $credentials)->converge($node, $assignment))->toThrow(RuntimeException::class, 'credential verification failed');
    expect($agents->commands())->toBe(['metrics.runtime']);
});

it('purges credential records only after a requested runtime purge succeeds', function (bool $purge, bool $failure): void {
    $agents = new FakeAgentCommandRunner;
    $failure ? $agents->refuse('metrics.runtime', 'metrics.volume_ownership_drift', ['status' => 409]) : $agents->answer('metrics.runtime', ['changed' => true]);
    $credentials = new MetricsCredentialManagerFake;
    [$node, $assignment] = metricsRuntimeModels();
    try {
        metricsContainerRuntime($agents, $credentials)->remove($node, $assignment, $purge);
        expect($failure)->toBeFalse();
    } catch (ResourceOperationException $exception) {
        expect($failure)->toBeTrue()->and($exception->status)->toBe(409);
    }
    expect($agents->calls[0]['input']['action'])->toBe(['operation' => 'remove', 'purge_data' => $purge]);
    expect($credentials->purged)->toBe($purge && ! $failure ? [$node->id] : []);
})->with([true, false])->with([true, false]);

it('reads health through the agent and rejects unknown service names locally', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.runtime-health', ['healthy' => true]);
    $runtime = metricsContainerRuntime($agents, new MetricsCredentialManagerFake);
    [$node] = metricsRuntimeModels();
    expect($runtime->health($node, 'grafana'))->toBeTrue()->and($runtime->health($node, 'foreign'))->toBeFalse();
    expect($agents->calls[0]['input'])->toBe(['service' => 'grafana'])->and($agents->calls)->toHaveCount(1);
});

function metricsContainerRuntime(FakeAgentCommandRunner $agents, MetricsCredentialManager $credentials): NativeMetricsContainerRuntime
{
    return new NativeMetricsContainerRuntime(new MetricsRuntimeCommands($agents, new CommandDeadline), new MetricsConfigurationRenderer, $credentials, new MetricsExporterLifecycleFake);
}

/** @return array{Node, NodeRole} */
function metricsRuntimeModels(): array
{
    $node = new Node(['name' => 'metrics-runtime', 'wireguard_ip' => '10.44.0.3']);
    $node->id = 3;
    $assignment = new NodeRole;
    $assignment->id = 41;
    $assignment->node_id = $node->id;

    return [$node, $assignment];
}

final class MetricsCredentialManagerFake implements MetricsCredentialManager
{
    /** @var list<int> */
    public array $verified = [];

    /** @var list<int> */
    public array $purged = [];

    public ?RuntimeException $verificationFailure = null;

    public function passwordForConvergence(Node $node): string
    {
        return 'runtime-admin-password';
    }

    public function verifyActive(Node $node): void
    {
        $this->verified[] = $node->id;

        if ($this->verificationFailure instanceof RuntimeException) {
            throw $this->verificationFailure;
        }
    }

    public function purge(Node $node): void
    {
        $this->purged[] = $node->id;
    }

    public function credentials(): MetricsCredentialsData
    {
        throw new RuntimeException('Not used by this test.');
    }

    public function storedCredentials(): MetricsCredentialsData
    {

        return $this->credentials();

    }

    public function reset(): MetricsCredentialsData
    {
        throw new RuntimeException('Not used by this test.');
    }
}

final class MetricsExporterLifecycleFake implements MetricsExporterLifecycle
{
    /** @var list<array{name: string, address: string}> */
    public array $targets = [
        ['name' => 'gateway', 'address' => '10.44.0.1'],
        ['name' => 'metrics-runtime', 'address' => '10.44.0.3'],
    ];

    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment): void {}

    public function removeNode(Node $node, Node $metricsNode): void {}

    public function actual(Node $node): string
    {
        return 'active';
    }

    public function targets(Node $metricsNode): array
    {
        return $this->targets;
    }
}
