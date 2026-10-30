<?php

declare(strict_types=1);

use App\Actions\Routes\CertificateChildAdmission;
use App\Actions\Routes\StartCertificateOperationAction;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'certificate-node', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $manifest = tempnam(sys_get_temp_dir(), 'certificate-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->socket = new FakeRoutePublicationSocket;
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-certificate-remove', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['dependency_detection.services_node_id' => $this->node->id, 'swamp.run_token' => 'fixture.fake', 'swamp.direct_writers' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->admit = fn () => app(StartCertificateOperationAction::class)->remove($this->node, 'route-700-router');
});

it('starts only the direct removal writer with safe input and no Gateway executor hold', function (): void {
    $operation = ($this->admit)();
    expect(app(DirectOperation::class)->writer('certificate.remove'))->toBe('direct')
        ->and($operation->state['swamp_run_id'])->toBe($this->runId)->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['scope' => 'route-700-router', 'home' => '/home/orbit', 'agent_sha256' => str_repeat('a', 64), 'in_use' => false]);
    $this->postJson($path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'certificate.remove', 'worker' => 'old'])->assertUnprocessable();
    expect($this->agents->calls)->toBe([]);
});

it('refuses a polled writer before recording removal intent', function (): void {
    config(['swamp.direct_writers' => ['certificate.remove' => 'polled']]);
    expect($this->admit)->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([])->and($this->agents->calls)->toBe([]);
});

it('does not allow an early or foreign native run to read removal intent', function (): void {
    $reply = $this->socket->reply;
    $this->socket->reply = function (array $request) use ($reply): array {
        $operation = Operation::query()->latest('id')->firstOrFail();
        $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');

        return $reply($request);
    };
    $operation = ($this->admit)();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92677')->assertConflict();
    expect($this->agents->calls)->toBe([]);
});

it('refuses an in-use certificate before admission and exposes later use before the Node effect', function (): void {
    $route = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $this->node->id, 'domain' => 'still-used.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:4788']);
    $scope = 'route-'.$route->id;
    $operation = app(StartCertificateOperationAction::class)->remove($this->node, $scope);
    $route->publishSites();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input.in_use', true);
    expect(fn () => app(StartCertificateOperationAction::class)->remove($this->node, $scope))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(1)->and($this->agents->calls)->toBe([]);
});

it('projects only verified absence or a bounded failure and redacts undeclared output', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:30:00.000Z', 'finished_at' => '2026-10-30T19:30:01.000Z', 'result' => ['present' => false, 'stdout' => 'private-diagnostic']];
    foreach ([[], ['present' => true], ['present' => false, 'error_code' => 'certificate.failed']] as $invalid) {
        $this->postJson($path, [...$body, 'result' => $invalid])->assertUnprocessable();
    }
    $this->postJson($path, $body)->assertOk()->assertDontSee('private-diagnostic');
    $this->postJson($path, $body)->assertOk();
    expect($operation->fresh()->result)->toBe(['present' => false])->and($operation->fresh()->steps()->count())->toBe(1)->and($this->agents->calls)->toBe([]);
});

it('refuses a foreign Node worker even when it has access to the Gateway', function (): void {
    $operation = ($this->admit)();
    $foreign = Node::query()->create(['name' => 'foreign-worker', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $foreign->accessibleNodes()->attach($this->node);
    config(['operation_executor.worker_node_ids' => [$foreign->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertForbidden();
    expect($this->agents->calls)->toBe([]);
});

it('projects a bounded removal refusal without Node execution or raw diagnostics', function (): void {
    $operation = ($this->admit)();
    $body = ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-30T19:30:00.000Z', 'finished_at' => '2026-10-30T19:30:01.000Z', 'error_code' => 'app-dev.certificate_in_use', 'result' => ['error_code' => 'app-dev.certificate_in_use', 'stderr' => 'private-diagnostic']];
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/result', $body)->assertOk()->assertDontSee('private-diagnostic');
    expect($operation->fresh()->result)->toBe(['error_code' => 'app-dev.certificate_in_use'])->and($this->agents->calls)->toBe([]);
});

it('preserves the parent actor and request at owning removal admission', function (?string $actor): void {
    $parent = new Operation(['kind' => 'route.withdraw', 'triggered_by' => $actor, 'request_id' => 'certificate-parent']);
    $child = app(CertificateChildAdmission::class)->admit($parent, ['kind' => 'certificate.remove', 'node_id' => $this->node->id, 'input' => ['scope' => 'route-700-router']]);
    expect($child->triggered_by)->toBe($actor)->and($child->request_id)->toBe('certificate-parent')->and($child->node_id)->toBe($this->node->id)->and($this->agents->calls)->toBe([]);
})->with(['peer:proof', null]);
