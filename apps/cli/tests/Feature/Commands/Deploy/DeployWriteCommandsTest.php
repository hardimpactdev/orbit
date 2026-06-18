<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('deploy write commands', function (): void {
    it('posts deploy:step-add payloads to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'app' => 'docs',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force',
                'order' => 20,
                'timeout_seconds' => 120,
                'retention' => 5,
            ],
        ], ['action' => 'created']));

        [$exitCode, $output] = runCommand($this, 'deploy:step-add', [
            'app' => 'docs',
            'deploy_command' => 'php artisan migrate --force',
            '--title' => 'Run migrations',
            '--order' => '20',
            '--timeout' => '120',
            '--retention' => '5',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/deploy/steps')
                && $request->data() === [
                    'app' => 'docs',
                    'command' => 'php artisan migrate --force',
                    'title' => 'Run migrations',
                    'order' => 20,
                    'timeout' => 120,
                    'retention' => 5,
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['step']['id'])->toBe(12)
            ->and($decoded['success']['meta']['action'])->toBe('created');
    });

    it('rejects deploy:step-add missing input before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'deploy:step-add', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('command');
    });

    it('rejects invalid deploy:step-add numeric options before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'deploy:step-add', [
            'app' => 'docs',
            'deploy_command' => 'php artisan migrate --force',
            '--timeout' => '0',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('timeout');
    });

    it('requires force for deploy:step-remove before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'deploy:step-remove', [
            'app' => 'docs',
            'step' => 'Run migrations',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('destructive_consent_required')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes deployment steps when force is supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'app' => 'docs',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force',
                'order' => 20,
            ],
        ], [
            'action' => 'removed',
            'history_preserved' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'deploy:step-remove', [
            'app' => 'docs',
            'step' => 'Run migrations',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/deploy/steps/Run%20migrations')
                && $request->data() === [
                    'app' => 'docs',
                    'destructive_consent' => true,
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['step']['id'])->toBe(12)
            ->and($decoded['success']['meta']['history_preserved'])->toBeTrue();
    });

    it('posts deploy:run payloads to the gateway', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'run' => [
                    'id' => 43,
                    'app' => 'docs',
                    'status' => 'running',
                ],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            'app' => 'docs',
            '--detach' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/deploy/run')
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'app' => 'docs',
                    'detach' => true,
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded)->toBe([
                'event' => 'complete',
                'data' => $complete,
            ]);
    });

    it('rejects deploy:run without an app before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('passes through gateway errors from deploy writes', function (): void {
        fakeGatewayProgressStream(json_encode(fakeErrorEnvelope('authorization_failed', 'Missing deploy permission.', [
            'missing_permission' => 'deploy:run',
        ]), JSON_THROW_ON_ERROR), 403);

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('deploy:run');
    });

    it('renders a concise human summary for deploy:step-add without dumping the envelope', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'app' => 'docs',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force',
                'order' => 20,
                'timeout_seconds' => 120,
                'retention' => 5,
            ],
        ], ['action' => 'created']));

        [$exitCode, $output] = runCommand($this, 'deploy:step-add', [
            'app' => 'docs',
            'deploy_command' => 'php artisan migrate --force',
            '--title' => 'Run migrations',
            '--order' => '20',
            '--retention' => '5',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Added deployment step #12 'Run migrations' to app 'docs'.")
            ->and($output)->toContain('php artisan migrate --force')
            ->and($output)->toContain('order 20')
            ->and($output)->toContain('retention 5')
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('step:')
            ->and($output)->not->toContain('timeout_seconds:');
    });

    it('renders retention as unlimited for deploy:step-add when no retention is set', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 13,
                'app' => 'docs',
                'title' => 'Clear cache',
                'command' => 'php artisan cache:clear',
                'order' => 30,
                'timeout_seconds' => 600,
                'retention' => null,
            ],
        ], ['action' => 'created']));

        [$exitCode, $output] = runCommand($this, 'deploy:step-add', [
            'app' => 'docs',
            'deploy_command' => 'php artisan cache:clear',
            '--title' => 'Clear cache',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Added deployment step #13 'Clear cache' to app 'docs'.")
            ->and($output)->toContain('retention unlimited')
            ->and($output)->not->toContain('{');
    });

    it('renders a concise human summary for deploy:step-remove without dumping the envelope', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'app' => 'docs',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force',
                'order' => 20,
            ],
        ], [
            'action' => 'removed',
            'history_preserved' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'deploy:step-remove', [
            'app' => 'docs',
            'step' => 'Run migrations',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Removed deployment step #12 'Run migrations' from app 'docs'.")
            ->and($output)->toContain('order 20')
            ->and($output)->toContain('history preserved')
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('step:')
            ->and($output)->not->toContain('history_preserved:');
    });
});
