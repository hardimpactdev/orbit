<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayStreamClient;
use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

describe('app:new interactive input mode', function (): void {
    beforeEach(function (): void {
        $this->tempPath = tempnam(sys_get_temp_dir(), 'orbit-app-new-interactive-').'.json';
        $this->store = new OrbitConfigStore(overridePath: $this->tempPath);
        @unlink($this->tempPath);

        app()->instance(OrbitConfigStore::class, $this->store);
    });

    afterEach(function (): void {
        if (isset($this->tempPath) && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    });

    it('resolves the complete zero-argument template flow before posting to the gateway', function (): void {
        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('complete', appNewInteractiveCompleteFrame('docs', 'app-1')),
        );

        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeAppNewNodeListEnvelope(['app-1', 'app-2']),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        Prompt::fake([Key::ENTER]);

        $this
            ->artisan('app:new')
            ->expectsQuestion('App name (slug):', 'docs')
            ->expectsChoice(
                'How should the app source be created?',
                'new',
                ['New repository from template', 'Clone existing repository', 'new', 'clone'],
            )
            ->expectsQuestion('Template repository (GitHub owner/repo):', 'hardimpact/laravel-template')
            ->expectsQuestion('New private repository (GitHub owner/repo):', 'hardimpact/docs')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/nodes'),
        );

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/apps'
                && $request->data() === [
                    'name' => 'docs',
                    'node' => 'app-1',
                    'repository' => null,
                    'template_repository' => 'hardimpact/laravel-template',
                    'new_repository' => 'hardimpact/docs',
                    'root' => 'public',
                    'php_version' => '8.5',
                    'domain' => null,
                    'runtime_proxy_transport' => 'http',
                ]
            ),
        );
    });

    it('resolves a new private repository from a template before posting to the gateway', function (): void {
        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('complete', appNewInteractiveCompleteFrame('docs', 'app-1')),
        );

        $this
            ->artisan('app:new', [
                'name' => 'docs',
                '--node' => 'app-1',
            ])
            ->expectsChoice(
                'How should the app source be created?',
                'new',
                ['New repository from template', 'Clone existing repository', 'new', 'clone'],
            )
            ->expectsQuestion('Template repository (GitHub owner/repo):', 'hardimpact/laravel-template')
            ->expectsQuestion('New private repository (GitHub owner/repo):', 'hardimpact/docs')
            ->assertSuccessful();

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data() === [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => null,
                'template_repository' => 'hardimpact/laravel-template',
                'new_repository' => 'hardimpact/docs',
                'root' => 'public',
                'php_version' => '8.5',
                'domain' => null,
                'runtime_proxy_transport' => 'http',
            ],
        );
    });

    it('resolves an existing repository clone before posting to the gateway', function (): void {
        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('complete', appNewInteractiveCompleteFrame('docs', 'app-1')),
        );

        $this
            ->artisan('app:new', [
                'name' => 'docs',
                '--node' => 'app-1',
            ])
            ->expectsChoice(
                'How should the app source be created?',
                'clone',
                ['New repository from template', 'Clone existing repository', 'new', 'clone'],
            )
            ->expectsQuestion('Repository URL (or GitHub owner/repo):', 'hardimpact/docs')
            ->assertSuccessful();

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data() === [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'hardimpact/docs',
                'template_repository' => null,
                'new_repository' => null,
                'root' => 'public',
                'php_version' => '8.5',
                'domain' => null,
                'runtime_proxy_transport' => 'http',
            ],
        );
    });

    it('validates an explicit template before prompting for its missing destination', function (): void {
        Http::fake();

        $this
            ->artisan('app:new', [
                'name' => 'docs',
                '--node' => 'app-1',
                '--template-repo' => 'not-a-template',
            ])
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('fails slug validation for an explicit app name before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:new', [
            'name' => 'Bad_Name',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('name');
    });

    it('rejects an invalid app name slug at the interactive prompt before contacting the gateway', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeAppNewNodeListEnvelope(['app-1']),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        Prompt::fake([Key::ENTER]);

        $this
            ->artisan('app:new')
            ->expectsQuestion('App name (slug):', 'Bad_Name')
            ->assertFailed();

        Http::assertSentCount(1);
    });

    it('preselects node default as the first datatable row when it is not returned first from the gateway', function (): void {
        $this->store->setDefaultNode('app-2');

        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('complete', appNewInteractiveCompleteFrame('docs', 'app-2')),
        );

        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeAppNewNodeListEnvelope(['app-1', 'app-2']),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        Prompt::fake([Key::ENTER]);

        $this
            ->artisan('app:new', ['--repo' => 'hardimpact/docs'])
            ->expectsQuestion('App name (slug):', 'docs')
            ->assertSuccessful();

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data()['node'] === 'app-2',
        );
    });

    it('still prompts for node when a local node default is configured', function (): void {
        $this->store->setDefaultNode('app-1');

        fakeGatewayProgressStreamClient(
            gatewayProgressFrame('complete', appNewInteractiveCompleteFrame('docs', 'app-2')),
        );

        Http::fake([
            'https://gateway.test/api/nodes*' => Http::response(
                fakeAppNewNodeListEnvelope(['app-1', 'app-2']),
                200,
            ),
        ]);

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayStreamClient::class);

        Prompt::fake([Key::DOWN, Key::ENTER]);

        $this
            ->artisan('app:new', ['--repo' => 'hardimpact/docs'])
            ->expectsQuestion('App name (slug):', 'docs')
            ->assertSuccessful();

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => $request->data()['node'] === 'app-2',
        );
    });

    it('fails fast in json mode when name is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:new', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('name')
            ->and($decoded['error']['message'])
            ->toBe('App name is required.');
    });
});

/**
 * @param  list<string>  $nodeNames
 * @return array<string, mixed>
 */
function fakeAppNewNodeListEnvelope(array $nodeNames): array
{
    $nodes = array_map(
        fn (string $name): array => [
            'name' => $name,
            'status' => 'active',
            'roles' => [['role' => 'app-dev', 'status' => 'active']],
            'addresses' => ['wireguard' => '10.6.0.10'],
        ],
        $nodeNames,
    );

    return fakeSuccessEnvelope(['nodes' => $nodes]);
}

/**
 * @return array<string, mixed>
 */
function appNewInteractiveCompleteFrame(string $name, string $node): array
{
    return [
        'exit_code' => 0,
        'data' => [
            'footer' => "App '{$name}' created.",
            'app' => ['name' => $name, 'node' => $node],
        ],
    ];
}
