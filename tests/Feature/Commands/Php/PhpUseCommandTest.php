<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Php\UsePhpRuntimeRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

describe('php:use command contract', function (): void {
    it('updates app PHP runtime intent on the gateway', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node, ['versions' => ['8.5', '8.4']]);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

        $exitCode = Artisan::call('php:use', [
            'version' => '8.5',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($app->refresh()->php_version)->toBe('8.5')
            ->and($payload['success']['data']['result'])->toMatchArray([
                'target' => 'app',
                'node' => 'app-1',
                'app' => 'docs',
                'workspace' => null,
                'previous' => '8.4',
                'version' => '8.5',
                'inherits' => false,
                'changed' => true,
            ])
            ->and($payload['success']['meta']['warnings'])->toBe([]);
    });

    it('renders the documented human progress tree', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node, ['versions' => ['8.5', '8.4']]);
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.4']);

        $this->artisan('php:use', [
            'version' => '8.5',
            '--app' => 'docs',
        ])
            ->expectsOutputToContain('┌  Updating PHP runtime to PHP 8.5')
            ->expectsOutputToContain('○  Resolve target')
            ->expectsOutputToContain('○  Validate version')
            ->expectsOutputToContain('○  Apply and verify PHP change')
            ->expectsOutputToContain('●  Resolved target')
            ->expectsOutputToContain('●  Validated version')
            ->expectsOutputToContain('●  Applied and verified PHP change')
            ->expectsOutputToContain('└  Successfully updated PHP runtime to PHP 8.5')
            ->expectsOutputToContain('Successfully updated app to PHP 8.5.')
            ->assertSuccessful();
    });

    it('updates workspace override and can restore workspace inheritance', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node, ['versions' => ['8.5', '8.4']]);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id, 'php_version' => null]);

        Artisan::call('php:use', [
            'version' => '8.4',
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        expect($workspace->refresh()->php_version)->toBe('8.4');

        $exitCode = Artisan::call('php:use', [
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--inherit' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($workspace->refresh()->php_version)->toBeNull()
            ->and($payload['success']['data']['result'])->toMatchArray([
                'target' => 'workspace',
                'workspace' => 'feature-docs',
                'previous' => '8.4',
                'version' => '8.5',
                'inherits' => true,
                'changed' => true,
            ]);
    });

    it('updates node CLI PHP default intent in the PHP tool facts', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $tool = createPhpTool($node, ['versions' => ['8.5', '8.4'], 'cli_version' => '8.4']);

        $exitCode = Artisan::call('php:use', [
            'version' => '8.5',
            '--node' => 'app-1',
            '--cli' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($tool->refresh()->config['cli_version'])->toBe('8.5')
            ->and($payload['success']['data']['result'])->toMatchArray([
                'target' => 'node_cli',
                'node' => 'app-1',
                'previous' => '8.4',
                'version' => '8.5',
                'changed' => true,
            ]);
    });

    it('rejects unsupported and missing installed versions before side effects', function (): void {
        createPhpLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        createPhpTool($node, ['versions' => ['8.5']]);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'php_version' => '8.5']);

        $exitCode = Artisan::call('php:use', [
            'version' => '8.4',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($app->refresh()->php_version)->toBe('8.5')
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toMatchArray([
                'field' => 'version',
                'reason' => 'not_installed',
            ]);
    });

    it('rejects mutually exclusive inputs in non-interactive JSON mode', function (): void {
        createPhpLocalNode('gateway');

        $exitCode = Artisan::call('php:use', [
            'version' => '8.5',
            '--inherit' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['reason'])->toBe('mutually_exclusive_input');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createPhpLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            UsePhpRuntimeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'php' => [
                            'node' => 'app-1',
                            'supported' => ['8.5', '8.4', '8.3'],
                            'installed' => ['8.5'],
                            'cli' => '8.5',
                            'app' => ['name' => 'docs', 'php_version' => '8.5'],
                            'workspace' => null,
                        ],
                        'result' => [
                            'target' => 'app',
                            'node' => 'app-1',
                            'app' => 'docs',
                            'workspace' => null,
                            'previous' => '8.4',
                            'version' => '8.5',
                            'inherits' => false,
                            'changed' => true,
                        ],
                    ],
                    'meta' => ['warnings' => []],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('php:use', [
            'version' => '8.5',
            '--app' => 'docs',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['result']['target'])->toBe('app');
    });
});
