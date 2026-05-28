<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('ToolStream commands', function (): void {
    it('streams tool:install and emits only the final complete frame in json mode', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => "Tool 'redis' installed on app-1.",
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'state' => 'running'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Installing Tool'])
            .gatewayProgressFrame('step', ['key' => 'install', 'status' => 'running', 'message' => 'Installing redis'])
            .gatewayProgressFrame('complete', $complete),
        );

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--status' => 'running',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/redis/install'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'app-1',
                'status' => 'running',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toBe([
                'event' => 'complete',
                'data' => $complete,
            ])
            ->and(count(array_filter(explode("\n", $output))))->toBe(1)
            ->and($output)->not->toContain('Installing redis');
    });

    it('streams lifecycle action commands to their gateway endpoints', function (string $command, string $endpoint): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'action' => $endpoint],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, $command, [
            'tool' => 'redis',
            '--app' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === "https://gateway.test/api/tools/redis/{$endpoint}"
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'app' => 'docs',
                'node' => 'app-1',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data'])->toBe($complete);
    })->with([
        ['tool:start', 'start'],
        ['tool:stop', 'stop'],
        ['tool:restart', 'restart'],
        ['tool:reload', 'reload'],
    ]);

    it('streams tool:update payloads to the single-tool gateway endpoint', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'redis', 'node' => 'app-1', 'version' => '7.2'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--expected-version' => '7.2',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/redis/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'node' => 'app-1',
                'version' => '7.2',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data'])->toBe($complete);
    });

    it('streams tool:update bulk payloads when the tool argument is omitted', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'updated' => [],
                'skipped' => [
                    ['tool' => 'redis', 'node' => 'app-1', 'reason' => 'null_latest_version'],
                ],
                'failed' => [],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/update'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === ['node' => 'app-1']);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data'])->toBe($complete);
    });

    it('streams tool:reconfigure payloads to the gateway', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'opencode-server', 'node' => 'app-1', 'action' => 'reconfigured'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'tool:reconfigure', [
            'tool' => 'opencode-server',
            '--app' => 'docs',
            '--password' => 'newpass',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/tools/opencode-server/reconfigure'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'app' => 'docs',
                'password' => 'newpass',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['event'])->toBe('complete')
            ->and($decoded['data'])->toBe($complete);
    });

    it('renders gateway-authored tool progress in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Starting Tool',
                'steps' => [
                    ['key' => 'service', 'label' => 'Start service unit'],
                ],
            ])
            .gatewayProgressFrame('step', ['key' => 'service', 'status' => 'running', 'message' => 'Starting supervisor'])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => ['footer' => "Tool 'supervisor' started."],
            ]),
        );

        [$exitCode, $output] = runCommand($this, 'tool:start', [
            'tool' => 'supervisor',
            '--node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Starting Tool')
            ->and($output)->toContain('Start service unit')
            ->and($output)->toContain('Starting supervisor')
            ->and($output)->toContain("Tool 'supervisor' started.");
    });

    it('emits only the final tool error frame in json mode', function (): void {
        $error = [
            'exit_code' => 1,
            'message' => 'Tool action failed.',
            'data' => [
                'code' => 'tool.action_failed',
                'message' => 'Tool action failed.',
                'meta' => ['tool' => 'redis', 'action' => 'restart'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('step', ['key' => 'restart', 'status' => 'running', 'message' => 'Restarting redis'])
            .gatewayProgressFrame('error', $error),
        );

        [$exitCode, $output] = runCommand($this, 'tool:restart', [
            'tool' => 'redis',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded)->toBe([
                'event' => 'error',
                'data' => $error,
            ])
            ->and(count(array_filter(explode("\n", $output))))->toBe(1)
            ->and($output)->not->toContain('Restarting redis');
    });

    it('preserves gateway error envelopes before a stream starts', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope('tool.unsupported_action', "Tool 'docker' does not support install.", [
            'tool' => 'docker',
            'action' => 'install',
        ]), JSON_THROW_ON_ERROR), 422);

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'docker',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('tool.unsupported_action')
            ->and($decoded['error']['message'])->toBe("Tool 'docker' does not support install.")
            ->and($decoded['error']['meta']['tool'])->toBe('docker');
    });
});
