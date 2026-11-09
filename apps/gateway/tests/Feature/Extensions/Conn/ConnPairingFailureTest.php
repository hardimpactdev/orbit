<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orbit\Extensions\Conn\Domain\ConnPairingProjection;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

uses(LazilyRefreshDatabase::class);

/** One admitted pairing Operation as IssueConnPairingAction records it. */
function connPairingOperation(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'nick', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64', 'public_ssh_host' => '10.44.0.6', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.6', 'user' => 'nick', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-beast', 'label' => 'beast', 'url' => 'http://10.44.0.7:3773',
        'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    $pairing = ConnPairing::query()->create(['node_id' => $node->id, 'conn_environment_id' => $environment->id, 'client_label' => 'nick via Orbit k3j9x0qa']);

    return Operation::query()->create(['kind' => 'conn.pairing_create', 'target_type' => 'conn_pairing', 'target_id' => $pairing->id, 'node_id' => $node->id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => [
            'conn_environment_id' => $environment->id, 'pairing_ids' => [$pairing->id], 'caller_node_id' => $node->id,
            'intent' => ['url' => $environment->url, 'pairing_id' => $pairing->id, 'label' => $pairing->client_label, 'request_body' => '{}'],
            'execution_secrets' => ['admin_session' => ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-create-00000000-0000-4000-8000-000000000001']],
        ]]);
}

function failConnPairing(Operation $operation, string $detail): Operation
{
    return app(ConnPairingProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subSeconds(4)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => [], 'failure' => ['failed_step' => 'Store the link in vault', 'detail' => $detail, 'stderr_recorded' => false],
    ]);
}

it('keeps the code of every answer T3 gave, so callers do not retry it', function (string $code): void {
    $projected = failConnPairing(connPairingOperation(), $code);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe($code)
        ->and($projected->result)->toBe(['error_code' => $code]);
})->with(ConnPairingProjection::AnsweredFailures);

it('reports an unknown outcome when no T3 answer was read', function (string $detail): void {
    $projected = failConnPairing(connPairingOperation(), $detail);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('conn.pairing_outcome_unknown');
})->with(['conn.pairing_outcome_unknown', 'Mint a pairing link timed out after 15s', 'The native step failed without stderr.']);
