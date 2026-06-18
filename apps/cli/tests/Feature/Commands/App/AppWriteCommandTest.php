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

describe('app write commands', function (): void {
    it('validates required app:new inputs before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:new', [
            'name' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node');
    });

    it('posts app:new payloads to the gateway apps endpoint', function (): void {
        $complete = [
            'exit_code' => 0,
            'data' => [
                'result' => ['action' => 'created'],
                'app' => ['name' => 'docs', 'node' => 'app-1'],
            ],
        ];

        fakeGatewayProgressStream(gatewayProgressFrame('complete', $complete));

        [$exitCode, $output] = runCommand($this, 'app:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--repo' => 'spatie/docs',
            '--root' => 'public',
            '--php-version' => '8.5',
            '--domain' => 'docs.example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps'
            && $request->hasHeader('Accept', 'text/event-stream')
            && $request->data() === [
                'name' => 'docs',
                'node' => 'app-1',
                'repository' => 'spatie/docs',
                'root' => 'public',
                'php_version' => '8.5',
                'domain' => 'docs.example.com',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toBe([
                'event' => 'complete',
                'data' => $complete,
            ]);
    });

    it('posts app:register payloads to the gateway register endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'adopted'],
            'app' => ['name' => 'docs', 'node' => 'app-1'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:register', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
            '--root' => 'public',
            '--php-version' => '8.5',
            '--domain' => 'docs.example.com',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/register'
            && $request->data() === [
                'name' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs',
                'root' => 'public',
                'php_version' => '8.5',
                'domain' => 'docs.example.com',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['result']['action'])->toBe('adopted');
    });

    it('validates required app:register inputs before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:register', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('requires force before removing an app non-interactively', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'app:remove', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('deletes app:remove targets with destructive consent when forced', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:remove', [
            'app' => 'docs',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/apps/docs'
            && $request->data() === [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['result']['action'])->toBe('removed');
    });

    it('prompts before removing an app without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ]));

        $this->artisan('app:remove', ['app' => 'docs'])
            ->expectsConfirmation("Remove app 'docs' and all owned artifacts? This cannot be undone.", 'yes')
            ->expectsOutputToContain("App 'docs' removed")
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/apps/docs'
            && $request->data() === [
                'destructive_consent' => true,
                'destructive_consent_source' => 'force',
            ]);
    });

    it('posts app:prune payloads to the gateway prune endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'stale_workspaces' => [],
            'dry_run' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:prune', [
            'app' => 'docs',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/prune'
            && $request->data() === [
                'app' => 'docs',
                'dry_run' => true,
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['dry_run'])->toBeTrue();
    });

    it('prompts before pruning stale workspaces without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'stale_workspaces' => [],
            'dry_run' => false,
        ]));

        $this->artisan('app:prune', ['app' => 'docs'])
            ->expectsConfirmation("Pruning will permanently remove all stale workspaces for 'docs'. Continue?", 'yes')
            ->expectsOutputToContain('Pruning App Workspaces')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/prune'
            && $request->data() === [
                'app' => 'docs',
                'dry_run' => false,
            ]);
    });

    it('posts app:root payloads to the gateway app root endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs', 'root' => 'public'],
            'result' => ['changed' => true],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:root', [
            'app' => 'docs',
            'root' => 'public',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/root'
            && $request->data() === ['root' => 'public']);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['result']['changed'])->toBeTrue();
    });

    it('validates required app:root inputs before gateway IO', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:root', [
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('root');
    });

    it('validates required app:agent-ide inputs before gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'missing app' => [[], 'app'],
        'missing adapter' => [['app' => 'docs'], 'agent_ide'],
    ]);

    it('prompts before app:agent-ide resubmits destructive cleanup consent', function (): void {
        $sequence = fakeGatewaySequence();
        $sequence
            ->push(fakeErrorEnvelope(
                'workspace_cleanup_consent_required',
                "Destructive workspace cleanup required (1 workspace(s) managed by 'opencode'). Use force=true to proceed.",
                [
                    'previous_adapter' => 'opencode',
                    'stale_workspaces' => ['stale-ws'],
                ],
            ), 422)
            ->push(fakeSuccessEnvelope([
                'app' => ['name' => 'docs'],
                'agent_ide' => ['adapter' => 'polyscope', 'source' => 'app', 'effective_adapter' => 'polyscope'],
                'cleanup' => ['workspaces_removed' => ['stale-ws']],
                'action' => 'set',
                'previous_adapter' => 'opencode',
            ]));

        $this->artisan('app:agent-ide', [
            'app' => 'docs',
            'agent_ide' => 'polyscope',
        ])
            ->expectsConfirmation("This will remove 1 workspace(s) managed by the previous adapter 'opencode'. Continue?", 'yes')
            ->expectsOutputToContain('app')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSentInOrder([
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/apps/docs/agent-ide'
                && $request->data() === ['agent_ide' => 'polyscope', 'force' => false],
            fn (Request $request): bool => $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/apps/docs/agent-ide'
                && $request->data() === ['agent_ide' => 'polyscope', 'force' => true],
        ]);
    });

    it('uses force for app:agent-ide without prompting', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs'],
            'agent_ide' => ['adapter' => 'polyscope', 'source' => 'app', 'effective_adapter' => 'polyscope'],
            'cleanup' => ['workspaces_removed' => ['stale-ws']],
            'action' => 'set',
            'previous_adapter' => 'opencode',
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'docs',
            'agent_ide' => 'polyscope',
            '--force' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/agent-ide'
            && $request->data() === ['agent_ide' => 'polyscope', 'force' => true]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['cleanup']['workspaces_removed'])->toBe(['stale-ws']);
    });

    it('forwards app:worker actions to their gateway endpoints', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => true,
            'worker_config' => null,
            'changed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'enable',
            'app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/worker/enable');

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['worker_enabled'])->toBeTrue();
    });

    it('renders human app:worker show output for an enabled app', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'show',
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' worker mode is enabled.")
            ->and($output)->toContain('  workers: auto')
            ->and($output)->toContain('  max_requests: 500')
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('worker_config:')
            ->and($output)->not->toContain('worker_enabled');
    });

    it('renders human app:worker show output for a disabled app without config detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => false,
            'worker_config' => null,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'show',
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe("App 'docs' worker mode is disabled.")
            ->and($output)->not->toContain('workers:')
            ->and($output)->not->toContain('max_requests:');
    });

    it('renders human app:worker enable output when state changed', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
            'changed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'enable',
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' worker mode enabled.")
            ->and($output)->toContain('  workers: auto')
            ->and($output)->toContain('  max_requests: 500')
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('worker_config:');
    });

    it('renders human app:worker enable output when already enabled', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => true,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
            'changed' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'enable',
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' worker mode already enabled.")
            ->and($output)->toContain('  workers: auto')
            ->and($output)->toContain('  max_requests: 500')
            ->and($output)->not->toContain('{');
    });

    it('renders human app:worker disable output retaining config detail', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => false,
            'worker_config' => ['workers' => 'auto', 'max_requests' => 500],
            'changed' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'disable',
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' worker mode disabled.")
            ->and($output)->toContain('  workers: auto')
            ->and($output)->toContain('  max_requests: 500')
            ->and($output)->not->toContain('{')
            ->and($output)->not->toContain('worker_config:');
    });

    it('renders human app:worker disable output when already disabled', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'worker_enabled' => false,
            'worker_config' => null,
            'changed' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:worker', [
            'action' => 'disable',
            'app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe("App 'docs' worker mode already disabled.")
            ->and($output)->not->toContain('workers:')
            ->and($output)->not->toContain('max_requests:');
    });

    it('validates required app:mount inputs before gateway IO', function (array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:mount', [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'missing action' => [[], 'action'],
        'missing app' => [['action' => 'add'], 'app'],
        'missing source' => [['action' => 'add', 'app' => 'docs'], 'source'],
        'missing target for add' => [['action' => 'add', 'app' => 'docs', 'source' => '/home/orbit/packages'], 'target'],
        'missing target for remove' => [['action' => 'remove', 'app' => 'docs'], 'target'],
    ]);

    it('forwards app:mount list add and remove to their gateway endpoints', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs'],
            'mounts' => [[
                'source' => '/home/orbit/packages',
                'target' => '/home/orbit/packages',
                'read_only' => true,
            ]],
            'inherited_by_workspaces' => true,
        ]));

        [$listExitCode] = runCommand($this, 'app:mount', [
            'action' => 'list',
            'app' => 'docs',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/apps/docs/mounts');

        expect($listExitCode)->toBe(0);

        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs'],
            'mount' => [
                'source' => '/home/orbit/packages',
                'target' => '/home/orbit/packages',
                'read_only' => false,
            ],
            'mounts' => [],
            'action' => 'created',
            'inherited_by_workspaces' => true,
        ]));

        [$addExitCode] = runCommand($this, 'app:mount', [
            'action' => 'add',
            'app' => 'docs',
            'source' => '/home/orbit/packages',
            'target' => '/home/orbit/packages',
            '--read-write' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/apps/docs/mounts'
            && $request->data() === [
                'source' => '/home/orbit/packages',
                'target' => '/home/orbit/packages',
                'read_only' => false,
            ]);

        expect($addExitCode)->toBe(0);

        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs'],
            'mounts' => [],
            'action' => 'removed',
            'inherited_by_workspaces' => true,
        ]));

        [$removeExitCode] = runCommand($this, 'app:mount', [
            'action' => 'remove',
            'app' => 'docs',
            'target' => '/home/orbit/packages',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://gateway.test/api/apps/docs/mounts'
            && $request->data() === ['target' => '/home/orbit/packages']);

        expect($removeExitCode)->toBe(0);
    });

});

