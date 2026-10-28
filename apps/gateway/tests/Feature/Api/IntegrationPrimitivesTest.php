<?php

declare(strict_types=1);

use App\Actions\Metrics\StartMetricsOperationAction;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Metrics\MetricsServiceExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnGatewaySessionsCommand;
use Orbit\Extensions\Conn\Domain\ConnPairingIssueCommand;
use Orbit\Extensions\Conn\Domain\T3ClientSession;
use Orbit\Extensions\Conn\Domain\T3ServerClient;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $this->node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.19', 'public_ssh_host' => '192.0.2.19']);
});

it('revokes only admitted earlier Gateway sessions and preserves new and current sessions', function (): void {
    $server = Mockery::mock(T3ServerClient::class);
    $server->shouldReceive('clientSessions')->once()->with('http://dev.test', 'fixture-session')->andReturn([
        new T3ClientSession('old', 'Orbit Gateway', false), new T3ClientSession('current', 'Orbit Gateway', true),
        new T3ClientSession('newer', 'Orbit Gateway', false), new T3ClientSession('phone', 'iPhone', false),
    ]);
    $server->shouldReceive('revokeClientSession')->once()->with('http://dev.test', 'fixture-session', 'old')->andReturn(true);
    app()->instance(T3ServerClient::class, $server);
    $result = app(ConnGatewaySessionsCommand::class)->run(new Operation(['kind' => 'conn.gateway_sessions_revoke', 'target_id' => 71]),
        ['environment' => ['url' => 'http://dev.test', 'admin_session' => 'fixture-session', 'id' => 71], 'pairing_ids' => [], 'session_ids' => ['old', 'current', 'phone']]);
    expect($result)->toBe(['backend' => 'revoked', 'revoked_sessions' => 1]);
});

it('refuses cleanup for another environment before any T3 call', function (): void {
    $server = Mockery::mock(T3ServerClient::class);
    $server->shouldNotReceive('clientSessions');
    app()->instance(T3ServerClient::class, $server);
    expect(fn () => app(ConnGatewaySessionsCommand::class)->run(new Operation(['kind' => 'conn.gateway_sessions_revoke', 'target_id' => 72]),
        ['environment' => ['url' => 'http://dev.test', 'admin_session' => 'fixture-session', 'id' => 71], 'pairing_ids' => [], 'session_ids' => ['old']]))
        ->toThrow(ResourceOperationException::class);
});

it('never mints a second one-time pairing after an uncertain issue', function (): void {
    $environment = ConnEnvironment::query()->create(['environment_id' => 'dev-env', 'label' => 'dev', 'node_id' => $this->node->id,
        'url' => 'http://dev.test', 'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $pairing = ConnPairing::query()->create(['conn_environment_id' => $environment->id, 'node_id' => $this->node->id,
        'client_label' => 'Orbit dev', 'issue_started_at' => now()]);
    $server = Mockery::mock(T3ServerClient::class);
    $server->shouldNotReceive('issuePairingCredential');
    app()->instance(T3ServerClient::class, $server);
    expect(fn () => app(ConnPairingIssueCommand::class)->run(new Operation(['kind' => 'conn.pairing_create', 'target_id' => $pairing->id]),
        ['environment' => ['url' => 'http://dev.test', 'admin_session' => 'fixture-session', 'id' => $environment->id], 'pairing_ids' => [$pairing->id]]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('conn.pairing_outcome_unknown'));
});

it('binds service exporter configuration to post-convergence pool fingerprints', function (): void {
    $this->node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'proof', 'slug' => 'proof', 'repository_url' => 'https://example.test/proof.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$project->id;
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'primary',
        'environment' => 'production', 'status' => 'active', 'checkout_path' => '/home/'.$user.'/releases/initial',
        'production_user' => $user, 'production_home' => '/home/'.$user, 'root' => 'public', 'selected_php_version' => '8.5']);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    $execution = app(MetricsServiceExecution::class);
    $operation = new Operation(['kind' => 'metrics.service_converge', 'node_id' => $this->node->id,
        'input' => ['instance_ids' => [$instance->id], 'caddy' => false, 'fpm' => true, 'metrics_address' => '10.44.0.20'],
        'state' => ['steps' => ['pool-fingerprint-'.$instance->id => ['enabled' => true, 'fingerprint' => 'new-pool-digest']]]]);
    $descriptors = $execution->context($operation)['descriptors'];
    expect(array_column($descriptors, 'name'))->toBe(['php.converge-production-runtime', 'php.inspect-production-pool', 'metrics.service-runtime']);
    expect($descriptors[2]['input']['state']['config'])->toContain('# runtime '.$instance->id.': new-pool-digest');
    $receipt = $execution->received($operation, ['key' => 'pool-fingerprint-'.$instance->id, 'name' => 'php.inspect-production-pool'],
        ['pool' => "pm.status_path = /orbit-fpm-status\npm.status_listen = /run/php/{$user}.sock.status\n", 'fingerprint' => 'new-pool-digest']);
    expect($receipt)->toBe(['enabled' => true, 'fingerprint' => 'new-pool-digest']);
});

it('records every retiring Node cleanup without running or waiting for it', function (): void {
    $metrics = Node::query()->create(['name' => 'metrics', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20']);
    if (! OperationKinds::supports('list_of')) {
        expect(fn () => app(StartMetricsOperationAction::class)->retire($this->node, $metrics))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.kind_unavailable'));
        expect(Operation::query()->count())->toBe(0);

        return;
    }
    $operation = app(StartMetricsOperationAction::class)->retire($this->node, $metrics);
    expect($operation->kind)->toBe('metrics.retire')->and($operation->node_id)->toBe($this->node->id);
    $children = array_values(array_filter($operation->input['flow'], static fn (array $entry): bool => $entry['type'] === 'child'));
    expect(array_column($children, 'kind'))->toBe(['metrics.node_exporter_remove', 'metrics.cadvisor_remove', 'metrics.service_converge'])
        ->and(array_column($children, 'continue_on_failure'))->toBe([true, true, true]);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
