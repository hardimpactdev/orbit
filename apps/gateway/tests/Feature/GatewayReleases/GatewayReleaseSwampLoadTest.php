<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseGrantProof;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseSwampLoad;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\GatewayRelease;
use App\Models\Node;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\GatewayReleaseFixture;

beforeEach(function (): void {
    $this->fixture = GatewayReleaseFixture::create(true);
    $this->sha = $this->fixture->commit('Pinned publication');
    $this->id = $this->fixture->prepared($this->sha);
    $this->policy = ['version' => 2, 'principal' => 'user:orbit-gateway',
        'workflows' => [['workflow' => 'operation-test', 'actions' => ['run', 'read']]],
        'vaults' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^test-[0-9]+$', 'actions' => ['write', 'read']]]];
    $this->raw = json_encode($this->policy, JSON_THROW_ON_ERROR);
    $path = $this->fixture->layout->releasePath($this->id).'/swamp/config';
    mkdir($path, 0700, true);
    file_put_contents($path.'/gateway-workflow-grants.json', $this->raw);
    $this->record = GatewayRelease::query()->create(['sha' => $this->sha, 'release_id' => $this->id, 'trigger' => 'deploy', 'outcome' => 'running', 'phases' => [], 'duration_ms' => 0]);
    Node::query()->forceCreate(['id' => 209, 'name' => 'native-control', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'public-test-key']);
    config(['swamp.address' => '10.44.0.209', 'swamp.ca_path' => '/public/test-ca', 'swamp.run_token' => 'private-token-sentinel']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->keepConnected = true;
    $this->socket->reply = static function (array $request): array {
        $payload = (array) $request['payload'];
        if ($payload === []) {
            $rows = array_map(static fn (string $action): array => ['grantId' => 'vault-grant', 'action' => $action, 'resource' => 'vault:orbit-control-inputs',
                'effect' => 'allow', 'condition' => "key.matches('^test-[0-9]+$')"], ['read', 'write']);
            foreach (['read', 'run'] as $action) {
                $rows[] = ['grantId' => 'workflow-grant', 'action' => $action, 'resource' => 'workflow:operation-test', 'effect' => 'allow'];
            }
            foreach (['approve', 'signal'] as $action) {
                $rows[] = ['grantId' => 'workflow-grant', 'action' => $action, 'resource' => 'workflow:operation-test', 'effect' => 'allow', 'impliedBy' => 'run'];
            }
            $rows[] = ['grantId' => 'vault-grant', 'action' => 'tag', 'resource' => 'vault:orbit-control-inputs',
                'effect' => 'allow', 'condition' => "key.matches('^test-[0-9]+$')", 'impliedBy' => 'write'];
        } else {
            $allowed = $payload['resource'] === 'workflow:operation-test' && in_array($payload['action'], ['run', 'read'], true);
            $rows = [['action' => $payload['action'], 'resource' => $payload['resource'], 'effect' => $allowed ? 'allow' : 'deny']];
        }

        return [['id' => $request['id'], 'type' => 'access.can-i', 'payload' => ['principal' => 'user:orbit-gateway', 'decisions' => $rows,
            'approveRequiresExplicitGrant' => false, 'signalRequiresExplicitGrant' => false, 'tagRequiresExplicitGrant' => false]]];
    };
    $this->requests = [];
    $this->changePrepared = $this->changeAccepted = null;
    $this->prepared = null;
    $this->failureAction = $this->nativeFailure = null;
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command): CommandResult {
        expect([$connection->host, $connection->identityFile, $connection->knownHostsFile])->toBe(['10.44.0.209', '/protected/identity', '/protected/known-hosts']);
        expect($command->arguments)->toBe(['sudo', '-n', '/usr/local/libexec/orbit-swamp-release-load.py', '--stdin-json']);
        $request = json_decode($command->input, true, 512, JSON_THROW_ON_ERROR);
        $this->requests[] = $request;
        if ($request['action'] === $this->failureAction) {
            $failure = is_array($this->nativeFailure) ? [
                'schema' => $request['schema'], 'attempt_id' => $request['attempt_id'],
                'state' => 'outcome_uncertain', 'phase' => 'entry',
                'checkpoint' => '/var/lib/orbit-swamp-main-load/releases/pending.json',
                ...$this->nativeFailure,
            ] : $this->nativeFailure;

            return new CommandResult(1, is_array($failure) ? json_encode($failure, JSON_THROW_ON_ERROR) : $failure, '', 10, false);
        }
        if ($request['action'] === 'prepare') {
            $receipt = f2PreparedReceipt($request, hash('sha256', $this->raw));
            $receipt = $this->changePrepared === null ? $receipt : ($this->changePrepared)($receipt);
            $this->prepared = $receipt;
        } else {
            expect($request['receipt_hash'])->toBe($this->prepared['receipt_hash']);
            $receipt = [...$this->prepared, 'state' => 'verified', 'completed_at' => '2026-11-01T12:00:01Z'];
            $receipt = $this->changeAccepted === null ? $receipt : ($this->changeAccepted)($receipt);
        }

        return new CommandResult(0, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), '', 10, false);
    });
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/protected/identity');
    $known = Mockery::mock(KnownHostsStore::class);
    $known->shouldReceive('path')->andReturn('/protected/known-hosts');
    $processes = Mockery::mock(ProcessRunner::class);
    $processes->shouldReceive('run')->andReturnUsing(function ($invocation): CommandResult {
        expect($invocation->arguments)->toBe(['git', '--git-dir='.$this->fixture->layout->repositoryPath(), 'rev-parse', $this->sha.':swamp']);

        return new CommandResult(0, str_repeat('b', 40)."\n", '', 1, false);
    });
    $this->loader = new NativeGatewayReleaseSwampLoad($this->fixture->layout, $ssh, $keys, $known, $processes, new GatewayReleaseGrantProof($this->socket));
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

it('retains exact entry failure details and retries only a proved no-effect prepare refusal', function (bool $safe, string $action): void {
    $this->failureAction = $action;
    $this->nativeFailure = ['code' => $safe ? 'publication_busy' : 'served_workflow_mismatch', 'retry_safe' => $safe];
    $retryable = $safe && $action === 'prepare';
    try {
        $this->loader->load($this->sha, $this->record);
        test()->fail('The entry failure was accepted.');
    } catch (GatewayReleaseException $exception) {
        expect($exception->errorCode)->toBe($retryable ? 'gateway.release_swamp_load_busy' : 'gateway.release_swamp_load_unverified')
            ->and($exception->phase['code'])->toBe($this->nativeFailure['code'])
            ->and($exception->phase['retry_safe'])->toBe($retryable)
            ->and($exception->phase['checkpoint'])->toBe('/var/lib/orbit-swamp-main-load/releases/pending.json')
            ->and($exception->getMessage())->toContain($this->nativeFailure['code']);
    }
    if ($retryable) {
        expect($this->record->fresh()->phases['swamp'])->toMatchArray([
            'outcome' => 'refused', 'retry_safe' => true, 'code' => 'publication_busy',
        ])->and($this->socket->sent)->toBe([]);
    }
})->with([[true, 'prepare'], [false, 'prepare'], [true, 'accept']]);

it('never infers a safe refusal from missing or mismatched entry evidence', function (mixed $failure): void {
    $this->failureAction = 'prepare';
    $this->nativeFailure = $failure;
    try {
        $this->loader->load($this->sha, $this->record);
        test()->fail('The uncertain entry failure was accepted.');
    } catch (GatewayReleaseException $exception) {
        expect($exception->errorCode)->toBe('gateway.release_swamp_load_unverified')
            ->and($exception->phase['retry_safe'] ?? false)->toBeFalse();
    }
    expect($this->record->fresh()->phases['swamp']['outcome'])->toBe('preparing');
})->with(['empty' => [''], 'malformed' => ['not JSON'], 'mismatch' => [['code' => 'publication_busy', 'retry_safe' => true, 'attempt_id' => 'different-attempt']]]);

it('pins one commit proves the configured token and accepts only that receipt', function (): void {
    $result = $this->loader->load($this->sha, $this->record);

    expect($result['outcome'])->toBe('verified')->and($result['configured_token']['checks'])->toBe(6)
        ->and(array_column($this->requests, 'action'))->toBe(['prepare', 'accept']);
    expect($this->requests[0]['release_sha'])->toBe($this->sha)
        ->and($this->requests[0]['gateway_release_record'])->toBe($this->record->id)
        ->and($this->requests[0]['attempt_id'])->toBe($this->requests[1]['attempt_id']);
    expect(json_encode([$result, $this->requests]))->not->toContain('private-token-sentinel');
    expect($this->socket->endpoint->bearerToken)->toBe('private-token-sentinel');
    expect($this->socket->closed)->toBeTrue();
    expect(array_map(static fn (array $request): array => (array) $request['payload'], $this->socket->sent))
        ->toBe([[], ['action' => 'admin', 'resource' => 'access:*']]);
});

it('refuses mismatched native proof before token verification or acceptance', function (string $field, mixed $value): void {
    $this->changePrepared = static function (array $receipt) use ($field, $value): array {
        data_set($receipt, $field, $value);
        unset($receipt['receipt_hash']);
        $receipt['receipt_hash'] = f2ReceiptDigest($receipt);

        return $receipt;
    };

    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare']);
    expect($this->socket->sent)->toBe([]);
})->with([
    ['disk_sha', str_repeat('c', 40)], ['swamp_tree', str_repeat('c', 40)], ['gateway_release_record', -1],
    ['target_node_id', 13], ['restarts', 1], ['worker_restarts', 1], ['runtime_after.pid', 999],
    ['manifest.applied', false], ['manifest.hash', str_repeat('0', 64)], ['health.configured_ca_status', 503],
    ['workflow_inventory.equal', false], ['extension_audit.matched', 0], ['workflows.0.served_hash', 'mismatch'],
]);

it('echoes the opaque receipt hash for the entry to accept over pinned SSH', function (): void {
    $this->changePrepared = static fn (array $receipt): array => [...$receipt, 'receipt_hash' => str_repeat('0', 64)];
    expect($this->loader->load($this->sha, $this->record)['outcome'])->toBe('verified');
    expect($this->requests[1]['receipt_hash'])->toBe(str_repeat('0', 64));
});

it('keeps custody unaccepted when configured token proof is unavailable or wrong', function (string $condition): void {
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => $condition === 'refused' ? 'error' : 'access.can-i',
        'payload' => ['principal' => 'user:someone-else', 'decisions' => []]]];
    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare']);
    expect($this->socket->closed)->toBeTrue();
})->with(['refused', 'principal']);

