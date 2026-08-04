<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

function fakeGatewaySequence(): ResponseSequence
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);

    $sequence = Http::sequence();
    Http::fake(['https://gateway.test/*' => $sequence]);

    return $sequence;
}

describe('project and instance write commands', function (): void {
    it('validates required project:new inputs before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
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

    it('posts project:new payloads to the gateway projects endpoint', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'result' => ['action' => 'created'],
                'project' => ['name' => 'docs', 'node' => 'app-1'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--repo' => 'spatie/docs',
            '--root' => 'public',
            '--php-version' => '8.5',
            '--domain' => 'docs.example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/projects'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'name' => 'docs',
                    'node' => 'app-1',
                    'repository' => 'spatie/docs',
                    'template_repository' => null,
                    'new_repository' => null,
                    'root' => 'public',
                    'php_version' => '8.5',
                    'domain' => 'docs.example.com',
                    'runtime_proxy_transport' => 'http',
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'event' => 'complete',
                'data' => $complete,
            ]);
    });

    it('posts template-based project:new payloads to the gateway projects endpoint', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'result' => ['action' => 'created'],
                'project' => ['name' => 'docs', 'node' => 'app-1'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--template-repo' => 'hardimpact/laravel-template',
            '--new-repo' => 'hardimpact/docs',
            '--json' => true,
        ]);

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

        expect($exitCode)->toBe(0);
    });

    it('rejects incomplete or conflicting project:new source input before gateway IO', function (array $source): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            ...$source,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('source')
            ->and($decoded['error']['meta']['fields'])
            ->toBe(['repo', 'template-repo', 'new-repo']);
    })->with([
        'missing source' => [[]],
        'template without destination' => [['--template-repo' => 'hardimpact/laravel-template']],
        'destination without template' => [['--new-repo' => 'hardimpact/docs']],
        'clone and template branches' => [[
            '--repo' => 'hardimpact/docs',
            '--template-repo' => 'hardimpact/laravel-template',
            '--new-repo' => 'hardimpact/new-docs',
        ]],
    ]);

    it('rejects credential-bearing project:new clone URLs before gateway IO', function (string $repository): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'project:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--repo' => $repository,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('repo');
    })->with([
        'token in HTTPS username' => ['https://secret-token@git.example.com/docs.git'],
        'HTTPS username and password' => ['https://user:secret@git.example.com/docs.git'],
        'SSH password' => ['ssh://git:secret@git.example.com/docs.git'],
        'token in query string' => ['https://git.example.com/docs.git?token=secret'],
    ]);

    it('posts instance:register payloads to the gateway register endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'adopted'],
            'project' => ['name' => 'docs', 'node' => 'app-1'],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
            '--root' => 'public',
            '--php-version' => '8.5',
            '--domain' => 'docs.example.com',
            '--runtime-proxy-transport' => 'https',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/register'
                && $request->data() === [
                    'name' => 'docs',
                    'node' => 'app-1',
                    'path' => '/home/orbit/apps/docs',
                    'root' => 'public',
                    'php_version' => '8.5',
                    'domain' => 'docs.example.com',
                    'runtime_proxy_transport' => 'https',
                ]
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['result']['action'])->toBe('adopted');
    });

    it('omits instance:register runtime proxy transport unless it is explicit', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'converged'],
            'project' => ['name' => 'docs', 'node' => 'app-1'],
        ]));

        [$exitCode] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/instances/register'
            && ! array_key_exists('runtime_proxy_transport', $request->data()),
        );

        expect($exitCode)->toBe(0);
    });

    it('validates required instance:register inputs before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:register', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('project');
    });

    it('requires force before removing a project non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'project:remove', [
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
            ->toBe('force');
    });

    it('deletes project:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'project:remove', [
            'project' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/projects/docs'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
                && ! $request->hasHeader('X-Orbit-Node-Transport-Preference')
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['result']['action'])->toBe('removed');
    });

    it('prompts before removing a project without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ]));

        $this
            ->artisan('project:remove', ['project' => 'docs'])
            ->expectsConfirmation("Remove project 'docs' and all owned artifacts? This cannot be undone.", 'yes')
            ->expectsOutputToContain("Project 'docs' removed")
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/projects/docs'
                && $request->data() === [
                    'destructive_consent' => true,
                    'destructive_consent_source' => 'force',
                ]
            ),
        );
    });

    it('posts instance:root payloads to the gateway instance root endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs', 'root' => 'public'],
            'result' => ['changed' => true],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:root', [
            'instance' => 'docs',
            'root' => 'public',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/docs/root'
                && $request->data() === ['root' => 'public']
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['result']['changed'])->toBeTrue();
    });

    it('validates required instance:root inputs before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:root', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('root');
    });

    it('forwards instance:worker actions to their gateway endpoints', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => true,
            'worker_config' => null,
            'changed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'enable',
            'instance' => 'docs.development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/docs.development/worker/enable'
            ),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['worker_enabled'])->toBeTrue();
    });

    it('renders human instance:worker show output for an enabled instance', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'show',
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance 'docs.development' worker mode is enabled.")
            ->and($output)
            ->toContain('  workers: auto')
            ->and($output)
            ->toContain('  max_requests: 500')
            ->and($output)
            ->not->toContain('{')->and($output)
            ->not->toContain('worker_config:')->and($output)
            ->not->toContain('worker_enabled');
    });

    it('renders human instance:worker show output for a disabled instance without config detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => false,
            'worker_config' => null,
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'show',
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe("Instance 'docs.development' worker mode is disabled.")
            ->and($output)
            ->not->toContain('workers:')->and($output)
            ->not->toContain('max_requests:');
    });

    it('renders human instance:worker enable output when state changed', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
            'changed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'enable',
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance 'docs.development' worker mode enabled.")
            ->and($output)
            ->toContain('  workers: auto')
            ->and($output)
            ->toContain('  max_requests: 500')
            ->and($output)
            ->not->toContain('{')->and($output)
            ->not->toContain('worker_config:');
    });

    it('renders human instance:worker enable output when already enabled', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
            'changed' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'enable',
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance 'docs.development' worker mode already enabled.")
            ->and($output)
            ->toContain('  workers: auto')
            ->and($output)
            ->toContain('  max_requests: 500')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders human instance:worker disable output retaining config detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => false,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
            'changed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'disable',
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance 'docs.development' worker mode disabled.")
            ->and($output)
            ->toContain('  workers: auto')
            ->and($output)
            ->toContain('  max_requests: 500')
            ->and($output)
            ->not->toContain('{')->and($output)
            ->not->toContain('worker_config:');
    });

    it('renders human instance:worker disable output when already disabled', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => 'docs',
            'instance' => 'development',
            'worker_enabled' => false,
            'worker_config' => null,
            'changed' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:worker', [
            'action' => 'disable',
            'instance' => 'docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe("Instance 'docs.development' worker mode already disabled.")
            ->and($output)
            ->not->toContain('workers:')->and($output)
            ->not->toContain('max_requests:');
    });

    it('validates required instance:mount inputs before gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:mount', [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe($field);
    })->with([
        'missing action' => [[], 'action'],
        'missing instance' => [['action' => 'add'], 'instance'],
        'missing source' => [['action' => 'add', 'instance' => 'docs.local'], 'source'],
        'missing target for add' => [
            ['action' => 'add', 'instance' => 'docs.local', 'source' => '/home/orbit/packages'],
            'target',
        ],
        'missing target for remove' => [['action' => 'remove', 'instance' => 'docs.local'], 'target'],
    ]);

    it('forwards instance:mount list to the gateway endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs'],
            'mounts' => [[
                'source' => '/home/orbit/packages',
                'target' => '/home/orbit/packages',
                'read_only' => true,
            ]],
            'inherited_by_workspaces' => true,
        ]));

        [$listExitCode] = runCommand($this, 'instance:mount', [
            'action' => 'list',
            'instance' => 'docs',
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && $request->url() === 'https://gateway.test/api/instances/docs/mounts'
            ),
        );

        expect($listExitCode)->toBe(0);
    });

    it('rejects instance:mount add and remove without a dotted instance selector before gateway IO', function (string $action): void {
        Http::fake();

        $arguments = [
            'action' => $action,
            'instance' => 'docs',
            '--json' => true,
        ];

        if ($action === 'add') {
            $arguments['source'] = '/home/orbit/packages';
            $arguments['target'] = '/home/orbit/packages';
        }

        $arguments['target'] ??= '/home/orbit/packages';

        [$exitCode, $output] = runCommand($this, 'instance:mount', $arguments);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('instance')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('dotted_instance_required');
    })->with(['add', 'remove']);

    it('forwards dotted instance selectors unchanged to the instance:mount gateway endpoints', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'hauser'],
            'target' => [
                'type' => 'instance',
                'project' => 'hauser',
                'instance' => 'nmbp',
            ],
            'mount' => [
                'source' => '/Users/nckrtl/projects',
                'target' => '/projects',
                'read_only' => true,
            ],
            'mounts' => [],
            'action' => 'created',
            'inherited_by_workspaces' => true,
        ]));

        [$exitCode] = runCommand($this, 'instance:mount', [
            'action' => 'add',
            'instance' => 'hauser.nmbp',
            'source' => '/Users/nckrtl/projects',
            'target' => '/projects',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/instances/hauser.nmbp/mounts'
                && $request->data() === [
                    'source' => '/Users/nckrtl/projects',
                    'target' => '/projects',
                    'read_only' => true,
                ]
            ),
        );

        expect($exitCode)->toBe(0);

        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'hauser'],
            'target' => [
                'type' => 'instance',
                'project' => 'hauser',
                'instance' => 'nmbp',
            ],
            'mounts' => [],
            'action' => 'removed',
            'inherited_by_workspaces' => true,
        ]));

        [$removeExitCode] = runCommand($this, command: 'instance:mount', params: [
            'action' => 'remove',
            'instance' => 'hauser.nmbp',
            'target' => '/projects',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && $request->url() === 'https://gateway.test/api/instances/hauser.nmbp/mounts'
                && $request->data() === ['target' => '/projects']
            ),
        );

        expect($removeExitCode)->toBe(0);
    });
});

