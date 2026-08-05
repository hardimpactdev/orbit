<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('process:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [
                [
                    'name' => 'vite',
                    'command' => 'npm run dev',
                    'restart_policy' => 'never',
                    'runtime_unit' => 'orbit_docs_main_vite',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--instance' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/processes')
                && str_contains($url, 'instance=docs')
                && str_contains($url, 'workspace=feature-docs')
            );
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['processes'][0]['name'])->toBe('vite');
    });

    it('forwards an app-instance selector and preserves concrete context in JSON', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => [
                'app' => 'docs',
                'instance' => 'production',
                'workspace' => null,
            ],
            'processes' => [
                [
                    'name' => 'vite',
                    'app' => 'docs',
                    'instance' => 'production',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--instance' => 'docs.production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => str_contains(
            urldecode($request->url()),
            'instance=docs.production',
        ));

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['context']['instance'])
            ->toBe('production')
            ->and($decoded['success']['data']['processes'][0]['instance'])
            ->toBe('production');
    });

    it('forwards the app hostname selector and rejects combining it with instance', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => [
                'app' => 'docs',
                'instance' => 'development',
                'workspace' => null,
            ],
            'processes' => [
                [
                    'name' => 'vite',
                    'status' => 'running',
                    'last_event' => ['id' => 1, 'type' => 'started'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--app' => 'test.app.example',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => str_contains(
            urldecode($request->url()),
            'app=test.app.example',
        ));

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['processes'][0]['status'])
            ->toBe('running');

        Http::fake();

        [$rejectCode, $rejectOutput] = runCommand($this, 'process:list', [
            '--app' => 'test.app.example',
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $rejected = json_decode($rejectOutput, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($rejectCode)
            ->toBe(1)
            ->and($rejected['error']['code'])
            ->toBe('validation_failed')
            ->and($rejected['error']['meta']['field'])
            ->toBe('context');
    });

    it('renders human output as a table with uppercase headers and derived status', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'instance' => 'production', 'workspace' => null],
            'processes' => [
                [
                    'name' => 'vite',
                    'command' => 'npm run dev',
                    'restart_policy' => 'never',
                    'tool' => 'agent_ide',
                    'last_event' => ['id' => 7, 'type' => 'started'],
                ],
                [
                    'name' => 'queue',
                    'command' => 'php artisan queue:work',
                    'restart_policy' => 'always',
                    'tool' => null,
                    'last_event' => ['id' => 9, 'type' => 'stopped'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--instance' => 'docs']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Processes for docs.production')
            ->and($output)
            ->toContain('KEY')
            ->and($output)
            ->toContain('LABEL')
            ->and($output)
            ->toContain('COMMAND')
            ->and($output)
            ->toContain('RESTART')
            ->and($output)
            ->toContain('TOOL')
            ->and($output)
            ->toContain('STATUS')
            ->and($output)
            ->toContain('vite')
            ->and($output)
            ->toContain('npm run dev')
            ->and($output)
            ->toContain('never')
            ->and($output)
            ->toContain('agent_ide')
            ->and($output)
            ->toContain('running')
            ->and($output)
            ->toContain('queue')
            ->and($output)
            ->toContain('stopped')
            ->and($output)
            ->not->toContain('processes: [')->and($output)
            ->not->toContain('"last_event"');
    });

    it('renders each PostgreSQL service version and published endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['node' => 'database1', 'app' => null, 'workspace' => null],
            'processes' => [
                [
                    'name' => 'postgres',
                    'command' => 'postgres',
                    'restart_policy' => 'always',
                    'tool' => null,
                    'service' => [
                        'service' => 'postgres',
                        'version_family' => '16',
                        'version' => '16-alpine',
                        'endpoint' => ['host' => '10.6.0.4', 'port' => 5432],
                    ],
                    'last_event' => ['id' => 1, 'type' => 'started'],
                ],
                [
                    'name' => 'postgres-food',
                    'command' => 'postgres',
                    'restart_policy' => 'always',
                    'tool' => null,
                    'service' => [
                        'service' => 'postgres',
                        'version_family' => '18',
                        'version' => '18-alpine',
                        'endpoint' => ['host' => '10.6.0.4', 'port' => 5433],
                    ],
                    'last_event' => ['id' => 2, 'type' => 'started'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--node' => 'database1']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('SERVICE')
            ->toContain('VERSION')
            ->toContain('ENDPOINT')
            ->toContain('postgres-food')
            ->toContain('16-alpine')
            ->toContain('18-alpine')
            ->toContain('10.6.0.4:5432')
            ->toContain('10.6.0.4:5433');
    });

    it('renders the missing-tool cell as an em dash', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [
                ['name' => 'worker', 'command' => 'php worker', 'tool' => null, 'last_event' => null],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--instance' => 'docs']);

        expect($exitCode)->toBe(0)->and($output)->toContain('—');
    });

    it('renders the documented empty state when no processes exist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--instance' => 'docs']);

        expect($exitCode)->toBe(0)->and($output)->toBe('No processes found.');
    });

    it('passes through gateway error envelopes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'An instance context is required.', [
            'field' => 'instance',
            'reason' => 'instance_required',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 'process:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('instance')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('instance_required');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
