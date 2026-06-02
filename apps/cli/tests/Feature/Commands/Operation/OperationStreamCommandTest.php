<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('Operation stream commands', function (): void {
    it('streams DoctorStream verify payloads to the gateway', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'doctor' => ['healthy' => true],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--family' => ['node'],
            '--key' => 'node.record_incomplete',
            '--self' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/doctor/run'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'mode' => 'verify',
                'families' => ['node'],
                'key' => 'node.record_incomplete',
                'self' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toBe([
                'event' => 'complete',
                'data' => $complete,
            ]);
    });

    it('streams DoctorStream restore payloads to the fix endpoint', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => ['doctor' => ['mode' => 'restore']],
        ]));

        [$exitCode] = runCommand($this, 'doctor', [
            '--restore' => true,
            '--family' => ['app'],
            '--node' => 'app-1',
            '--dry-run' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/doctor/fix'
            && $request->data() === [
                'mode' => 'restore',
                'families' => ['app'],
                'node' => 'app-1',
                'dry_run' => true,
            ]);

        expect($exitCode)->toBe(0);
    });

    it('ignores UpdateAllStream keepalive comments while waiting for the terminal frame', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayOperationEventStreamClient::class);
        app()->forgetInstance(GatewayOperationFollower::class);
        app()->instance(RunsLocalUpdate::class, new OperationStreamUpdateAllFakeUpdater);

        Http::fake([
            'https://gateway.test/api/update/all/start' => Http::response(operationStreamUpdateAllStartEnvelope(), 200),
            'https://gateway.test/api/operations/run-1/events' => Http::response(
                ": heartbeat\n\n"
                ."id: 1\n"
                .gatewayProgressFrame('complete', ['exit_code' => 0, 'data' => ['updates' => []]]),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        [$exitCode, $output] = runCommand($this, 'update:all', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/update/all/start');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/operations/run-1/events'
            && $request->hasHeader('Accept', 'text/event-stream'));

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($output)->not->toContain('heartbeat');
    });
});

/**
 * @return array<string, mixed>
 */
function operationStreamUpdateAllStartEnvelope(): array
{
    return fakeSuccessEnvelope([
        'operation_run' => [
            'id' => 'run-1',
            'type' => 'update:all',
            'status' => 'queued',
        ],
        'update_plan' => [
            'target_version' => '1.2.3',
            'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', 64),
            'manifest_source' => 'github-release',
            'manifest_version' => '1.2.3',
        ],
        'events_url' => '/api/operations/run-1/events',
    ]);
}

final class OperationStreamUpdateAllFakeUpdater implements RunsLocalUpdate
{
    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }
}
