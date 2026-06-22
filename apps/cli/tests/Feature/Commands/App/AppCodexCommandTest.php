<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app:codex', function (): void {
    it('adds an app project through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'codex_project' => [
                'app' => 'docs',
                'node' => 'mini',
                'label' => 'docs',
                'remote_path' => '/home/orbit/apps/docs',
                'ssh_alias' => 'app-node',
                'added' => true,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:codex', [
            'action' => 'add',
            'app' => 'docs',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/codex'
            && $request->data() === ['node' => 'mini']);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['codex_project']['ssh_alias'])->toBe('app-node');
    });

    it('removes an app project through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'codex_project' => [
                'app' => 'docs',
                'node' => 'mini',
                'removed' => true,
            ],
        ]));

        [$exitCode] = runCommand($this, 'app:codex', [
            'action' => 'remove',
            'app' => 'docs',
            '--node' => 'mini',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/apps/docs/codex'
            && $request->data() === ['node' => 'mini']);

        expect($exitCode)->toBe(0);
    });

    it('lists target-node Codex App projects through the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'codex_projects' => [
                [
                    'app' => 'docs',
                    'label' => 'docs',
                    'remote_path' => '/home/orbit/apps/docs',
                    'ssh_alias' => 'app-node',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:codex', [
            'action' => 'list',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/apps/codex/projects?node=mini');

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['codex_projects'][0]['app'])->toBe('docs');
    });

    it('rejects app selectors when listing target-node Codex App projects', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:codex', [
            'action' => 'list',
            'app' => 'docs',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('app');
    });

    it('fails before gateway IO when required non-interactive input is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:codex', [
            'action' => 'add',
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node');
    });
});
