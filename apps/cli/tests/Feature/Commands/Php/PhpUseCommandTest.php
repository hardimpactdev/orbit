<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function strip_php_use_ansi(string $value): string
{
    return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', replacement: '', subject: $value) ?? $value;
}

describe('php:use', function (): void {
    it('posts instance PHP runtime selections to the gateway and returns the canonical envelope', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => [
                'node' => 'app-1',
                'supported' => ['8.5', '8.4', '8.3'],
                'available_images' => ['8.5'],
                'cli' => '8.5',
                'app' => ['name' => 'docs', 'php_version' => '8.5'],
                'instance' => ['name' => 'development', 'app' => 'docs'],
                'workspace' => null,
            ],
            'result' => [
                'target' => 'instance',
                'node' => 'app-1',
                'app' => 'docs',
                'instance' => 'development',
                'workspace' => null,
                'previous' => '8.4',
                'version' => '8.5',
                'changed' => true,
            ],
        ], ['warnings' => []]));

        [$exitCode, $output] = runCommand($this, 'php:use', [
            'version' => '8.5',
            '--instance' => 'docs.development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return (
                $request->method() === 'POST'
                && str_contains($request->url(), '/api/php/use')
                && $request->data() === [
                    'version' => '8.5',
                    'instance' => 'docs.development',
                    'inherit' => false,
                    'cli' => false,
                ]
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['result']['target'])
            ->toBe('instance')
            ->and($decoded['success']['meta']['warnings'])
            ->toBe([]);
    });

    it('uses cwd-inferred app context when no explicit app is provided', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        $markerDir = sys_get_temp_dir().'/orbit-php-use-'.uniqid('', true);
        mkdir($markerDir.'/.orbit', 0777, true);
        file_put_contents($markerDir.'/.orbit/config', json_encode(['instance' => 'docs'], JSON_THROW_ON_ERROR));
        putenv("ORBIT_HOST_CWD={$markerDir}");

        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'app-1'],
            'result' => ['target' => 'instance', 'app' => 'docs', 'instance' => 'development', 'version' => '8.5'],
        ], ['warnings' => []]));

        try {
            [$exitCode] = runCommand($this, 'php:use', ['version' => '8.5', '--json' => true]);

            Http::assertSent(function (Request $request): bool {
                return $request->data()['instance'] === 'docs';
            });

            expect($exitCode)->toBe(0);
        } finally {
            restoreHostCwd($previousHostCwd);
            @unlink($markerDir.'/.orbit/config');
            @rmdir($markerDir.'/.orbit');
            @rmdir($markerDir);
        }
    });

    it('posts workspace inheritance without requiring a version', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'app-1'],
            'result' => [
                'target' => 'workspace',
                'app' => 'docs',
                'workspace' => 'feature-docs',
                'version' => '8.5',
                'inherits' => true,
            ],
        ], ['warnings' => []]));

        [$exitCode] = runCommand($this, 'php:use', [
            '--instance' => 'docs.development',
            '--workspace' => 'feature-docs',
            '--inherit' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return (
                $request->method() === 'POST'
                && str_contains($request->url(), '/api/php/use')
                && ! array_key_exists('version', $payload)
                && $payload['instance'] === 'docs.development'
                && $payload['workspace'] === 'feature-docs'
                && $payload['inherit'] === true
                && $payload['cli'] === false
            );
        });

        expect($exitCode)->toBe(0);
    });

    it('uses the local default node for CLI PHP selection when no node is supplied', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-php-use-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'default-app', 'cli' => '8.5'],
            'result' => ['target' => 'node_cli', 'node' => 'default-app', 'version' => '8.5'],
        ], ['warnings' => []]));

        try {
            [$exitCode] = runCommand($this, 'php:use', [
                'version' => '8.5',
                '--cli' => true,
                '--json' => true,
            ]);

            Http::assertSent(function (Request $request): bool {
                return $request->data() === [
                    'version' => '8.5',
                    'node' => 'default-app',
                    'inherit' => false,
                    'cli' => true,
                ];
            });

            expect($exitCode)->toBe(0);
        } finally {
            @unlink($store->path());
        }
    });

    it('prompts for version in interactive mode when version is missing', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'app-1'],
            'result' => ['target' => 'instance', 'app' => 'docs', 'instance' => 'development', 'version' => '8.5'],
        ], ['warnings' => []]));

        $this
            ->artisan('php:use', ['--instance' => 'docs.development'])
            ->expectsChoice('PHP version', '8.5', ['8.5', '8.4', '8.3'])
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->data()['version'] === '8.5' && $request->data()['instance'] === 'docs.development';
        });
    });

    it('returns validation_failed in JSON mode when version is missing before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'php:use', ['--instance' => 'docs.development', '--json' => true]);
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta'])
            ->toMatchArray([
                'field' => 'version',
                'reason' => 'missing',
            ]);
    });

    it('passes through gateway error codes', function (): void {
        fakeGateway(fakeErrorEnvelope('validation_failed', 'A workspace target is required.', [
            'field' => 'workspace',
            'reason' => 'missing_target',
        ]), 400);

        [$exitCode, $output] = runCommand($this, 'php:use', [
            'version' => '8.5',
            '--workspace' => 'missing-workspace',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('workspace');
    });

    it('renders php:use --cli human output as a progress tree with host PHP CLI labels', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'beast', 'cli' => '8.5'],
            'result' => [
                'target' => 'node_cli',
                'node' => 'beast',
                'previous' => '8.4',
                'version' => '8.5',
                'changed' => true,
            ],
        ], ['warnings' => []]));

        [$exitCode, $output] = runCommand($this, 'php:use', [
            'version' => '8.5',
            '--node' => 'beast',
            '--cli' => true,
        ]);

        $plain = strip_php_use_ansi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('Updating node CLI PHP on beast to PHP 8.5')
            ->and($plain)
            ->toMatch('/●\s+Resolved target beast\b/')
            ->and($plain)
            ->toContain('Validate host PHP CLI version')
            ->and($plain)
            ->toContain('Apply host PHP CLI default')
            ->and($plain)
            ->toContain('Successfully updated node CLI PHP on beast to PHP 8.5')
            ->and($plain)
            ->not->toMatch('/^php:/m')->and($plain)
            ->not->toMatch('/^result:/m');
    });

    it('renders php:use --cli gateway failures as a failed tree step and footer', function (): void {
        fakeGateway(
            fakeErrorEnvelope(
                code: 'authorization_failed',
                message: 'This node is not authorized to manage the PHP runtime target.',
            ),
            status: 403,
        );

        [$exitCode, $output] = runCommand($this, command: 'php:use', params: [
            'version' => '8.5',
            '--node' => 'app-dev-1',
            '--cli' => true,
        ]);

        $plain = strip_php_use_ansi($output);

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain('Updating node CLI PHP on app-dev-1 to PHP 8.5')
            ->and($plain)
            ->toMatch('/●\s+Resolved target app-dev-1\b/')
            ->and($plain)
            ->toContain('Validate host PHP CLI version')
            ->and($plain)
            ->toContain('not authorized to manage the PHP runtime target')
            ->and($plain)
            ->not->toContain('Validated host PHP CLI version')->and($plain)
            ->not->toContain('Applied host PHP CLI default')->and($plain)
            ->not->toContain('Successfully updated node CLI PHP on app-dev-1 to PHP 8.5')->and($plain)
            ->not->toMatch('/^php:/m')->and($plain)
            ->not->toMatch('/^result:/m')->and($plain)
            ->not->toContain('"error"');
    });

    it('does not emit a progress tree for php:use --cli in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'php' => ['node' => 'beast', 'cli' => '8.5'],
            'result' => [
                'target' => 'node_cli',
                'node' => 'beast',
                'version' => '8.5',
                'changed' => true,
            ],
        ], ['warnings' => []]));

        [$exitCode, $output] = runCommand($this, command: 'php:use', params: [
            'version' => '8.5',
            '--node' => 'beast',
            '--cli' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['result']['target'])
            ->toBe('node_cli')
            ->and($output)
            ->not->toContain('Updating node CLI PHP')->and($output)
            ->not->toContain('Resolve target');
    });

    it('surfaces config store failures when resolving the default node for CLI scope', function (): void {
        $path = base_path('tests/.tmp-php-use-invalid-config.json');
        file_put_contents($path, '{invalid-json');

        $store = new OrbitConfigStore(overridePath: $path);
        app()->instance(OrbitConfigStore::class, $store);

        try {
            [$exitCode, $output] = runCommand($this, command: 'php:use', params: [
                'version' => '8.5',
                '--cli' => true,
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('config_invalid_json');
        } finally {
            @unlink($path);
        }
    });
});