it('saves prepared native custody before a failing token check', function (): void {
    $this->socket->onConnect = static function (): never {
        throw new RuntimeException('private-token-sentinel');
    };

    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    $phase = $this->record->fresh()->phases['swamp'];
    expect($phase['outcome'])->toBe('awaiting_gateway_verification')
        ->and($phase['attempt_id'])->toBe($this->requests[0]['attempt_id'])
        ->and($phase['receipt']['receipt_hash'])->toBe($this->prepared['receipt_hash']);
    expect(json_encode($phase))->not->toContain('private-token-sentinel');
});

it('does not accept a release with missing or excessive configured-token authority', function (string $fault): void {
    $reply = $this->socket->reply;
    $this->socket->reply = static function (array $request) use ($reply, $fault): array {
        $messages = $reply($request);
        $query = (array) $request['payload'];
        if ($fault === 'vault condition' && $query === []) {
            unset($messages[0]['payload']['decisions'][0]['condition']);
        } elseif ($fault === 'missing read' && $query === []) {
            $messages[0]['payload']['decisions'] = array_values(array_filter($messages[0]['payload']['decisions'],
                static fn (array $row): bool => ! ($row['resource'] === 'workflow:operation-test' && $row['action'] === 'read')));
        } elseif ($fault === 'extra write' && $query === []) {
            $messages[0]['payload']['decisions'][] = ['grantId' => 'extra-write', 'action' => 'write', 'resource' => 'workflow:operation-test', 'effect' => 'allow'];
        } elseif ($fault === 'admin' && ($query['action'] ?? null) === 'admin') {
            $messages[0]['payload']['decisions'][0]['effect'] = 'allow';
        }

        return $messages;
    };

    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare']);
    if ($fault === 'admin') {
        expect(array_last($this->socket->sent)['payload'])->toBe(['action' => 'admin', 'resource' => 'access:*']);
    }
})->with(['missing read', 'extra write', 'vault condition', 'admin']);

