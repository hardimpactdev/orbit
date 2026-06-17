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
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/processes')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'workspace=feature-docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['processes'][0]['name'])->toBe('vite');
    });

    it('renders human output as a table with uppercase headers and derived status', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
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

        [$exitCode, $output] = runCommand($this, 'process:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Processes for docs')
            ->and($output)->toContain('NAME')
            ->and($output)->toContain('COMMAND')
            ->and($output)->toContain('RESTART')
            ->and($output)->toContain('TOOL')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('vite')
            ->and($output)->toContain('npm run dev')
            ->and($output)->toContain('never')
            ->and($output)->toContain('agent_ide')
            ->and($output)->toContain('running')
            ->and($output)->toContain('queue')
            ->and($output)->toContain('stopped')
            ->and($output)->not->toContain('processes: [')
            ->and($output)->not->toContain('"last_event"');
    });

    it('renders the missing-tool cell as an em dash', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [
                ['name' => 'worker', 'command' => 'php worker', 'tool' => null, 'last_event' => null],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('—');
    });

    it('renders the documented empty state when no processes exist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'context' => ['app' => 'docs', 'workspace' => null],
            'processes' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'process:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No processes found.');
    });

    it('passes through gateway error envelopes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'An app context is required.', [
            'field' => 'app',
        ]), 422);

        [$exitCode, $output] = runCommand($this, 'process:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'process:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
