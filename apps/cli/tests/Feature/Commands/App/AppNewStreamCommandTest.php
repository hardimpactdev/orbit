<?php

declare(strict_types=1);

describe('AppNewStream command', function (): void {
    it('renders gateway-authored project:new progress in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Creating App',
                'steps' => [
                    ['key' => 'operation', 'label' => 'Prepare app creation'],
                    ['key' => 'source', 'label' => 'Create app source'],
                    ['key' => 'registry', 'label' => 'Register app'],
                    ['key' => 'runtime', 'label' => 'Apply app runtime'],
                ],
            ])
                .gatewayProgressFrame('step', [
                    'key' => 'source',
                    'status' => 'running',
                    'message' => 'Creating source for docs',
                ])
                .gatewayProgressFrame('complete', [
                    'exit_code' => 0,
                    'data' => ['footer' => "App 'docs' created."],
                ]),
        );

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--repo' => 'hardimpact/docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Creating App')
            ->toContain('Prepare app creation')
            ->toContain('Create app source')
            ->toContain('Register app')
            ->toContain('Apply app runtime')
            ->toContain('Creating source for docs')
            ->toContain("App 'docs' created.");
    });

    it('emits only the final AppNewStream complete frame in json mode', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => "App 'docs' created.",
                'app' => ['name' => 'docs', 'node' => 'app-1'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Creating App'])
                .gatewayProgressFrame('step', [
                    'key' => 'source',
                    'status' => 'running',
                    'message' => 'Creating source',
                ])
                .gatewayProgressFrame('complete', $complete),
        );

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--repo' => 'hardimpact/docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/projects'
            && $request->hasHeader('Accept', 'text/event-stream'),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'event' => 'complete',
                'data' => $complete,
            ])
            ->and($output)
            ->not->toContain('Creating source');
    });

    it('preserves AppNewStream gateway errors before a stream starts', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope('authorization_failed', 'Missing app permission.', [
            'missing_permission' => 'project:new',
        ]), JSON_THROW_ON_ERROR), 403);

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--repo' => 'hardimpact/docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])
            ->toBe('project:new');
    });
});
