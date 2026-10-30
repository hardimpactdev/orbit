<?php

declare(strict_types=1);

use App\Actions\Routes\CertificateChildAdmission;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = Node::query()->create(['name' => 'services', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.17', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    $this->node = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->operation = Operation::query()->create(['kind' => 'certificate.converge', 'target_type' => 'node_certificate', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'worker' => 'worker', 'sequence' => 1,
        'input' => ['scope' => 'route-7-router', 'domain' => 'private.test', 'version' => '0123456789abcdef', 'root_hash' => str_repeat('a', 64), 'user' => 'orbit', 'home' => '/home/orbit']]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => $this->operation->kind, 'worker' => 'worker'])->assertOk()->assertJsonPath('data.id', $this->operation->id);
    $this->operation->refresh();
    $this->path = '/api/v1/operation-executor/'.$this->operation->id;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $request = openssl_csr_new(['commonName' => 'private.test'], $key);
    openssl_csr_export($request, $this->csr);
    $this->agents = new FakeAgentCommandRunner()->answer('certificate.request-leaf', ['state' => 'request', 'request' => $this->csr])
        ->answer('certificate.publish-leaf', [])->answer('certificate.inspect-leaf', ['present' => true])->answer('certificate.remove-leaf', []);
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->signer = new class implements LeafCertificateSigner
    {
        public int $calls = 0;

        public function sign(string $hostname, string $certificateRequest): string
        {
            $this->calls++;
            expect($hostname)->toBe('private.test')->and($certificateRequest)->toStartWith('-----BEGIN CERTIFICATE REQUEST-----');

            return "-----BEGIN CERTIFICATE-----\npublic leaf\n-----END CERTIFICATE-----\n";
        }

        public function rootCertificate(): string
        {
            return "-----BEGIN CERTIFICATE-----\npublic root\n-----END CERTIFICATE-----\n";
        }
    };
    app()->instance(LeafCertificateSigner::class, $this->signer);
    $this->runStep = function (string $key): array {
        $descriptors = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors');
        $descriptor = array_find($descriptors, static fn (array $d): bool => $d['key'] === $key);
        expect($descriptor)->not->toBeNull();
        $result = $this->postJson($this->path.'/run', ['worker' => 'worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))])->assertOk()->json('data');
        $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => $key.':finish', 'name' => $key, 'status' => 'succeeded', 'result' => $result])->assertOk();

        return $result;
    };
});

it('keeps the public CSR usable through redaction and hydrates only this Operation signature at publication', function (): void {
    expect(strlen(base64_encode($this->csr)))->toBeGreaterThan(1024);
    $request = ($this->runStep)('certificate-request');
    expect(base64_decode($request['request_base64'], true))->toBe($this->csr)->and($request)->not->toHaveKey('request');
    ($this->runStep)('certificate-sign');
    ($this->runStep)('certificate-publish');
    $result = ($this->runStep)('certificate-inspect');
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->signer->calls)->toBe(1)->and($this->agents->calls)->toHaveCount(3)
        ->and($this->agents->calls[1]['input']['certificate'])->toContain('public leaf');
    $state = json_encode($this->operation->fresh()->state);
    expect($state)->not->toContain('-----BEGIN', 'PRIVATE KEY');
});

it('never signs or republishes a current leaf but still requires final inspection proof', function (): void {
    $this->agents->answer('certificate.request-leaf', ['state' => 'current']);
    ($this->runStep)('certificate-request');
    $result = ($this->runStep)('certificate-inspect');
    $this->postJson($this->path.'/complete', ['worker' => 'worker', 'status' => 'succeeded', 'result' => $result])->assertOk();
    expect($this->signer->calls)->toBe(0)->and($this->agents->calls)->toHaveCount(2);
});

it('refuses an unrelated secret reference and forged final inspection', function (): void {
    ($this->runStep)('certificate-request');
    ($this->runStep)('certificate-sign');
    $this->postJson($this->path.'/run', ['worker' => 'worker', 'key' => 'certificate-publish', 'transport' => 'agent', 'name' => 'certificate.publish-leaf', 'input' => [...$this->operation->input, 'certificate' => ['$secret' => 'certificate.leaf', 'id' => $this->operation->id + 1], 'root_certificate' => ['$secret' => 'certificate.leaf', 'id' => $this->operation->id]]])->assertUnprocessable();
    $this->postJson($this->path.'/step', ['worker' => 'worker', 'key' => 'forged', 'name' => 'certificate-inspect', 'status' => 'succeeded', 'result' => ['present' => true]])->assertConflict();
    expect($this->agents->calls)->toHaveCount(1);
});

it('recovers and encodes the original public CSR from sanitized evidence without another request', function (): void {
    expect(strlen(base64_encode($this->csr)))->toBeGreaterThan(1024);
    $this->agents->answer('certificate.request-leaf', static fn (): array => throw new RuntimeException('Lost reply after key generation.'));
    $descriptor = $this->postJson($this->path.'/heartbeat', ['worker' => 'worker'])->assertOk()->json('data.execution_context.descriptors.0');
    $run = ['worker' => 'worker', ...array_intersect_key($descriptor, array_flip(['key', 'transport', 'name', 'input']))];
    $this->postJson($this->path.'/run', $run)->assertConflict()->assertJsonPath('error.code', 'operation.execution_uncertain');
    $effectKey = 'op-'.$this->operation->id.'.certificate-request';
    $this->agents->answer('agent.effect-receipt', ['command' => 'certificate.request-leaf', 'effect_key' => $effectKey, 'state' => 'succeeded', 'result' => ['ok' => true, 'data' => ['state' => 'request', 'request' => $this->csr]], 'result_digest' => str_repeat('a', 64), 'sanitized' => true, 'effect_nonce' => $this->operation->fresh()->state['executor_effect_identity']['nonce'], 'effect_created_at' => $this->operation->fresh()->state['executor_effect_identity']['created_at'], 'exit_code' => 0, 'finished_at' => '2026-10-28T22:00:00Z']);
    $receipt = $this->getJson($this->path.'/receipts/certificate-request')->assertOk()->json('data.result');
    expect(base64_decode($receipt['request_base64'], true))->toBe($this->csr)->and($receipt)->not->toHaveKey('request');
    ($this->runStep)('certificate-request');
    ($this->runStep)('certificate-sign');
    ($this->runStep)('certificate-publish');
    ($this->runStep)('certificate-inspect');
    expect(array_column($this->agents->calls, 'command'))->toBe(['certificate.request-leaf', 'agent.effect-receipt', 'certificate.publish-leaf', 'certificate.inspect-leaf']);
});

it('preserves the parent actor and request when admitting a certificate child', function (string $kind, ?string $actor): void {
    $node = Node::query()->create(['name' => 'child-cert', 'public_ssh_host' => '192.0.2.4', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $parent = new Operation(['kind' => 'test.parent', 'triggered_by' => $actor, 'request_id' => 'certificate-parent-request']);
    $input = ['scope' => 'route-7-router', ...($kind === 'certificate.converge' ? ['domain' => 'private.test'] : [])];
    $child = app(CertificateChildAdmission::class)->admit($parent, ['kind' => $kind, 'node_id' => $node->id, 'input' => $input]);
    expect($child->triggered_by)->toBe($actor)->and($child->request_id)->toBe($parent->request_id)
        ->and($child->node_id)->toBe($node->id)->and($this->agents->calls)->toBe([]);
})->with(['certificate.converge'])->with(['peer:proof', null]);