it('refuses changed acceptance evidence', function (): void {
    $this->changeAccepted = static function (array $receipt): array {
        $receipt['runtime_after']['pid']++;

        return $receipt;
    };
    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare', 'accept']);
});

it('refuses broad or overriding vault authority even when every exact allow exists', function (string $effect): void {
    $reply = $this->socket->reply;
    $this->socket->reply = static function (array $request) use ($reply, $effect): array {
        $messages = $reply($request);
        if ((array) $request['payload'] === []) {
            $messages[0]['payload']['decisions'][] = ['grantId' => 'broad-vault-grant', 'action' => 'write', 'resource' => 'vault:*', 'effect' => $effect];
        }

        return $messages;
    };

    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare']);
})->with(['allow', 'deny']);

it('requires the same durable active release before any remote effect', function (string $condition): void {
    if ($condition !== 'missing') {
        $this->record->update($condition === 'sha' ? ['sha' => str_repeat('c', 40)] : ['outcome' => 'verified']);
    }
    expect(fn () => $this->loader->load($this->sha, $condition === 'missing' ? null : $this->record))->toThrow(GatewayReleaseException::class);
    expect($this->requests)->toBe([]);
})->with(['outcome', 'sha', 'missing']);

it('rejects extra workflow inventory authority even when exact allows and unrelated denial pass', function (string $resource): void {
    $reply = $this->socket->reply;
    $this->socket->reply = static function (array $request) use ($reply, $resource): array {
        $messages = $reply($request);
        if ((array) $request['payload'] === []) {
            $messages[0]['payload']['decisions'][] = ['grantId' => 'extra-workflow-grant', 'action' => 'run', 'resource' => $resource, 'effect' => 'allow'];
        }

        return $messages;
    };

    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare']);
})->with(['workflow:operation-*', 'workflow:operation-unlisted']);

