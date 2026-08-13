<?php

declare(strict_types=1);

describe('ToolStream commands', function (): void {
    it('streams tool:install and emits only the final complete frame in json mode', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => "Tool 'composer' installed on app-1.",
                'tool' => ['name' => 'composer', 'node' => 'app-1', 'state' => 'installed'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Installing Tool'])
                .gatewayProgressFrame('step', [
                    'key' => 'install',
                    'status' => 'running',
                    'message' => 'Installing composer',
                ])
                .gatewayProgressFrame('complete', $complete),
        );

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/install'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'event' => 'complete',
                'data' => $complete,
            ])
            ->and(count(array_filter(explode("\n", $output))))
            ->toBe(1)
            ->and($output)
            ->not->toContain('Installing composer');
    });

    it('sends with_process=false for tool:install --no-process', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => ['tool' => [
                    'name' => 'opencode-cli',
                    'node' => 'app-1',
                    'state' => 'installed',
                    'process' => null,
                ]],
            ]),
        );

        [$exitCode] = runCommand($this, 'tool:install', [
            'tool' => 'opencode-cli',
            '--node' => 'app-1',
            '--no-process' => true,
            '--json' => true,
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/opencode-cli/install'
                && $request->data() === [
                    'node' => 'app-1',
                    'with_process' => false,
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('streams tool:update payloads to the single-tool gateway endpoint', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'composer', 'node' => 'app-1', 'version' => '2.8'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--expected-version' => '2.8',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/composer/update'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'node' => 'app-1',
                    'version' => '2.8',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data'])
            ->toBe($complete);
    });

    it('streams tool:update bulk payloads when the tool argument is omitted', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'updated' => [],
                'skipped' => [
                    ['tool' => 'composer', 'node' => 'app-1', 'reason' => 'null_latest_version'],
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

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/update'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === ['node' => 'app-1']
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data'])
            ->toBe($complete);
    });

    it('returns a failed complete result when a bulk tool update has failed targets', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 1,
            'data' => [
                'updated' => [],
                'skipped' => [],
                'failed' => [['tool' => 'composer', 'node' => 'app-1']],
                'footer' => 'Tool update failed',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe([
                'event' => 'complete',
                'error' => [
                    'code' => 'operation_failed',
                    'message' => 'Tool update failed',
                    'meta' => ['exit_code' => 1],
                    'data' => [
                        'updated' => [],
                        'skipped' => [],
                        'failed' => [['tool' => 'composer', 'node' => 'app-1']],
                        'footer' => 'Tool update failed',
                    ],
                ],
            ]);
    });

    it('streams tool:reconfigure payloads to the gateway', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'tool' => ['name' => 'opencode-cli', 'node' => 'app-1', 'action' => 'reconfigured'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'tool:reconfigure', [
            'tool' => 'opencode-cli',
            '--instance' => 'docs',
            '--password' => 'newpass',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/tools/opencode-cli/reconfigure'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'instance' => 'docs',
                    'password' => 'newpass',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['event'])
            ->toBe('complete')
            ->and($decoded['data'])
            ->toBe($complete);
    });

    it('renders gateway-authored tool progress in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Starting Tool',
                'steps' => [
                    ['key' => 'service', 'label' => 'Start service unit'],
                ],
            ]).gatewayProgressFrame('step', ['key' => 'service', 'status' => 'running', 'message' => 'Starting caddy'])
                .gatewayProgressFrame('complete', [
                    'exit_code' => 0,
                    'data' => ['footer' => "Tool 'caddy' started."],
                ]),
        );

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'caddy',
            '--node' => 'app-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Starting Tool')
            ->and($output)
            ->toContain('Start service unit')
            ->and($output)
            ->toContain('Starting caddy')
            ->and($output)
            ->toContain("Tool 'caddy' started.");
    });

    it('emits only the final tool error frame in json mode', function (): void {
        $error = [
            'exit_code' => 1,
            'message' => 'Tool action failed.',
            'data' => [
                'code' => 'tool.action_failed',
                'message' => 'Tool action failed.',
                'meta' => ['tool' => 'opencode-cli', 'action' => 'restart'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('step', [
                'key' => 'restart',
                'status' => 'running',
                'message' => 'Restarting opencode-server',
            ])
                .gatewayProgressFrame('error', $error),
        );

        [$exitCode, $output] = runCommand($this, 'tool:update', [
            'tool' => 'opencode-cli',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe([
                'event' => 'error',
                'data' => $error,
            ])
            ->and(count(array_filter(explode("\n", $output))))
            ->toBe(1)
            ->and($output)
            ->not->toContain('Restarting opencode-server');
    });

    it('preserves gateway error envelopes before a stream starts', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope(
            'tool.unsupported_action',
            "Tool 'docker' does not support install.",
            [
                'tool' => 'docker',
                'action' => 'install',
            ],
        ), JSON_THROW_ON_ERROR), 422);

        [$exitCode, $output] = runCommand($this, 'tool:install', [
            'tool' => 'docker',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('tool.unsupported_action')
            ->and($decoded['error']['message'])
            ->toBe("Tool 'docker' does not support install.")
            ->and($decoded['error']['meta']['tool'])
            ->toBe('docker');
    });
});
