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
            ->expectsOutputToContain('result')
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
            ->expectsOutputToContain('dry_run')
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
