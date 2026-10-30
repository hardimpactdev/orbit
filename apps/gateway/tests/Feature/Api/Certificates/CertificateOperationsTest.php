<?php

declare(strict_types=1);

use App\Actions\Routes\CertificateChildAdmission;
use App\Actions\Routes\StartCertificateOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
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
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-certificate-converge', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['dependency_detection.services_node_id' => $this->node->id, 'swamp.run_token' => 'fixture.fake', 'swamp.direct_writers' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->signer = new class implements LeafCertificateSigner
    {
        public int $calls = 0;

        public bool $fail = false;

        public function rootCertificate(): string
        {
            return "-----BEGIN CERTIFICATE-----\npublic root\n-----END CERTIFICATE-----\n";
        }

        public function sign(string $hostname, string $certificateRequest): string
        {
            $this->calls++;
            expect($hostname)->toBe('private.test')->and($certificateRequest)->toStartWith('-----BEGIN CERTIFICATE REQUEST-----');
            if ($this->fail) {
                throw new RuntimeException('private-signer-diagnostic');
            }

            return "-----BEGIN CERTIFICATE-----\npublic leaf\n-----END CERTIFICATE-----\n";
        }
    };
    app()->instance(LeafCertificateSigner::class, $this->signer);
    $this->admit = fn () => app(StartCertificateOperationAction::class)->converge($this->node, 'route-700-router', 'private.test');
    $this->csr = static function (): string {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'private.test'], $key);
        openssl_csr_export($request, $pem);

        return $pem;
    };
    $this->body = ['run_id' => $this->runId, 'status' => 'running', 'started_at' => '2026-10-30T20:00:00.000Z'];
});

it('records direct certificate intent without Node execution or a Gateway claim', function (): void {
    $operation = ($this->admit)();
    expect(app(DirectOperation::class)->writer('certificate.converge'))->toBe('direct')
        ->and($operation->state['swamp_run_id'])->toBe($this->runId)->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input.agent_sha256', str_repeat('a', 64))->assertJsonPath('data.input.domain', 'private.test');
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'certificate.converge', 'worker' => 'old'])->assertUnprocessable();
    expect($this->signer->calls)->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('records only public CSR bytes and signs only the admitted domain through the generic primitive', function (): void {
    $operation = ($this->admit)();
    $encoded = base64_encode(($this->csr)());
    expect(strlen($encoded))->toBeGreaterThan(1024);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->postJson($path.'/result', [...$this->body, 'result' => ['request_base64' => $encoded]])->assertOk();
    $signed = $this->postJson($path.'/primitive', ['run_id' => $this->runId])->assertOk()->assertJsonPath('data.signed', true)->json('data');
    expect(base64_decode($signed['certificate_base64']))->toContain('public leaf')
        ->and(base64_decode($signed['root_certificate_base64']))->toContain('public root')
        ->and($operation->fresh()->state['certificate_request'])->toBe($encoded)
        ->and($operation->fresh()->state)->not->toHaveKey('executor_runs')
        ->and($this->signer->calls)->toBe(1)->and($this->agents->calls)->toBe([]);
});

it('cannot sign before its public request or before the native run is bound', function (): void {
    $reply = $this->socket->reply;
    $this->socket->reply = function (array $request) use ($reply): array {
        $op = Operation::query()->latest('id')->firstOrFail();
        $this->getJson('/api/v1/direct-operations/'.$op->id.'?run_id='.$this->runId)->assertConflict();
        $this->postJson('/api/v1/direct-operations/'.$op->id.'/primitive', ['run_id' => $this->runId])->assertConflict();

        return $reply($request);
    };
    $operation = ($this->admit)();
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    expect($this->signer->calls)->toBe(0);
});

it('refuses private PEM, malformed CSR and a changed request without replacing product state', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    foreach (["-----BEGIN PRIVATE KEY-----\nnot public\n-----END PRIVATE KEY-----\n", "-----BEGIN CERTIFICATE REQUEST-----\nbroken\n-----END CERTIFICATE REQUEST-----\n", ($this->csr)()."-----BEGIN PRIVATE KEY-----\nprivate\n-----END PRIVATE KEY-----\n"] as $invalid) {
        $this->postJson($path, [...$this->body, 'result' => ['request_base64' => base64_encode($invalid)]])->assertUnprocessable();
    }
    $first = base64_encode(($this->csr)());
    $this->postJson($path, [...$this->body, 'result' => ['request_base64' => $first]])->assertOk();
    $this->postJson($path, [...$this->body, 'result' => ['request_base64' => $first]])->assertOk();
    $this->postJson($path, [...$this->body, 'result' => ['request_base64' => base64_encode(($this->csr)())]])->assertUnprocessable();
    expect($operation->fresh()->state['certificate_request'])->toBe($first)->and($this->signer->calls)->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('projects final presence for a current leaf without requiring a new signature', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $body = [...$this->body, 'status' => 'succeeded', 'finished_at' => '2026-10-30T20:00:01.000Z', 'result' => ['present' => true, 'stderr' => 'private-diagnostic']];
    $this->postJson($path, [...$body, 'result' => ['present' => false]])->assertUnprocessable();
    $this->postJson($path, $body)->assertOk()->assertDontSee('private-diagnostic');
    $this->postJson($path, $body)->assertOk();
    expect($operation->fresh()->result)->toBe(['present' => true])->and($operation->fresh()->state)->not->toHaveKey('certificate_request')
        ->and($this->signer->calls)->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('returns only a bounded signing refusal and keeps private diagnostics out of the response', function (): void {
    $operation = ($this->admit)();
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->postJson($path.'/result', [...$this->body, 'result' => ['request_base64' => base64_encode(($this->csr)())]])->assertOk();
    $this->signer->fail = true;
    $this->postJson($path.'/primitive', ['run_id' => $this->runId])->assertOk()->assertJsonPath('data.error_code', 'app-dev.certificate_sign_failed')->assertDontSee('private-signer-diagnostic');
    expect($this->signer->calls)->toBe(1)->and($this->agents->calls)->toBe([]);
});

it('refuses a removed writer before creating another certificate intent', function (): void {
    config(['swamp.direct_writers' => ['certificate.converge' => 'polled']]);
    expect($this->admit)->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('keeps the parent actor and request on direct certificate admission', function (?string $actor): void {
    $parent = new Operation(['kind' => 'route.converge', 'triggered_by' => $actor, 'request_id' => 'certificate-parent']);
    $child = app(CertificateChildAdmission::class)->admit($parent, ['kind' => 'certificate.converge', 'node_id' => $this->node->id, 'input' => ['scope' => 'route-700-router', 'domain' => 'private.test']]);
    expect($child->triggered_by)->toBe($actor)->and($child->request_id)->toBe('certificate-parent')->and($this->agents->calls)->toBe([]);
})->with(['peer:proof', null]);

it('refuses signing from another enrolled Node even with Gateway access', function (): void {
    $operation = ($this->admit)();
    $foreign = Node::query()->create(['name' => 'foreign-certificate-worker', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $foreign->accessibleNodes()->attach($this->node);
    config(['operation_executor.worker_node_ids' => [$foreign->id]]);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->postJson('/api/v1/direct-operations/'.$operation->id.'/primitive', ['run_id' => $this->runId])->assertForbidden();
    expect($this->signer->calls)->toBe(0)->and($this->agents->calls)->toBe([]);
});
