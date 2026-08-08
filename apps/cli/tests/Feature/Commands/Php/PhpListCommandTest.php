<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('php:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => [
                'node' => 'app-1',
                'supported' => ['8.5'],
                'available_images' => ['8.5'],
                'cli' => '8.5',
            ],
        ], ['live' => true]));

        [$exitCode, $output] = runCommand($this, 'php:list', [
            '--instance' => 'docs',
            '--workspace' => 'feature-docs',
            '--node' => 'app-1',
            '--live' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/php/runtime')
                && str_contains($url, 'instance=docs')
                && str_contains($url, 'workspace=feature-docs')
                && str_contains($url, 'node=app-1')
                && str_contains($url, 'live=1')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['php']['node'])
            ->toBe('app-1')
            ->and($decoded['success']['meta']['live'])
            ->toBeTrue();
    });

    it('uses cwd-inferred app when no explicit app is provided', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $markerDir = sys_get_temp_dir().'/orbit-php-list-'.uniqid('', true);
        mkdir($markerDir.'/.orbit', 0777, true);
        file_put_contents($markerDir.'/.orbit/config', json_encode(['instance' => 'docs'], JSON_THROW_ON_ERROR));
        putenv("ORBIT_HOST_CWD={$markerDir}");

        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'app-1', 'cli' => '8.5'],
        ]));

        try {
            [$exitCode] = runCommand($this, 'php:list', ['--json' => true]);

            Http::assertSent(function (Request $request): bool {
                $url = urldecode($request->url());

                return str_contains($url, 'instance=docs');
            });

            expect($exitCode)->toBe(0);
        } finally {
            restoreHostCwd($previousHostCwd);
            @unlink($markerDir.'/.orbit/config');
            @rmdir($markerDir.'/.orbit');
            @rmdir($markerDir);
        }
    });

    it('uses the local default node when no app, workspace, or node is supplied', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-php-list-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'default-app', 'cli' => '8.5'],
        ]));

        [$exitCode] = runCommand($this, 'php:list', ['--json' => true]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'node=default-app') && ! str_contains($url, 'instance=');
        });

        expect($exitCode)->toBe(0);

        @unlink($store->path());
    });

    it('renders human output as a compact runtime table with uppercase headers', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => [
                'node' => 'app-1',
                'supported' => ['8.3', '8.4', '8.5'],
                'available_images' => ['8.4', '8.5'],
                'cli' => '8.5',
                'app' => ['name' => 'docs', 'php_version' => '8.4'],
                'instance' => ['name' => 'development', 'app' => 'docs'],
                'workspace' => ['name' => 'feature-docs', 'php_version' => '8.4', 'inherits' => true],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'php:list', ['--node' => 'app-1']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('ATTRIBUTE')
            ->and($output)
            ->toContain('VALUE')
            ->and($output)
            ->toContain('NODE')
            ->and($output)
            ->toContain('SUPPORTED')
            ->and($output)
            ->toContain('AVAILABLE IMAGES')
            ->and($output)
            ->toContain('CLI')
            ->and($output)
            ->toContain('INSTANCE')
            ->and($output)
            ->toContain('WORKSPACE')
            ->and($output)
            ->toContain('app-1')
            ->and($output)
            ->toContain('8.3, 8.4, 8.5')
            ->and($output)
            ->toContain('8.4, 8.5')
            ->and($output)
            ->toContain('docs')
            ->and($output)
            ->toContain('feature-docs')
            ->and($output)
            ->toContain('inherited')
            ->and($output)
            ->not->toContain('php: {');
    });

    it('renders the instance own version beside the app template when they diverge', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => [
                'node' => 'app-1',
                'supported' => ['8.3', '8.4', '8.5'],
                'available_images' => ['8.3', '8.5'],
                'cli' => '8.5',
                'app' => ['name' => 'docs', 'php_version' => '8.5'],
                'instance' => ['name' => 'development', 'app' => 'docs', 'php_version' => '8.3'],
                'workspace' => null,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'php:list', ['--node' => 'app-1']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('docs.development')
            ->and($output)
            ->toContain('PHP 8.3')
            ->and($output)
            ->toContain('app template 8.5');
    });

    it('renders em dash for absent runtime context cells in human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => [
                'node' => 'app-1',
                'supported' => ['8.5'],
                'available_images' => [],
                'cli' => null,
                'app' => null,
                'instance' => null,
                'workspace' => null,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'php:list', ['--node' => 'app-1']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('NODE')
            ->and($output)
            ->toContain('app-1')
            ->and($output)
            ->toContain('—')
            ->and($output)
            ->not->toContain('php: {');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(
            fakeErrorEnvelope('authorization_failed', 'This node is not authorized to inspect PHP runtime.'),
            403,
        );

        [$exitCode, $output] = runCommand($this, 'php:list', ['--node' => 'app-1', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('authorization_failed');
    });

    it('surfaces config store failures when resolving the default node', function (): void {
        $path = base_path('tests/.tmp-php-list-invalid-config.json');
        file_put_contents($path, '{invalid-json');

        $store = new OrbitConfigStore(overridePath: $path);
        app()->instance(OrbitConfigStore::class, $store);

        try {
            [$exitCode, $output] = runCommand($this, 'php:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('config_invalid_json');
        } finally {
            @unlink($path);
        }
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'php:list', ['--node' => 'app-1', '--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
