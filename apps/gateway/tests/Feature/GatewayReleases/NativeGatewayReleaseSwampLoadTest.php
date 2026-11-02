<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseSwampLoad;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'public_ssh_host' => '192.0.2.209',
        'wireguard_ip' => '10.44.0.15', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture-load-token']);
    $this->sha = str_repeat('a', 40);
});

/** A load whose native run ends with the given status at its first wait. */
function swamp_load(OperationStatus $ends, ?string $errorCode = null, int $waitSeconds = 600): NativeGatewayReleaseSwampLoad
{
    $now = 0.0;

    return new NativeGatewayReleaseSwampLoad(app(StartOperationAction::class), $waitSeconds,
        sleep: static function (int $microseconds) use (&$now, $ends, $errorCode): void {
            $now += $microseconds * 1_000;
            if ($ends !== OperationStatus::Running) {
                Operation::query()->sole()->update(['status' => $ends, 'error_code' => $errorCode]);
            }
        },
        clock: static function () use (&$now): float {
            return $now;
        });
}

it('records one load intent for the commit on the Swamp Node and returns after the native run succeeds', function (): void {
    $loaded = swamp_load(OperationStatus::Succeeded)->load($this->sha);
    $operation = Operation::query()->sole();

    expect($loaded)->toBe(['outcome' => 'loaded', 'operation_id' => $operation->id])
        ->and($operation->only(['kind', 'target_type', 'target_id', 'node_id', 'input', 'triggered_by']))->toBe([
            'kind' => 'gateway.swamp_load', 'target_type' => 'node', 'target_id' => 209, 'node_id' => 209,
            'input' => ['sha' => $this->sha], 'triggered_by' => 'gateway-release',
        ])
        ->and($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-gateway-swamp-load')
        ->and($this->socket->sent[0]['payload']['inputs'])->toMatchArray([
            'operation_id' => $operation->id, 'node_id' => 209, 'kind' => 'gateway.swamp_load',
            'intent' => ['sha' => $this->sha],
        ])
        ->and($this->socket->sent[0]['payload']['inputs']['execution_secrets'])->toEqual(new stdClass);
});

it('stops the release when the native load fails', function (): void {
    expect(fn () => swamp_load(OperationStatus::Failed, 'swamp.step_failed')->load($this->sha))
        ->toThrow(function (GatewayReleaseException $exception): void {
            expect($exception->step)->toBe('swamp')
                ->and($exception->errorCode)->toBe('gateway.release_swamp_load_failed')
                ->and($exception->sha)->toBe($this->sha)
                ->and($exception->getMessage())->toContain('ended failed with swamp.step_failed');
        });
});

it('stops the release when the native load does not finish in time', function (): void {
    expect(fn () => swamp_load(OperationStatus::Running, waitSeconds: 5)->load($this->sha))
        ->toThrow(GatewayReleaseException::class, 'within 5 seconds');
});

it('stops the release when Swamp refuses the start', function (): void {
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket(accepted: false));

    expect(fn () => swamp_load(OperationStatus::Succeeded)->load($this->sha))
        ->toThrow(GatewayReleaseException::class, 'did not start the load');
});

it('waits for a new serve instance and loads again when the load restarted serve', function (): void {
    $now = 0.0;
    $instances = ['old', 'old', null, 'new'];
    $restart = ['reason' => 'additional_files', 'served' => str_repeat('b', 40),
        'manifests' => ['swamp/extensions/behaviors/operations/x/manifest.yaml'], 'issue' => 'https://swamp-club.com/lab/4282'];
    $load = new NativeGatewayReleaseSwampLoad(app(StartOperationAction::class), 600,
        sleep: static function (int $microseconds) use (&$now, $restart): void {
            $now += $microseconds * 1_000;
            $running = Operation::query()->where('status', OperationStatus::Running)->orWhere('status', OperationStatus::Queued)->get();
            foreach ($running as $operation) {
                $first = Operation::query()->count() === 1;
                $operation->update(['status' => OperationStatus::Succeeded, 'result' => $first ? ['restart' => $restart] : ['workflows' => 3]]);
            }
        },
        clock: static function () use (&$now): float {
            return $now;
        },
        instance: static function () use (&$instances): ?string {
            return array_shift($instances);
        });

    $loaded = $load->load($this->sha);
    [$first, $second] = Operation::query()->orderBy('id')->get()->all();

    expect($loaded)->toBe(['outcome' => 'loaded', 'operation_id' => $second->id,
        'restart' => [...$restart, 'operation_id' => $first->id, 'instance' => 'new', 'waited_seconds' => 2]])
        ->and($this->socket->sent)->toHaveCount(2)
        ->and($instances)->toBe([]);
});

it('stops the release when serve does not come back after the restart', function (): void {
    $now = 0.0;
    $load = new NativeGatewayReleaseSwampLoad(app(StartOperationAction::class), 600,
        sleep: static function (int $microseconds) use (&$now): void {
            $now += $microseconds * 1_000;
            Operation::query()->whereIn('status', [OperationStatus::Running, OperationStatus::Queued])
                ->update(['status' => OperationStatus::Succeeded, 'result' => ['restart' => ['reason' => 'additional_files']]]);
        },
        clock: static function () use (&$now): float {
            return $now;
        },
        instance: static fn (): string => 'old');

    expect(fn () => $load->load($this->sha))->toThrow(GatewayReleaseException::class, 'did not come back after the restart');
    expect(Operation::query()->count())->toBe(1);
});
