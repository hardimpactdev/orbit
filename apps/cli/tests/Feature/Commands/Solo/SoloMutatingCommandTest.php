<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @mago-expect lint:halstead
 * @mago-expect lint:cyclomatic-complexity
 */
describe('Solo mutating commands', function (): void {
    beforeEach(function (): void {
        $this->tempPath = orbit_test_config_path(prefix: 'orbit-solo-mutate-');
        unlink_orbit_test_file($this->tempPath);
        app()->instance(OrbitConfigStore::class, new OrbitConfigStore(overridePath: $this->tempPath));
    });

    afterEach(function (): void {
        unlink_orbit_test_file($this->tempPath);
    });

    it('keeps local extension disabled failures for mutating commands', function (): void {
        fakeGateway(fakeSuccessEnvelope(['project' => ['name' => 'orbit']]));

        [$exitCode, $output] = runCommand($this, command: 'solo:project:create', params: [
            'name' => 'orbit',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('extension_disabled')
            ->and($decoded['error']['meta'])
            ->toMatchArray([
                'extension' => 'solo',
                'scope' => 'local',
            ]);
    });

    it('posts project mutations through the gateway', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['project' => ['name' => 'orbit']]));

        [$exitCode, $output] = runCommand($this, command: 'solo:project:create', params: [
            'name' => 'orbit',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && str_contains($request->url(), '/api/solo/project/create')
                && ($request->data()['name'] ?? null) === 'orbit'
                && ($request->data()['self'] ?? null) === true
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['project']['name'])
            ->toBe('orbit');
    });

    it('uses the configured default node for mutations without an explicit target', function (): void {
        enable_solo_mutating_extension();
        app(OrbitConfigStore::class)->setDefaultNode('NMBP');
        fakeGateway(fakeSuccessEnvelope(['project' => ['name' => 'orbit']]));

        [$exitCode] = runCommand($this, command: 'solo:project:create', params: [
            'name' => 'orbit',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => ($request->data()['node'] ?? null) === 'NMBP'
            && ! array_key_exists('self', $request->data()),
        );

        expect($exitCode)->toBe(0);
    });

    it('passes the target node to gateway-backed mutations', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['scratchpad' => ['name' => 'plan', 'revision' => 8]]));

        [$exitCode] = runCommand($this, command: 'solo:scratchpad:write', params: [
            'scratchpad' => 'plan',
            '--content' => 'updated plan',
            '--expected-revision' => '7',
            '--node' => 'NMBP',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PUT'
                && str_contains($request->url(), '/api/solo/scratchpad/write')
                && ($request->data()['scratchpad'] ?? null) === 'plan'
                && ($request->data()['node'] ?? null) === 'NMBP'
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('requires force before destructive non-interactive commands call the gateway', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['todo' => ['id' => 42]]));

        [$exitCode, $output] = runCommand($this, command: 'solo:todo:delete', params: [
            'todo' => '42',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('destructive_consent_required');
    });

    it('requires force before destructive commands across resource groups', function (
        string $command,
        array $params,
    ): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(solo_mutating_success_payload($command)));

        [$exitCode, $output] = runCommand($this, command: $command, params: $params + ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('force')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('destructive_consent_required');
    })->with([
        'scratchpad delete' => ['solo:scratchpad:delete', ['scratchpad' => 'plan']],
        'process close' => ['solo:process:close', ['process' => 'worker']],
    ]);

    it('preserves the legacy force gate for non-destructive force-required operations', function (
        string $command,
        array $params,
    ): void {
        enable_solo_mutating_extension();
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: $command, params: $params + ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['reason'])
            ->toBe('force_required');
    })->with([
        'process stop' => ['solo:process:stop', ['process' => 'worker']],
        'process clear output' => ['solo:process:clear-output', ['process' => 'worker']],
        'scratchpad archive' => ['solo:scratchpad:archive', ['scratchpad' => 'plan']],
    ]);

    it('validates destructive command targets before requiring consent', function (): void {
        enable_solo_mutating_extension();
        Http::fake();

        [$exitCode, $output] = runCommand($this, command: 'solo:todo:delete', params: ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('todo');
    });

    it('prompts for destructive consent after resolving the Solo target', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['todo' => ['id' => 42, 'deleted' => true]]));

        $this
            ->artisan('solo:todo:delete', ['todo' => '42'])
            ->expectsConfirmation("Run destructive Solo command 'solo:todo:delete' for todo '42'?", 'yes')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => ($request->data()['todo'] ?? null) === '42');
    });

    it('returns canonical destructive consent failure when a Solo prompt is declined', function (
        string $command,
        array $params,
        string $prompt,
    ): void {
        enable_solo_mutating_extension();
        Http::fake();

        $this
            ->artisan($command, $params)
            ->expectsConfirmation($prompt, 'no')
            ->expectsOutput('validation_failed: Operation cancelled.')
            ->assertFailed();

        Http::assertNothingSent();
    })->with([
        'todo delete' => [
            'solo:todo:delete',
            ['todo' => '42'],
            "Run destructive Solo command 'solo:todo:delete' for todo '42'?",
        ],
        'process close' => [
            'solo:process:close',
            ['process' => 'worker'],
            "Run destructive Solo command 'solo:process:close' for process 'worker'?",
        ],
    ]);

    it('sends forced destructive commands to the gateway', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['todo' => ['id' => 42, 'deleted' => true]]));

        [$exitCode] = runCommand($this, command: 'solo:todo:delete', params: [
            'todo' => '42',
            '--project' => '4',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/solo/todo/delete')
                && ($request->data()['todo'] ?? null) === '42'
                && ($request->data()['project'] ?? null) === '4'
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('forwards project scope for project-scoped todo mutations', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['todo' => ['id' => 42, 'title' => 'Check live Solo']]));

        [$exitCode] = runCommand($this, command: 'solo:todo:create', params: [
            'title' => 'Check live Solo',
            '--project' => '4',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && str_contains($request->url(), '/api/solo/todo/create')
                && ($request->data()['title'] ?? null) === 'Check live Solo'
                && ($request->data()['project'] ?? null) === '4'
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('forwards scratchpad expected revisions', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(['scratchpad' => ['name' => 'plan', 'revision' => 8]]));

        [$exitCode] = runCommand($this, command: 'solo:scratchpad:write', params: [
            'scratchpad' => 'plan',
            '--content' => 'updated plan',
            '--expected-revision' => '7',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PUT'
                && str_contains($request->url(), '/api/solo/scratchpad/write')
                && ($request->data()['scratchpad'] ?? null) === 'plan'
                && ($request->data()['content'] ?? null) === 'updated plan'
                && ($request->data()['expected_revision'] ?? null) === '7'
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('maps gateway Solo write errors into the CLI envelope', function (): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeErrorEnvelope(
            code: 'solo_revision_conflict',
            message: 'Scratchpad revision changed.',
            meta: ['expected_revision' => 7, 'actual_revision' => 8],
        ), status: 409);

        [$exitCode, $output] = runCommand($this, command: 'solo:scratchpad:write', params: [
            'scratchpad' => 'plan',
            '--content' => 'updated plan',
            '--expected-revision' => '7',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('solo_revision_conflict')
            ->and($decoded['error']['meta']['actual_revision'])
            ->toBe(8);
    });

    it('implements representative mutating resource groups', function (string $command): void {
        enable_solo_mutating_extension();
        fakeGateway(fakeSuccessEnvelope(solo_mutating_success_payload($command)));

        [$exitCode] = runCommand($this, command: $command, params: solo_mutating_command_params($command));

        expect($exitCode)->toBe(0);
    })->with([
        'solo:project:create',
        'solo:project:rename',
        'solo:project:select',
        'solo:project:delete',
        'solo:process:input',
        'solo:process:spawn',
        'solo:process:start',
        'solo:process:stop',
        'solo:process:restart',
        'solo:process:clear-output',
        'solo:process:rename',
        'solo:process:close',
        'solo:scratchpad:create',
        'solo:scratchpad:write',
        'solo:scratchpad:append',
        'solo:scratchpad:append-section',
        'solo:scratchpad:edit',
        'solo:scratchpad:rename',
        'solo:scratchpad:archive',
        'solo:scratchpad:clear',
        'solo:scratchpad:delete',
        'solo:todo:create',
        'solo:todo:update',
        'solo:todo:complete',
        'solo:todo:reopen',
        'solo:todo:delete',
        'solo:todo:lock',
        'solo:todo:unlock',
        'solo:todo:comment:add',
        'solo:todo:comment:update',
        'solo:todo:comment:delete',
        'solo:lock:acquire',
        'solo:lock:release',
        'solo:timer:set',
        'solo:timer:cancel',
        'solo:timer:pause',
        'solo:timer:resume',
    ]);
});

function enable_solo_mutating_extension(): void
{
    app(OrbitConfigStore::class)->enableExtension('solo');
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
}

/**
 * @return array<string, mixed>
 *
 * @mago-expect lint:halstead
 */
function solo_mutating_command_params(string $command): array
{
    return match ($command) {
        'solo:project:create' => ['name' => 'orbit', '--json' => true],
        'solo:project:rename' => ['project' => 'orbit', '--name' => 'orbit-next', '--json' => true],
        'solo:project:select' => ['project' => 'orbit', '--json' => true],
        'solo:project:delete' => ['project' => 'orbit', '--force' => true, '--json' => true],
        'solo:process:input' => ['process' => 'worker', '--input' => 'status', '--json' => true],
        'solo:process:spawn' => [
            'processCommand' => 'php artisan queue:work',
            '--project' => 'orbit',
            '--json' => true,
        ],
        'solo:process:start',
        'solo:process:stop',
        'solo:process:restart',
        'solo:process:clear-output',
        'solo:process:close',
            => ['process' => 'worker', '--force' => true, '--json' => true],
        'solo:process:rename' => ['process' => 'worker', '--name' => 'worker-2', '--json' => true],
        'solo:scratchpad:create' => ['name' => 'plan', '--content' => 'draft', '--json' => true],
        'solo:scratchpad:write' => [
            'scratchpad' => 'plan',
            '--content' => 'updated',
            '--expected-revision' => '7',
            '--json' => true,
        ],
        'solo:scratchpad:append' => [
            'scratchpad' => 'plan',
            '--content' => 'more',
            '--expected-revision' => '7',
            '--json' => true,
        ],
        'solo:scratchpad:append-section' => [
            'scratchpad' => 'plan',
            '--heading' => 'Notes',
            '--content' => 'more',
            '--expected-revision' => '7',
            '--json' => true,
        ],
        'solo:scratchpad:edit' => [
            'scratchpad' => 'plan',
            '--search' => 'old',
            '--replace' => 'new',
            '--expected-revision' => '7',
            '--json' => true,
        ],
        'solo:scratchpad:rename' => [
            'scratchpad' => 'plan',
            '--name' => 'plan-next',
            '--expected-revision' => '7',
            '--json' => true,
        ],
        'solo:scratchpad:archive', 'solo:scratchpad:clear', 'solo:scratchpad:delete' => [
            'scratchpad' => 'plan',
            '--force' => true,
            '--json' => true,
        ],
        'solo:todo:create' => ['title' => 'Check feature', '--project' => '4', '--json' => true],
        'solo:todo:update' => ['todo' => '42', '--project' => '4', '--title' => 'Check feature', '--json' => true],
        'solo:todo:complete', 'solo:todo:reopen', 'solo:todo:lock', 'solo:todo:unlock' => [
            'todo' => '42',
            '--project' => '4',
            '--json' => true,
        ],
        'solo:todo:delete' => ['todo' => '42', '--project' => '4', '--force' => true, '--json' => true],
        'solo:todo:comment:add' => ['todo' => '42', '--body' => 'Done', '--json' => true],
        'solo:todo:comment:update' => ['comment' => '7', '--body' => 'Updated', '--json' => true],
        'solo:todo:comment:delete' => ['comment' => '7', '--force' => true, '--json' => true],
        'solo:lock:acquire', 'solo:lock:release' => ['name' => 'deploy', '--json' => true],
        'solo:timer:set' => ['name' => 'idle', '--seconds' => '60', '--json' => true],
        'solo:timer:cancel', 'solo:timer:pause', 'solo:timer:resume' => ['timer' => 'idle', '--json' => true],
        default => ['--json' => true],
    };
}

/**
 * @return array<string, mixed>
 */
function solo_mutating_success_payload(string $command): array
{
    return match (true) {
        str_starts_with($command, 'solo:project:') => ['project' => ['name' => 'orbit']],
        str_starts_with($command, 'solo:process:') => ['process' => ['name' => 'worker']],
        str_starts_with($command, 'solo:scratchpad:') => ['scratchpad' => ['name' => 'plan', 'revision' => 8]],
        str_starts_with($command, 'solo:todo:comment:') => ['comment' => ['id' => 7]],
        str_starts_with($command, 'solo:todo:') => ['todo' => ['id' => 42]],
        str_starts_with($command, 'solo:lock:') => ['lock' => ['name' => 'deploy']],
        str_starts_with($command, 'solo:timer:') => ['timer' => ['name' => 'idle']],
        default => [],
    };
}