describe('project and instance mutation command human renderers', function (): void {
    it('renders instance:register human output as a progress tree with a registered footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'registered'],
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Registering Instance')
            ->and($output)
            ->toContain('Apply and verify instance runtime')
            ->and($output)
            ->toContain("Instance for project 'docs' successfully registered on node 'app-1'.")
            ->and($output)
            ->not->toContain('action:')->and($output)
            ->not->toContain('{');
    });

    it('renders instance:register adopted action as adoption prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'adopted'],
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain(
                "Instance for project 'docs' successfully adopted from path '/home/orbit/apps/docs' on node 'app-1'.",
            )
            ->and($output)
            ->not->toContain('{');
    });

    it('renders instance:register converged action as no-change prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'converged'],
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance for project 'docs' is already converged on node 'app-1'. No changes were needed.")
            ->and($output)
            ->not->toContain('{');
    });

    it('renders instance:register partial action without claiming convergence', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'partial'],
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ], [
            'warnings' => [[
                'code' => 'proxy.enactment_failed',
                'family' => 'proxy',
                'layer' => 'router',
                'node' => 'gateway-router',
                'operation' => 'caddy.router.install',
                'message' => "Proxy route 'docs.example.com' failed on node 'gateway-router' during 'caddy.router.install'.",
                'next_command' => 'doctor --family=proxy --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance for project 'docs' is registered on node 'app-1', but proxy enactment is incomplete.")
            ->toContain("failed on node 'gateway-router' during 'caddy.router.install'")
            ->not->toContain("Instance for project 'docs' converged")
            ->not->toContain('No changes were needed.');
    });

    it('renders instance:register warnings after the success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'registered'],
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ], [
            'warnings' => [[
                'code' => 'proxy.domain_inactive',
                'family' => 'proxy',
                'message' => "Production domain 'docs.example.com' is not yet active.",
                'next_command' => 'instance:register docs --domain=docs.example.com',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Instance for project 'docs' successfully registered on node 'app-1'.")
            ->and($output)
            ->toContain("Production domain 'docs.example.com' is not yet active.")
            ->and($output)
            ->not->toContain('{');
    });

    it('renders instance:register gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'project.path_collision',
            "Path '/home/orbit/apps/docs' on node 'app-1' is already owned by project 'old-docs'.",
        ), 422);

        [$exitCode, $output] = runCommand($this, 'instance:register', [
            'project' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('is already owned by project')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders instance:root human output as a progress tree with a changed footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-01', 'root' => 'public'],
            'result' => ['changed' => true],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:root', [
            'instance' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Updating Instance Root')
            ->and($output)
            ->toContain('Apply runtime container configuration')
            ->and($output)
            ->toContain("Document root for instance 'docs.development' updated to 'public'.")
            ->and($output)
            ->toContain("Artifacts successfully re-applied on node 'app-01'.")
            ->and($output)
            ->not->toContain('changed:')->and($output)
            ->not->toContain('{');
    });

    it('renders instance:root converged no-op as already prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-01', 'root' => 'public'],
            'result' => ['changed' => false],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:root', [
            'instance' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Document root for instance 'docs.development' is already 'public'.")
            ->and($output)
            ->toContain("Artifacts successfully re-applied on node 'app-01'.")
            ->and($output)
            ->not->toContain('{');
    });

    it('renders instance:root drift warnings after the tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'docs'],
            'instance' => ['name' => 'development', 'node' => 'app-01', 'root' => 'public'],
            'result' => ['changed' => true],
        ], [
            'warnings' => [[
                'code' => 'instance.runtime_container_mismatch',
                'family' => 'instance',
                'message' => "runtime container configuration could not be re-applied on node 'app-01'.",
                'next_command' => 'doctor --family=instance --instance=docs --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:root', [
            'instance' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Document root for instance 'docs.development' updated to 'public'.")
            ->and($output)
            ->toContain('instance.runtime_container_mismatch')
            ->and($output)
            ->toContain('doctor --family=instance --instance=docs --restore')
            ->and($output)
            ->not->toContain('{');
    });

    it('renders instance:root gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'authorization_failed',
            "This node is not authorized for 'instance:root' on 'app-1'.",
        ), 403);

        [$exitCode, $output] = runCommand($this, 'instance:root', [
            'instance' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('not authorized')
            ->and($output)
            ->not->toContain('"error"');
    });

    it('renders project:remove human output as a progress tree with a removed footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'my-app'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'project:remove', [
            'project' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Removing Project')
            ->and($output)
            ->toContain('Apply and verify project removal')
            ->and($output)
            ->toContain("Project 'my-app' removed")
            ->and($output)
            ->not->toContain('action:')->and($output)
            ->not->toContain('{');
    });

    it('renders project:remove drift warnings in the footer and notes', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'project' => ['name' => 'my-app'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ], [
            'warnings' => [[
                'code' => 'instance.runtime_container_extra',
                'family' => 'instance',
                'message' => "App runtime container for 'my-app' could not be removed during cleanup.",
                'next_command' => 'doctor --family=instance --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'project:remove', [
            'project' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Project 'my-app' removed with drift")
            ->and($output)
            ->toContain('Drift detected:')
            ->and($output)
            ->toContain("App runtime container for 'my-app' could not be removed during cleanup.")
            ->and($output)
            ->not->toContain('{');
    });

    it('renders project:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('project.not_found', "Project 'my-app' not found."), 404);

        [$exitCode, $output] = runCommand($this, 'project:remove', [
            'project' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain("Project 'my-app' not found.")
            ->and($output)
            ->not->toContain('"error"');
    });
});
