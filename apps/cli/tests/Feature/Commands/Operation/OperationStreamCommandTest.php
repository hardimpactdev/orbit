<?php

declare(strict_types=1);

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
        fakeGatewayProgressStream(
            ": heartbeat\n\n"
            .gatewayProgressFrame('complete', ['exit_code' => 0, 'data' => ['updates' => []]]),
        );

        [$exitCode, $output] = runCommand($this, 'update:all', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/update/all'
            && $request->hasHeader('Accept', 'text/event-stream'));

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($output)->not->toContain('heartbeat');
    });
});