it('accepts exact grants when native policy disables implied actions', function (): void {
    $reply = $this->socket->reply;
    $this->socket->reply = static function (array $request) use ($reply): array {
        $messages = $reply($request);
        $payload = &$messages[0]['payload'];
        $payload['approveRequiresExplicitGrant'] = $payload['signalRequiresExplicitGrant'] = $payload['tagRequiresExplicitGrant'] = true;
        $payload['decisions'] = array_values(array_filter($payload['decisions'], static fn (array $row): bool => ! isset($row['impliedBy'])));

        return $messages;
    };

    expect($this->loader->load($this->sha, $this->record)['outcome'])->toBe('verified');
});

it('rejects implied authority without its exact native origin and enabled policy', function (string $fault): void {
    $reply = $this->socket->reply;
    $this->socket->reply = static function (array $request) use ($reply, $fault): array {
        $messages = $reply($request);
        if ((array) $request['payload'] === []) {
            foreach ($messages[0]['payload']['decisions'] as &$row) {
                if (($row['action'] ?? null) !== 'approve') {
                    continue;
                }
                match ($fault) {
                    'grant' => $row['grantId'] = 'other-grant',
                    'parent' => $row['impliedBy'] = 'write',
                    'explicit' => $row['impliedBy'] = null,
                    'resource' => $row['resource'] = 'workflow:operation-unlisted',
                    'condition' => $row['condition'] = 'unrelated',
                    'policy' => $messages[0]['payload']['approveRequiresExplicitGrant'] = true,
                };
            }
        }

        return $messages;
    };

    expect(fn () => $this->loader->load($this->sha, $this->record))->toThrow(GatewayReleaseException::class);
    expect(array_column($this->requests, 'action'))->toBe(['prepare']);
})->with(['grant', 'parent', 'explicit', 'resource', 'condition', 'policy']);

/** Native protocol fixture includes an empty JSON object to cover canonical digest fidelity. */
function f2PreparedReceipt(array $request, string $manifest): array
{
    unset($request['action']);
    $receipt = [...$request, 'requested_sha' => $request['release_sha'], 'disk_sha' => $request['release_sha'], 'swamp_tree' => str_repeat('b', 40),
        'state' => 'awaiting_gateway_verification', 'runtime_before' => ['pid' => 123, 'invocation' => 'native-invocation'],
        'runtime_after' => ['pid' => 123, 'invocation' => 'native-invocation'], 'restarts' => 0, 'worker_restarts' => 0,
        'reload_result' => ['success' => true, 'details' => (object) []], 'reload_ack_at' => '2026-11-01T12:00:00Z',
        'manifest' => ['source_sha' => $request['release_sha'], 'hash' => $manifest, 'applied' => true],
        'health' => ['loopback_status' => 200, 'configured_ca_status' => 200],
        'extension_audit' => ['checked' => 1, 'matched' => 1, 'mismatches' => []],
        'workflow_inventory' => ['equal' => true, 'disk_count' => 1, 'served_count' => 1, 'scope' => 'all_id_name_pairs'],
        'workflows' => [['equal' => true, 'disk_hash' => 'definition-hash', 'served_hash' => 'definition-hash']],
    ];
    $receipt['receipt_hash'] = f2ReceiptDigest($receipt);

    return $receipt;
}

function f2ReceiptDigest(array $receipt): string
{
    $sort = function (mixed $value) use (&$sort): mixed {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        }

        return $value;
    };

    return hash('sha256', json_encode($sort($receipt), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
