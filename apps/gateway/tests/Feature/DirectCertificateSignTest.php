<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Certificates\FilesystemRootCertificate;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\RootCaFixture;

beforeAll(function (): void {
    RootCaFixture::prepare('direct-sign', '/CN=Orbit Test Root CA', 3650,
        ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign']);
});

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/orbit-sign-test-'.bin2hex(random_bytes(8));
    mkdir($this->home.'/ca', 0o700, true);
    RootCaFixture::install($this->home.'/ca', 'direct-sign');
    config(['orbit.home' => $this->home]);
    app()->instance(LeafCertificateSigner::class, new FilesystemRootCertificate($this->home));
    app()->instance(ProcessRunner::class, new NativeProcessRunner);
    $this->node = Node::query()->create(['name' => 'leaf-target', 'public_ssh_host' => '192.0.2.9',
        'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $this->signerNode = Node::query()->forceCreate(['id' => 209, 'name' => 'signer', 'public_ssh_host' => '192.0.2.209',
        'wireguard_ip' => '10.44.0.209', 'status' => LifecycleStatus::Active]);
    $this->runId = 'c97d0ea3-3c9e-4cdb-a306-10e9e71e21f8';
    $this->root = file_get_contents($this->home.'/ca/root.pem');
    $this->leaf = ['node_id' => $this->node->id, 'scope' => 'route-31', 'domain' => 'example.orbit',
        'root_hash' => hash('sha256', $this->root)];
    $this->operation = Operation::query()->create(['kind' => 'certificate.converge', 'target_type' => 'node_certificate',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Running,
        'state' => ['swamp_run_id' => $this->runId], 'input' => $this->leaf]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/certificates/sign';
    $this->body = ['run_id' => $this->runId, 'requests' => [['node_id' => $this->node->id,
        'scope' => $this->leaf['scope'], 'state' => 'request', 'request_base64' => base64_encode(direct_sign_csr())]]];
    $this->withServerVariables(['REMOTE_ADDR' => $this->signerNode->wireguard_ip]);
});

afterEach(function (): void {
    expect(json_encode([Activity::query()->get()->toArray(), Operation::query()->get()->toArray()], JSON_THROW_ON_ERROR))
        ->not->toContain('PRIVATE KEY', 'CERTIFICATE REQUEST', 'private-caller-sentinel', base64_encode('private-caller-sentinel'));
    (new Filesystem)->deleteDirectory($this->home);
});

