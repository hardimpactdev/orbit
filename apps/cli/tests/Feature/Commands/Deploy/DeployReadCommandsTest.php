<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('deploy:history', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runs' => [
                [
                    'id' => 12,
                    'status' => 'succeeded',
                    'started_at' => '2026-05-27T10:00:00Z',
                    'duration_ms' => 5432,
                ],
            ],
        ], [
            'app' => 'docs',
            'count' => 1,
        ]));

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/deploy/history')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['runs'][0]['id'])->toBe(12)
            ->and($decoded['success']['meta'])->toMatchArray([
                'app' => 'docs',
                'count' => 1,
            ]);
    });

    it('forwards the limit option to the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['runs' => []], ['app' => 'docs', 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => 'docs',
            '--limit' => '10',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/deploy/history')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'limit=10');
        });

        expect($exitCode)->toBe(0);
    });

    it('renders human output as a table with a header line and newest-first rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runs' => [
                [
                    'id' => 44,
                    'status' => 'failed',
                    'exit_code' => 1,
                    'started_at' => '2026-05-07T10:20:00Z',
                ],
                [
                    'id' => 43,
                    'status' => 'completed',
                    'exit_code' => 0,
                    'started_at' => '2026-05-07T09:00:00Z',
                ],
            ],
        ], ['app' => 'docs', 'count' => 2]));

        [$exitCode, $output] = runCommand($this, 'deploy:history', ['app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Deployment History: docs')
            ->and($output)->toContain('ID')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('EXIT')
            ->and($output)->toContain('STARTED')
            ->and($output)->toContain('failed')
            ->and($output)->toContain('completed')
            ->and($output)->toContain('2026-05-07T10:20:00Z')
            ->and(mb_strpos($output, '44'))->toBeLessThan(mb_strpos($output, '43'))
            ->and($output)->not->toContain('runs: [')
            ->and($output)->not->toContain('"exit_code"');
    });

    it('renders empty human output naming the app when no deploy history exists', function (): void {
        fakeGateway(fakeSuccessEnvelope(['runs' => []], ['app' => 'docs', 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'deploy:history', ['app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No deployment history found for docs.');
    });

    it('rejects a missing app argument before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['runs' => []]));

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => ' ',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('passes through gateway validation failures for invalid limits', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'Invalid value for --limit: must be a positive integer.', [
            'field' => 'limit',
        ]), 400);

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => 'docs',
            '--limit' => '0',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('limit');
    });

    it('passes through authorization failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing deploy read permission.', [
            'missing_permission' => 'deploy:read',
        ]), 403);

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('deploy:read');
    });

    it('passes through production-app-required failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('deploy.production_app_required', 'A production app is required.', [
            'app' => 'docs',
        ]), 400);

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('deploy.production_app_required');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'deploy:history', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

describe('deploy:step-list', function (): void {
    it('returns a canonical success envelope in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 1,
                    'order' => 1,
                    'title' => 'Build',
                    'command' => 'composer install --no-dev',
                    'timeout' => 300,
                ],
            ],
        ], [
            'app' => 'docs',
            'count' => 1,
        ]));

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/deploy/steps')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['steps'][0]['title'])->toBe('Build')
            ->and($decoded['success']['meta'])->toMatchArray([
                'app' => 'docs',
                'count' => 1,
            ]);
    });

    it('renders human output as a table with uppercase headers and ordered step cells', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 1,
                    'order' => 1,
                    'title' => 'Build',
                    'command' => 'composer install --no-dev',
                    'timeout_seconds' => 600,
                    'retention' => null,
                ],
                [
                    'id' => 2,
                    'order' => 2,
                    'title' => 'Migrate',
                    'command' => 'php artisan migrate --force',
                    'timeout_seconds' => null,
                    'retention' => null,
                ],
            ],
        ], ['app' => 'docs', 'count' => 2]));

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', ['app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('ID')
            ->and($output)->toContain('ORDER')
            ->and($output)->toContain('TITLE')
            ->and($output)->toContain('COMMAND')
            ->and($output)->toContain('TIMEOUT')
            ->and($output)->toContain('Build')
            ->and($output)->toContain('composer install --no-dev')
            ->and($output)->toContain('600')
            ->and($output)->toContain('Migrate')
            ->and($output)->toContain('—')
            ->and(mb_strpos($output, 'Build'))->toBeLessThan(mb_strpos($output, 'Migrate'))
            ->and($output)->not->toContain('steps: [')
            ->and($output)->not->toContain('"timeout_seconds"');
    });

    it('renders empty human output naming the app when no deploy steps exist', function (): void {
        fakeGateway(fakeSuccessEnvelope(['steps' => []], ['app' => 'docs', 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', ['app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No deployment steps found for docs.');
    });

    it('rejects a missing app argument before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['steps' => []]));

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', [
            'app' => ' ',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('passes through authorization failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing deploy read permission.', [
            'missing_permission' => 'deploy:read',
        ]), 403);

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('deploy:read');
    });

    it('passes through app-not-found failures from the gateway', function (): void {
        fakeGateway(fakeErrorEnvelope('app.not_found', 'App not found.', [
            'app' => 'missing',
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', [
            'app' => 'missing',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('app.not_found');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'deploy:step-list', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