describe('app mutation command human renderers', function (): void {
    it('renders app:register human output as a progress tree with a registered footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'registered'],
            'app' => ['name' => 'docs', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:register', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Registering App')
            ->and($output)->toContain('Apply and verify app runtime')
            ->and($output)->toContain("App 'docs' successfully registered on node 'app-1'.")
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders app:register adopted action as adoption prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'adopted'],
            'app' => ['name' => 'docs', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:register', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' successfully adopted from path '/home/orbit/apps/docs' on node 'app-1'.")
            ->and($output)->not->toContain('{');
    });

    it('renders app:register converged action as no-change prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'converged'],
            'app' => ['name' => 'docs', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:register', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' is already converged on node 'app-1'. No changes were needed.")
            ->and($output)->not->toContain('{');
    });

    it('renders app:register warnings after the success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => ['action' => 'registered'],
            'app' => ['name' => 'docs', 'node' => 'app-1', 'path' => '/home/orbit/apps/docs'],
        ], [
            'warnings' => [[
                'code' => 'proxy.domain_inactive',
                'family' => 'proxy',
                'message' => "Production domain 'docs.example.com' is not yet active.",
                'next_command' => 'app:register docs --domain=docs.example.com',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:register', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'docs' successfully registered on node 'app-1'.")
            ->and($output)->toContain("Production domain 'docs.example.com' is not yet active.")
            ->and($output)->not->toContain('{');
    });

    it('renders app:register gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'app.path_collision',
            "Path '/home/orbit/apps/docs' on node 'app-1' is already owned by app 'old-docs'.",
        ), 422);

        [$exitCode, $output] = runCommand($this, 'app:register', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--path' => '/home/orbit/apps/docs',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('is already owned by app')
            ->and($output)->not->toContain('"error"');
    });

    it('renders app:root human output as a progress tree with a changed footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs', 'node' => 'app-01', 'root' => 'public'],
            'result' => ['changed' => true],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:root', [
            'app' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Updating App Root')
            ->and($output)->toContain('Apply runtime container configuration')
            ->and($output)->toContain("Document root for app 'docs' updated to 'public'.")
            ->and($output)->toContain("Artifacts successfully re-applied on node 'app-01'.")
            ->and($output)->not->toContain('changed:')
            ->and($output)->not->toContain('{');
    });

    it('renders app:root converged no-op as already prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs', 'node' => 'app-01', 'root' => 'public'],
            'result' => ['changed' => false],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:root', [
            'app' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Document root for app 'docs' is already 'public'.")
            ->and($output)->toContain("Artifacts successfully re-applied on node 'app-01'.")
            ->and($output)->not->toContain('{');
    });

    it('renders app:root drift warnings after the tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'docs', 'node' => 'app-01', 'root' => 'public'],
            'result' => ['changed' => true],
        ], [
            'warnings' => [[
                'code' => 'app.runtime_container_mismatch',
                'family' => 'app',
                'message' => "runtime container configuration could not be re-applied on node 'app-01'.",
                'next_command' => 'doctor --family=app --app=docs --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:root', [
            'app' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("Document root for app 'docs' updated to 'public'.")
            ->and($output)->toContain('app.runtime_container_mismatch')
            ->and($output)->toContain('doctor --family=app --app=docs --restore')
            ->and($output)->not->toContain('{');
    });

    it('renders app:root gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'authorization_failed',
            "This node is not authorized for 'app:root' on 'app-1'.",
        ), 403);

        [$exitCode, $output] = runCommand($this, 'app:root', [
            'app' => 'docs',
            'root' => 'public',
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('not authorized')
            ->and($output)->not->toContain('"error"');
    });

    it('renders app:remove human output as a progress tree with a removed footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:remove', [
            'app' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Removing App')
            ->and($output)->toContain('Apply and verify app removal')
            ->and($output)->toContain("App 'my-app' removed")
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders app:remove drift warnings in the footer and notes', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'result' => ['action' => 'removed'],
            'cleanup' => [],
        ], [
            'warnings' => [[
                'code' => 'app.runtime_container_extra',
                'family' => 'app',
                'message' => "App runtime container for 'my-app' could not be removed during cleanup.",
                'next_command' => 'doctor --family=app --restore',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:remove', [
            'app' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain("App 'my-app' removed with drift")
            ->and($output)->toContain('Drift detected:')
            ->and($output)->toContain("App runtime container for 'my-app' could not be removed during cleanup.")
            ->and($output)->not->toContain('{');
    });

    it('renders app:remove gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('app.not_found', "App 'my-app' not found."), 404);

        [$exitCode, $output] = runCommand($this, 'app:remove', [
            'app' => 'my-app',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("App 'my-app' not found.")
            ->and($output)->not->toContain('"error"');
    });

    it('renders app:prune human output as a per-workspace progress tree', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'stale_workspaces' => [
                ['name' => 'feature-docs', 'removed' => true],
                ['name' => 'stale-experiment', 'removed' => true],
            ],
            'dry_run' => false,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:prune', [
            'app' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Pruning App Workspaces')
            ->and($output)->toContain('Query agent IDE adapters')
            ->and($output)->toContain('Remove workspace `feature-docs`')
            ->and($output)->toContain('Remove workspace `stale-experiment`')
            ->and($output)->not->toContain('dry_run:')
            ->and($output)->not->toContain('{');
    });

    it('renders app:prune dry-run output with preview labels and footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => 'docs',
            'stale_workspaces' => [
                ['name' => 'feature-docs', 'removed' => false],
            ],
            'dry_run' => true,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:prune', [
            'app' => 'docs',
            '--dry-run' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Previewing App Workspace Prune')
            ->and($output)->toContain('Preview workspace `feature-docs`')
            ->and($output)->toContain('Dry run complete. No side effects performed.')
            ->and($output)->not->toContain('{');
    });

    it('renders app:prune gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope('app.not_found', "App 'docs' not found."), 404);

        [$exitCode, $output] = runCommand($this, 'app:prune', [
            'app' => 'docs',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain("App 'docs' not found.")
            ->and($output)->not->toContain('"error"');
    });

    it('renders app:agent-ide human output as a progress tree with a set footer', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'agent_ide' => ['adapter' => 'opencode', 'source' => 'app', 'effective_adapter' => 'opencode'],
            'cleanup' => ['workspaces_removed' => []],
            'action' => 'set',
            'previous_adapter' => null,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'opencode',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Configuring App Agent IDE')
            ->and($output)->toContain('Apply and verify app agent IDE')
            ->and($output)->toContain('App "my-app" agent IDE set to "opencode" (effective: "opencode").')
            ->and($output)->not->toContain('action:')
            ->and($output)->not->toContain('{');
    });

    it('renders app:agent-ide inherit resolution prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app', 'node' => 'app-1'],
            'agent_ide' => ['adapter' => null, 'source' => 'node', 'effective_adapter' => 'polyscope'],
            'cleanup' => ['workspaces_removed' => []],
            'action' => 'set',
            'previous_adapter' => null,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'inherit',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('App "my-app" agent IDE set to inherit (effective: "polyscope" from node "app-1").')
            ->and($output)->not->toContain('{');
    });

    it('renders app:agent-ide none resolution prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'agent_ide' => ['adapter' => null, 'source' => 'app', 'effective_adapter' => null],
            'cleanup' => ['workspaces_removed' => []],
            'action' => 'set',
            'previous_adapter' => null,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'none',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('App "my-app" agent IDE set to none (effective: none).')
            ->and($output)->not->toContain('{');
    });

    it('renders app:agent-ide converged as already-set prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'agent_ide' => ['adapter' => 'opencode', 'source' => 'app', 'effective_adapter' => 'opencode'],
            'cleanup' => ['workspaces_removed' => []],
            'action' => 'converged',
            'previous_adapter' => null,
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'opencode',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('App "my-app" agent IDE already set to "opencode".')
            ->and($output)->not->toContain('{');
    });

    it('renders app:agent-ide cleanup summary after the success line', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'agent_ide' => ['adapter' => 'polyscope', 'source' => 'app', 'effective_adapter' => 'polyscope'],
            'cleanup' => ['workspaces_removed' => ['stale-ws-1', 'stale-ws-2']],
            'action' => 'set',
            'previous_adapter' => 'opencode',
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'polyscope',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('App "my-app" agent IDE set to "polyscope" (effective: "polyscope").')
            ->and($output)->toContain('Removed 2 stale workspaces during adapter switch:')
            ->and($output)->toContain('- stale-ws-1')
            ->and($output)->toContain('- stale-ws-2')
            ->and($output)->not->toContain('{');
    });

    it('renders app:agent-ide post-configuration warnings as prose', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'my-app'],
            'agent_ide' => ['adapter' => 'polyscope', 'source' => 'app', 'effective_adapter' => 'polyscope'],
            'cleanup' => ['workspaces_removed' => []],
            'action' => 'set',
            'previous_adapter' => 'opencode',
        ], [
            'warnings' => [[
                'code' => 'workspace.remove_failed',
                'family' => 'workspace',
                'message' => "Failed to remove workspace 'stale-ws'.",
                'next_command' => 'workspace:remove stale-ws --app=my-app --force',
            ]],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'polyscope',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('App "my-app" agent IDE set to "polyscope" (effective: "polyscope").')
            ->and($output)->toContain("Failed to remove workspace 'stale-ws'.")
            ->and($output)->not->toContain('{');
    });

    it('renders app:agent-ide gateway failures as prose in human mode', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'authorization_failed',
            "This node is not authorized for 'app:agent' on 'app-1'.",
        ), 403);

        [$exitCode, $output] = runCommand($this, 'app:agent-ide', [
            'app' => 'my-app',
            'agent_ide' => 'opencode',
            '--force' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('not authorized')
            ->and($output)->not->toContain('"error"');
    });
});