it('signs only the admitted public RSA leaf and audits selectors without changing the Operation', function (): void {
    $before = $this->operation->fresh()->toArray();
    $response = $this->postJson($this->path, $this->body)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $bundle = $response->json('data.certificates.0');
    $certificate = base64_decode($bundle['certificate_base64'], true);
    $parsed = openssl_x509_parse($certificate);
    $public = openssl_pkey_get_details(openssl_pkey_get_public($certificate));
    expect(array_keys($bundle))->toBe(['node_id', 'scope', 'certificate_base64', 'root_certificate_base64'])
        ->and(base64_decode($bundle['root_certificate_base64'], true))->toBe($this->root)
        ->and($public['type'])->toBe(OPENSSL_KEYTYPE_RSA)->and($public['bits'])->toBe(2048)
        ->and($parsed['subject']['CN'])->toBe('example.orbit')
        ->and($parsed['extensions']['subjectAltName'])->toBe('DNS:example.orbit')
        ->and($parsed['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and($parsed['extensions']['extendedKeyUsage'])->toBe('TLS Web Server Authentication')
        ->and($parsed['validTo_time_t'] - $parsed['validFrom_time_t'])->toBe(397 * 86400)
        ->and(openssl_x509_verify($certificate, openssl_pkey_get_public($this->root)))->toBe(1)
        ->and($response->getContent())->not->toContain('PRIVATE KEY')
        ->and($this->operation->fresh()->toArray())->toBe($before)
        ->and(Activity::query()->where('command', 'operation:certificate:sign')->sole()->properties->toArray())->toBe([
            'operation_id' => $this->operation->id, 'run_id' => $this->runId, 'caller_node_id' => 209,
            'node_id' => $this->node->id, 'scope' => 'route-31', 'domain' => 'example.orbit', 'root_hash' => $this->leaf['root_hash'],
        ]);
});

it('also permits the authenticated root Node without a fleet access edge', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->postJson($this->path, $this->body)->assertOk();
});

it('denies an unknown or unrelated active peer', function (bool $known): void {
    if ($known) {
        Node::query()->create(['name' => 'foreign', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'status' => LifecycleStatus::Active]);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.10']);
    $this->postJson($this->path, $this->body)->assertForbidden();
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
})->with([false, true]);

it('refuses a wrong run, terminal intent, cancellation or stale root', function (string $change): void {
    match ($change) {
        'run' => $this->body['run_id'] = 'e9c6ca86-29c4-4646-ab88-319b61de6d6c',
        'terminal' => $this->operation->update(['status' => OperationStatus::Succeeded]),
        'cancel' => $this->operation->update(['cancel_requested_at' => now()]),
        'root' => $this->operation->update(['input' => [...$this->leaf, 'root_hash' => str_repeat('0', 64)]]),
    };
    $this->postJson($this->path, $this->body)->assertConflict();
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
})->with(['run', 'terminal', 'cancel', 'root']);

it('refuses foreign leaf selectors before issuing any certificate', function (string $field): void {
    $this->body['requests'][0][$field] = $field === 'node_id' ? 209 : 'route-32';
    $this->postJson($this->path, $this->body)->assertConflict()->assertJsonPath('error.code', 'certificate.intent_mismatch');
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
})->with(['node_id', 'scope']);

it('refuses unexpected body and leaf authority fields', function (string $field): void {
    if ($field === 'domain') {
        $this->body['requests'][0]['domain'] = 'foreign.orbit';
    } else {
        $this->body[$field] = 'foreign';
    }
    $this->postJson($this->path, $this->body)->assertUnprocessable();
})->with(['domain', 'input', 'root_hash']);

it('rejects malformed, wrong-domain, weak, EC and extra-SAN requests', function (string $case): void {
    $csr = match ($case) {
        'malformed' => 'not a CSR',
        'domain' => direct_sign_csr('foreign.orbit'),
        'weak' => direct_sign_csr(bits: 1024),
        'large' => direct_sign_csr(bits: 4096),
        'ec' => direct_sign_csr(ec: true),
        'signature' => direct_sign_bad_signature(),
        'trailing' => direct_sign_csr()."-----BEGIN PRIVATE KEY-----\nprivate-caller-sentinel\n-----END PRIVATE KEY-----\n",
        'san' => direct_sign_csr(san: 'DNS:example.orbit,DNS:foreign.orbit'),
        'ip' => direct_sign_csr(san: 'DNS:example.orbit,IP:127.0.0.1'),
    };
    $this->body['requests'][0]['request_base64'] = base64_encode($csr);
    $this->postJson($this->path, $this->body)->assertUnprocessable()->assertJsonPath('error.code', 'certificate.request_invalid');
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
})->with(['malformed', 'domain', 'weak', 'large', 'ec', 'signature', 'trailing', 'san', 'ip']);

it('returns no new bundle for an admitted already-current leaf', function (): void {
    $this->body['requests'][0]['state'] = 'current';
    unset($this->body['requests'][0]['request_base64']);
    unlink($this->home.'/ca/root.key');
    $this->postJson($this->path, $this->body)->assertOk()->assertJsonPath('data.certificates', []);
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
});

it('does not copy privilege extensions from a CSR', function (): void {
    $this->body['requests'][0]['request_base64'] = base64_encode(direct_sign_csr(ca: true));
    $response = $this->postJson($this->path, $this->body)->assertOk();
    $certificate = base64_decode($response->json('data.certificates.0.certificate_base64'), true);
    expect(openssl_x509_parse($certificate)['extensions']['basicConstraints'])->toBe('CA:FALSE');
});

it('refuses missing or mismatched Gateway CA material without changing custody', function (): void {
    unlink($this->home.'/ca/root.key');
    $this->postJson($this->path, $this->body)->assertServiceUnavailable()->assertJsonPath('error.code', 'certificate.signer_unavailable');
    expect(is_file($this->home.'/ca/root.key'))->toBeFalse();
});

it('reads the existing canonical admitted leaf containers for every issuing family', function (string $kind, string $path): void {
    $input = [];
    data_set($input, $path, [$this->leaf]);
    $this->operation->update(['kind' => $kind, 'input' => $input]);
    if ($kind === 'node.remove') {
        $this->body['restoring'] = true;
    }
    $this->postJson($this->path, $this->body)->assertOk()->assertJsonPath('data.certificates.0.scope', 'route-31');
})->with([
    ['route.create', 'route_lifecycle.preparation.certificates'], ['route.target_set', 'route_target.preparation.certificates'], ['cluster.router_set', 'certificates'],
    ['analytics.enable', 'tracking.certificates'], ['node.role_add', '_role.certificates.leaves'],
    ['node.role_converge', '_role.certificates.leaves'], ['node.role_relocate', '_relocate.certificates.leaves'],
    ['node.remove', '_removal.restore_certificates'], ['instance.transfer', 'route_publication.route_mutations.forward.certificates'],
]);

it('rechecks the original live intent after signing before releasing a public bundle', function (): void {
    $operation = $this->operation;
    app()->instance(ProcessRunner::class, new class($operation) implements ProcessRunner
    {
        public function __construct(private Operation $operation) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $result = (new NativeProcessRunner)->run($invocation);
            $this->operation->update(['input' => [...$this->operation->input, 'domain' => 'changed.orbit']]);

            return $result;
        }
    });
    $this->postJson($this->path, $this->body)->assertConflict()->assertJsonPath('error.code', 'certificate.intent_mismatch');
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
});

it('does not infer signing authority from certificate-looking input on another kind', function (): void {
    $this->operation->update(['kind' => 'firewall.inspect', 'input' => ['certificates' => [$this->leaf]]]);
    $this->postJson($this->path, $this->body)->assertForbidden()->assertJsonPath('error.code', 'certificate.intent_missing');
});

it('validates the entire admitted selector batch before signing', function (): void {
    $this->body['requests'][] = [...$this->body['requests'][0], 'scope' => 'foreign-31'];
    $this->postJson($this->path, $this->body)->assertConflict()->assertJsonPath('error.code', 'certificate.intent_mismatch');
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
});

it('accepts a full Node certificate batch while checking every admitted selector', function (int $count): void {
    $leaves = [];
    $requests = [];
    for ($index = 1; $index <= $count; $index++) {
        $leaves[] = [...$this->leaf, 'scope' => 'route-'.$index.'-router'];
        $requests[] = ['node_id' => $this->node->id, 'scope' => 'route-'.$index.'-router', 'state' => 'current'];
    }
    $requests[$count - 1] = [...$requests[$count - 1], 'state' => 'request', 'request_base64' => $this->body['requests'][0]['request_base64']];
    $this->operation->update(['kind' => 'cluster.router_set', 'input' => ['certificates' => $leaves]]);
    $before = $this->operation->fresh()->toArray();

    $this->postJson($this->path, ['run_id' => $this->runId, 'requests' => $requests])->assertOk()
        ->assertJsonCount(1, 'data.certificates')->assertJsonPath('data.certificates.0.scope', 'route-'.$count.'-router');

    expect($this->operation->fresh()->toArray())->toBe($before)
        ->and(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(1);
})->with([120, 256]);

it('refuses an oversized Node batch before issuing any certificate', function (): void {
    $requests = array_fill(0, 257, ['node_id' => $this->node->id, 'scope' => 'route-31', 'state' => 'current']);
    $before = $this->operation->fresh()->toArray();

    $this->postJson($this->path, ['run_id' => $this->runId, 'requests' => $requests])->assertUnprocessable();

    expect($this->operation->fresh()->toArray())->toBe($before)
        ->and(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
});

it('rejects duplicate scope selectors instead of signing twice', function (): void {
    $this->body['requests'][] = $this->body['requests'][0];
    $this->postJson($this->path, $this->body)->assertConflict();
});

function direct_sign_bad_signature(): string
{
    $csr = direct_sign_csr();
    $body = preg_replace('/-----[^\r\n]+-----|\s+/', '', $csr);
    $der = base64_decode($body, true);
    $der[strlen($der) - 1] = chr(ord($der[strlen($der) - 1]) ^ 1);

    return "-----BEGIN CERTIFICATE REQUEST-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE REQUEST-----\n";
}

/** Disposable public CSR; its key is never sent to the endpoint. */
function direct_sign_csr(string $domain = 'example.orbit', int $bits = 2048, bool $ec = false, ?string $san = null, bool $ca = false): string
{
    static $cache = [];
    $index = json_encode(func_get_args());
    if (isset($cache[$index])) {
        return $cache[$index];
    }
    $key = openssl_pkey_new($ec ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
        : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
    $configuration = tempnam(sys_get_temp_dir(), 'orbit-csr-test-');
    file_put_contents($configuration, "[req]\ndistinguished_name=dn\nreq_extensions=leaf\n[dn]\n[leaf]\n".
        ($san !== null ? "subjectAltName={$san}\n" : '').($ca ? "basicConstraints=critical,CA:TRUE\n" : ''));
    try {
        $csr = openssl_csr_new(['commonName' => $domain], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
        openssl_csr_export($csr, $pem);

        return $cache[$index] = $pem;
    } finally {
        unlink($configuration);
    }
}

it('signs only the captured Route phase when one stable scope changes domain', function (string $kind, string $key, bool $restoring): void {
    $prior = [...$this->leaf, 'domain' => 'before.orbit'];
    $input = ['certificates' => [$this->leaf, $prior], $key => ['preparation' => ['certificates' => [$this->leaf]], 'route_mutations' => [
        'forward' => ['prepare_certificates' => [$this->leaf], 'certificates' => [$this->leaf]],
        'restore' => ['certificates' => [$prior]],
    ]]];
    $this->operation->update(['kind' => $kind, 'input' => $input]);
    $this->body['restoring'] = $restoring;
    $domain = $restoring ? 'before.orbit' : 'example.orbit';
    $this->body['requests'][0]['request_base64'] = base64_encode(direct_sign_csr($domain));
    $response = $this->postJson($this->path, $this->body)->assertOk();
    $certificate = base64_decode($response->json('data.certificates.0.certificate_base64'), true);
    expect(openssl_x509_parse($certificate)['subject']['CN'])->toBe($domain)
        ->and($this->operation->fresh()->input)->toBe($input);
})->with([['route.update', 'route_update', false], ['route.update', 'route_update', true],
    ['route.target_set', 'route_target', false], ['route.target_set', 'route_target', true], ['route.create', 'route_lifecycle', false], ['route.create', 'route_lifecycle', true], ['route.destroy', 'route_lifecycle', true]]);

it('does not grant Route signing authority from the opposite phase or a flat fallback', function (string $kind, string $key, bool $missing): void {
    $prior = [...$this->leaf, 'domain' => 'before.orbit'];
    $input = ['certificates' => [$this->leaf, $prior]];
    if (! $missing) {
        $input[$key] = ['preparation' => ['certificates' => [$this->leaf]], 'route_mutations' => [
            'forward' => ['prepare_certificates' => [$this->leaf], 'certificates' => [$this->leaf]],
            'restore' => ['certificates' => [$prior]],
        ]];
    }
    $this->operation->update(['kind' => $kind, 'input' => $input]);
    $this->body['requests'][0]['request_base64'] = base64_encode(direct_sign_csr('before.orbit'));
    $this->postJson($this->path, $this->body)->assertStatus($missing ? 403 : 422)
        ->assertJsonPath('error.code', $missing ? 'certificate.intent_missing' : 'certificate.request_invalid');
    expect(Activity::query()->where('command', 'operation:certificate:sign')->count())->toBe(0);
})->with([['route.update', 'route_update', false], ['route.update', 'route_update', true],
    ['route.target_set', 'route_target', false], ['route.target_set', 'route_target', true], ['route.create', 'route_lifecycle', false], ['route.create', 'route_lifecycle', true], ['route.destroy', 'route_lifecycle', true]]);
