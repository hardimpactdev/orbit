<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app setup commands', function (): void {
    it('streams instance:setup through the app setup endpoint', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'footer' => 'App ready and available at: https://docs.test',
                'result' => [
                    'instance' => 'docs',
                    'node' => 'app-1',
                    'setup_steps' => ['status' => 'completed', 'count' => 1],
                ],
            ],
        ];

        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Setting Up App'])
                .gatewayProgressFrame('step', ['key' => 'setup', 'status' => 'running', 'message' => 'Running setup'])
                .gatewayProgressFrame('complete', $complete),
        );

        [$exitCode, $output] = runCommand($this, 'instance:setup', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/instances/docs/setup'
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
            ->not->toContain('Running setup');
    });

    it('posts instance-setup-step:add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'added'],
            'step' => [
                'id' => 10,
                'instance' => 'docs',
                'order' => 1,
                'command' => 'composer install',
                'timeout_seconds' => 900,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance-setup-step:add', [
            'instance' => 'docs',
            '--command' => 'composer install',
            '--timeout' => '900',
            '--before' => '12',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/docs/setup-steps'
                && $request->data() === [
                    'command' => 'composer install',
                    'timeout' => 900,
                    'before' => 12,
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['step']['command'])->toBe('composer install');
    });

    it('lists app setup steps in json mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 10,
                    'instance' => 'docs',
                    'order' => 1,
                    'command' => 'composer install',
                    'timeout_seconds' => 600,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance-setup-step:list', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/instances/docs/setup-steps'
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['steps'][0]['command'])->toBe('composer install');
    });

    it('removes app setup steps with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'removed'],
            'step' => [
                'id' => 10,
                'instance' => 'docs',
                'order' => 1,
                'command' => 'composer install',
                'timeout_seconds' => 600,
            ],
        ], [
            'remaining_step_count' => 0,
            'new_step_count' => 0,
        ]));

        [$exitCode] = runCommand($this, 'instance-setup-step:remove', [
            'instance' => 'docs',
            '--step' => '10',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/instances/docs/setup-steps/10'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('validates app setup command inputs before gateway IO', function (
        string $command,
        array $params,
        string $field,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe($field);
    })->with([
        'setup missing instance' => ['instance:setup', [], 'instance'],
        'add missing instance' => ['instance-setup-step:add', ['--command' => 'composer install'], 'instance'],
        'add missing command' => ['instance-setup-step:add', ['instance' => 'docs'], 'command'],
        'add bad timeout' => [
            'instance-setup-step:add',
            ['instance' => 'docs', '--command' => 'composer install', '--timeout' => '0'],
            'timeout',
        ],
        'remove missing instance' => ['instance-setup-step:remove', ['--step' => '1', '--force' => true], 'instance'],
        'remove missing step' => ['instance-setup-step:remove', ['instance' => 'docs', '--force' => true], 'step'],
    ]);
});
