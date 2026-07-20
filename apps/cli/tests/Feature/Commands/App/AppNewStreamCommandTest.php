<?php

declare(strict_types=1);

describe('AppNewStream command', function (): void {
    it('renders gateway-authored project:new progress in human mode', function (): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', [
                'title' => 'Creating Project',
                'steps' => [
                    ['key' => 'operation', 'label' => 'Prepare project creation'],
                    ['key' => 'source', 'label' => 'Create project source'],
                    ['key' => 'registry', 'label' => 'Register project'],
                    ['key' => 'runtime', 'label' => 'Apply instance runtime'],
                ],
            ])
                .gatewayProgressFrame('step', [
                    'key' => 'source',
                    'status' => 'running',
                    'message' => 'Creating source for docs',
                ])
                .gatewayProgressFrame('complete', [
                    'exit_code' => 0,
                    'data' => ['footer' => "Project 'docs' created."],
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
            ->toContain('Creating Project')
            ->toContain('Prepare project creation')
            ->toContain('Create project source')
            ->toContain('Register project')
            ->toContain('Apply instance runtime')
            ->toContain('Creating source for docs')
            ->toContain("Project 'docs' created.");
    });

    it('emits only the final project:new complete frame in json mode', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => "Project 'docs' created.",
                'project' => ['name' => 'docs', 'node' => 'app-1'],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Creating Project'])
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

    it('preserves project:new gateway errors before a stream starts', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope('authorization_failed', 'Missing project permission.', [
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
