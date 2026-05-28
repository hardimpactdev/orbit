<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('NodeNewStream command', function (): void {
    it('renders gateway-authored node:new progress in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Creating Node',
                'steps' => [
                    ['key' => 'operation', 'label' => 'Create operation'],
                    ['key' => 'node', 'label' => 'Create node'],
                ],
            ])
            .gatewayProgressFrame('step', ['key' => 'node', 'status' => 'running', 'message' => 'Provisioning app-1'])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => ['footer' => "Node 'app-1' created."],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.20',
            '--tld' => 'test',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/nodes'
            && $request->hasHeader('Accept', 'text/event-stream'));

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Creating Node')
            ->and($output)->toContain('Create operation')
            ->and($output)->toContain('Provisioning app-1')
            ->and($output)->toContain("Node 'app-1' created.");
    });

    it('fails when the NodeNewStream closes without a terminal frame', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('step', ['key' => 'node', 'status' => 'running']));

        [$exitCode, $output] = runCommand($this, 'node:new', [
            'name' => 'app-1',
            '--roles' => 'app-dev',
            '--host' => '192.0.2.20',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });
});
