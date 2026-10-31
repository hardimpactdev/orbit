<?php

declare(strict_types=1);

use App\Actions\Routes\StartCertificateOperationAction;
use App\Domain\Certificates\CertificateSigningPrimitive;
use App\Domain\Certificates\CertificateSigningRequests;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

function nativeCertificateRequest(string $domain): string
{
    static $key = null;
    static $requests = [];
    if (! isset($requests[$domain])) {
        $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $request = openssl_csr_new(['commonName' => $domain], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($request, $requests[$domain]);
    }

    return $requests[$domain];
}

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
    $this->csr = nativeCertificateRequest('private.test');
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

it('signs only the public csr for its admitted leaf including a different root node', function (bool $parent): void {
    $leaf = app(StartCertificateOperationAction::class)->convergenceInput($this->node, 'route-81-router', 'private.test');
    $operation = new Operation(['kind' => $parent ? 'route.converge' : 'certificate.converge', 'node_id' => $parent ? 77 : $this->node->id,
        'input' => $parent ? ['certificates' => [$leaf]] : $leaf]);
    $operation->state = ['certificate_requests' => [['node_id' => $this->node->id, 'scope' => $leaf['scope'], 'request_base64' => base64_encode($this->csr)]]];
    $result = app(CertificateSigningPrimitive::class)->execute($operation)['certificates'][0];
    expect($this->signer->calls)->toBe([['private.test', $this->csr]])
        ->and(base64_decode($result['certificate_base64']))->toContain('public leaf')
        ->and(base64_decode($result['root_certificate_base64']))->toBe($this->signer->rootCertificate())
        ->and(json_encode($result))->not->toContain('PRIVATE KEY')->and($this->agents->calls)->toBe([]);
})->with([false, true]);

it('refuses a foreign node scope root or private key without signing', function (string $mismatch): void {
    $leaf = app(StartCertificateOperationAction::class)->convergenceInput($this->node, 'route-81-router', 'private.test');
    $request = ['node_id' => $this->node->id, 'scope' => $leaf['scope'], 'request_base64' => base64_encode($this->csr)];
    match ($mismatch) {
        'node' => $request['node_id']++,
        'scope' => $request['scope'] = 'route-99-router',
        'root' => $leaf['root_hash'] = str_repeat('f', 64),
        'key' => $request['request_base64'] = base64_encode("-----BEGIN PRIVATE KEY-----\ncHVibGlj\n-----END PRIVATE KEY-----\n"),
    };
    $operation = new Operation(['kind' => 'route.converge', 'node_id' => 77, 'input' => ['certificates' => [$leaf]]]);
    if ($mismatch === 'root') {
        $operation->state = ['certificate_requests' => [$request]];
        expect(fn () => app(CertificateSigningPrimitive::class)->execute($operation))->toThrow(ResourceOperationException::class);
    } else {
        expect(fn () => app(CertificateSigningRequests::class)->record($operation, [$request]))->toThrow(ResourceOperationException::class);
    }
    expect($this->signer->calls)->toBe([])->and($this->agents->calls)->toBe([]);
})->with(['node', 'scope', 'root', 'key']);

it('selects the admitted cleanup domain when a parent records several versions of one live scope', function (): void {
    $previous = app(StartCertificateOperationAction::class)->convergenceInput($this->node, 'route-81-router', 'previous.test');
    $cleanup = app(StartCertificateOperationAction::class)->convergenceInput($this->node, 'route-81-router', 'private.test');
    $operation = new Operation(['kind' => 'route.update', 'node_id' => 77, 'input' => ['certificates' => [$previous, $cleanup]],
        'state' => ['certificate_requests' => [['node_id' => $this->node->id, 'scope' => $cleanup['scope'], 'request_base64' => base64_encode($this->csr)]]]]);
    $result = app(CertificateSigningPrimitive::class)->execute($operation);
    expect($this->signer->calls)->toBe([['private.test', $this->csr]])->and($result['certificates'])->toHaveCount(1);
});
