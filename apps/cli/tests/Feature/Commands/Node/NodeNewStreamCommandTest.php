<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;

function fakeNodeBootstrapStreamPrepare(string $id = 'bootstrap-stream'): void
{
    Http::fake([
        'https://gateway.test/api/nodes/bootstrap/resume' => Http::response(JsonEnvelope::success([
            'preflight_required' => true,
        ])),
        'https://gateway.test/api/nodes/bootstrap' => Http::response(JsonEnvelope::success([
            'bootstrap' => [
                'id' => $id,
                'status' => 'pending',
                'host' => '192.0.2.20',
                'user' => 'root',
                'wireguard_address' => '10.6.0.4',
                'script' => "#!/usr/bin/env bash\nset -euo pipefail\n",
            ],
        ])),
    ]);
    Process::fake(fn ($process) => str_contains((string) $process->input, 'ORBIT_TARGET_PLATFORM')
        ? Process::result(output: "ubuntu_24-04\namd64\n")
        : Process::result());
    Process::preventStrayProcesses();
}

describe('NodeNewStream command', function (): void {
    it('renders gateway-authored node:new progress in human mode', function (): void {
        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('tree', [
                'title' => 'Creating Node',
                'steps' => [
                    ['key' => 'operation', 'label' => 'Create operation'],
                    ['key' => 'node', 'label' => 'Create node'],
                ],
            ]).gatewayProgressFrame('step', ['key' => 'node', 'status' => 'running', 'message' => 'Provisioning app-1'])
                .gatewayProgressFrame('complete', [
                    'exit_code' => 0,
                    'data' => ['footer' => "Node 'app-1' created."],
                ]),
        );
        fakeNodeBootstrapStreamPrepare();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.20',
            '--tld' => 'test',
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes/bootstrap/bootstrap-stream/complete'
            && $request->hasHeader('Accept', 'text/event-stream'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Creating Node')
            ->and($output)
            ->toContain('Create operation')
            ->and($output)
            ->toContain('Provisioning app-1')
            ->and($output)
            ->toContain("Node 'app-1' created.");
    });

    it('fails when the NodeNewStream closes without a terminal frame', function (): void {
        fakeGatewayProgressStreamClient(gatewayProgressFrame('step', ['key' => 'node', 'status' => 'running']));
        fakeNodeBootstrapStreamPrepare();

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.20',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('streams JSON progress from the gateway completion phase', function (): void {
        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('tree', ['title' => 'Completing Node'])
                .gatewayProgressFrame('step', ['key' => 'agent', 'status' => 'running'])
                .gatewayProgressFrame('complete', [
                    'exit_code' => 0,
                    'data' => ['result' => ['transport' => 'client-ssh']],
                ]),
        );
        fakeNodeBootstrapStreamPrepare('bootstrap-stream-json');

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.20',
            '--tld' => 'test',
            '--stream-json' => true,
        ]);

        $frames = array_map(
            fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $output)),
        );

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes/bootstrap/bootstrap-stream-json/complete'
            && $request->hasHeader('Accept', 'text/event-stream'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($frames)
            ->toHaveCount(3)
            ->and($frames[0]['event'])
            ->toBe('tree')
            ->and($frames[1]['event'])
            ->toBe('step')
            ->and($frames[2])
            ->toBe([
                'event' => 'complete',
                'success' => [
                    'data' => ['result' => ['transport' => 'client-ssh']],
                    'meta' => [],
                ],
            ]);
    });

    it('preserves registered gateway-authored node failure codes', function (string $code): void {
        $error = [
            'exit_code' => 1,
            'message' => 'Node creation failed.',
            'data' => [
                'code' => $code,
                'message' => 'Node creation failed.',
                'meta' => [],
            ],
        ];
        fakeGatewayProgressStreamClient(gatewayProgressFrame('error', $error));

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['event'])
            ->toBe('error')
            ->and($decoded['data']['data']['code'])
            ->toBe($code);
    })->with(['node.not_found', 'node.tld_in_use']);
});
