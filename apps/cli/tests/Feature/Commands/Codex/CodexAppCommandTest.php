<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('codex:app', function (): void {
    beforeEach(function (): void {
        $this->tempPath = orbit_test_config_path(prefix: 'orbit-codex-app-');
        unlink_orbit_test_file($this->tempPath);

        app()->instance(OrbitConfigStore::class, new OrbitConfigStore(overridePath: $this->tempPath));
    });

    afterEach(function (): void {
        unlink_orbit_test_file($this->tempPath);
    });

    it('returns extension disabled before gateway IO when locally disabled', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'codex:app', params: [
            'action' => 'list',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_disabled')
            ->and($decoded['error']['meta']['extension'])
            ->toBe('codex')
            ->and($decoded['error']['meta']['scope'])
            ->toBe('local');
    });

    it('adds an app project through the codex gateway API', function (): void {
        enable_local_codex_extension();

        fakeGateway(fakeSuccessEnvelope([
            'codex_project' => [
                'project' => 'docs',
                'node' => 'mini',
                'label' => 'docs',
                'remote_path' => '/home/orbit/apps/docs',
                'ssh_alias' => 'app-node',
                'added' => true,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'codex:app', params: [
            'action' => 'add',
            'project' => 'docs',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/codex/apps/docs'
                && $request->data() === ['node' => 'mini']
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['codex_project']['ssh_alias'])->toBe('app-node');
    });

    it('removes an app project through the codex gateway API', function (): void {
        enable_local_codex_extension();

        fakeGateway(fakeSuccessEnvelope([
            'codex_project' => [
                'project' => 'docs',
                'node' => 'mini',
                'removed' => true,
            ],
        ]));

        [$exitCode] = runCommand($this, command: 'codex:app', params: [
            'action' => 'remove',
            'project' => 'docs',
            '--node' => 'mini',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/codex/apps/docs'
                && $request->data() === ['node' => 'mini']
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('lists target-node Codex App projects through the codex gateway API', function (): void {
        enable_local_codex_extension();

        fakeGateway(fakeSuccessEnvelope([
            'codex_projects' => [
                [
                    'project' => 'docs',
                    'label' => 'docs',
                    'remote_path' => '/home/orbit/apps/docs',
                    'ssh_alias' => 'app-node',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'codex:app', params: [
            'action' => 'list',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/codex/projects?node=mini'
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['codex_projects'][0]['project'])->toBe('docs');
    });

    it('rejects app selectors when listing target-node Codex App projects', function (): void {
        enable_local_codex_extension();
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'codex:app', params: [
            'action' => 'list',
            'project' => 'docs',
            '--node' => 'mini',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('project');
    });

    it('fails before gateway IO when required non-interactive input is missing', function (): void {
        enable_local_codex_extension();
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'codex:app', params: [
            'action' => 'add',
            'project' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('node');
    });
});

function enable_local_codex_extension(): void
{
    app(OrbitConfigStore::class)->enableExtension('codex');
}
