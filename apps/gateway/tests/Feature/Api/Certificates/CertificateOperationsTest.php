<?php

declare(strict_types=1);

use App\Actions\Routes\StartCertificateOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'native-certificate', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.8',
        'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $this->manifest = tempnam(sys_get_temp_dir(), 'certificate-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->signer = new class implements LeafCertificateSigner
    {
        public array $calls = [];

        public function rootCertificate(): string
        {
            return "-----BEGIN CERTIFICATE-----\npublic root\n-----END CERTIFICATE-----\n";
        }

        public function sign(string $hostname, string $certificateRequest): string
        {
            $this->calls[] = [$hostname, $certificateRequest];

            return "-----BEGIN CERTIFICATE-----\npublic leaf\n-----END CERTIFICATE-----\n";
        }
    };
    app()->instance(LeafCertificateSigner::class, $this->signer);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
});

afterEach(function (): void {
    @unlink($this->manifest);
});

it('admits the exact local leaf scope account root and agent pin without executing', function (): void {
    $input = app(StartCertificateOperationAction::class)->convergenceInput($this->node, 'route-81-router', 'private.test');
    expect($input)->toMatchArray(['node_id' => $this->node->id, 'scope' => 'route-81-router', 'home' => '/home/orbit', 'user' => 'orbit',
        'domain' => 'private.test', 'root_hash' => hash('sha256', $this->signer->rootCertificate()), 'agent_sha256' => str_repeat('a', 64)])
        ->and($input['version'])->toMatch('/\A[a-f0-9]{16}\z/')
        ->and($this->agents->calls)->toBe([])->and($this->signer->calls)->toBe([]);
});

it('refuses unsupported scope and platform before recording or executing a certificate intent', function (): void {
    expect(fn () => app(StartCertificateOperationAction::class)->convergenceInput($this->node, '../../ca', 'private.test'))
        ->toThrow(ResourceOperationException::class);
    $this->node->platform = 'macos';
    expect(fn () => app(StartCertificateOperationAction::class)->convergenceInput($this->node, 'route-81', 'private.test'))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('admits removal only after stored caddy sites stop using its leaf', function (): void {
    $route = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $this->node->id, 'domain' => 'still-used.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:4788']);
    $scope = 'route-'.$route->id;
    $input = app(StartCertificateOperationAction::class)->removalInput($this->node, $scope);
    expect($input)->toBe(['node_id' => $this->node->id, 'scope' => $scope, 'home' => '/home/orbit', 'agent_sha256' => str_repeat('a', 64), 'in_use' => false]);
    $route->publishSites();
    expect(fn () => app(StartCertificateOperationAction::class)->removalInput($this->node, $scope))->toThrow(ResourceOperationException::class);
    expect($this->agents->calls)->toBe([])->and(Operation::query()->count())->toBe(0);
});

it('declares native certificate workflows without a Gateway signing primitive', function (): void {
    $direct = app(DirectOperation::class);
    foreach (['certificate.converge', 'certificate.remove', 'cluster.router_set', 'cluster.router_unset'] as $kind) {
        expect($direct->definition($kind))->not->toHaveKey('primitive');
    }
});
