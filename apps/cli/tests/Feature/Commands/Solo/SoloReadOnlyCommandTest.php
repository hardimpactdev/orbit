<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/**
 * @mago-expect lint:halstead
 */
describe('Solo read-only commands', function (): void {
    beforeEach(function (): void {
        $this->tempPath = orbit_test_config_path(prefix: 'orbit-solo-read-');
        unlink_orbit_test_file($this->tempPath);
        app()->instance(OrbitConfigStore::class, new OrbitConfigStore(overridePath: $this->tempPath));
    });

    afterEach(function (): void {
        unlink_orbit_test_file($this->tempPath);
    });

    it('keeps local extension disabled failures for read-only commands', function (): void {
        fakeGateway(fakeSuccessEnvelope(['tools' => []]));

        [$exitCode, $output] = runCommand($this, command: 'solo:tools', params: [
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

    it('calls the gateway and renders tools as JSON', function (): void {
        enable_solo_read_extension();
        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                ['name' => 'spawn_agent', 'description' => 'Spawn an agent'],
            ],
        ], ['source' => 'solo']));

        [$exitCode, $output] = runCommand($this, command: 'solo:tools', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_contains($request->url(), '/api/solo/tools')
                && ($request->data()['self'] ?? null) === true
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toHaveKey('success')
            ->and($decoded['success']['data']['tools'][0]['name'])
            ->toBe('spawn_agent')
            ->and($decoded['success']['meta']['source'])
            ->toBe('solo');
    });

    it('uses the configured default node when no explicit Solo target is supplied', function (): void {
        enable_solo_read_extension();
        app(OrbitConfigStore::class)->setDefaultNode('NMBP');
        fakeGateway(fakeSuccessEnvelope(['projects' => []]));

        [$exitCode] = runCommand($this, command: 'solo:project:list', params: [
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => ($request->data()['node'] ?? null) === 'NMBP'
            && ! array_key_exists('self', $request->data()),
        );

        expect($exitCode)->toBe(0);
    });

    it('passes the target node to gateway-backed read-only commands', function (): void {
        enable_solo_read_extension();
        fakeGateway(fakeSuccessEnvelope([
            'projects' => [
                ['name' => 'orbit', 'status' => 'active'],
            ],
        ]));

        [$exitCode] = runCommand($this, command: 'solo:project:list', params: [
            '--node' => 'NMBP',
            '--json' => true,
        ]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_contains($request->url(), '/api/solo/projects')
                && ($request->data()['node'] ?? null) === 'NMBP'
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('renders project lists for humans', function (): void {
        enable_solo_read_extension();
        fakeGateway(fakeSuccessEnvelope([
            'projects' => [
                ['name' => 'orbit', 'status' => 'active'],
                ['name' => 'hauser', 'status' => 'idle'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'solo:project:list');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('orbit')
            ->and($output)
            ->toContain('hauser');
    });

    it('passes required identifiers to gateway-backed show commands', function (): void {
        enable_solo_read_extension();
        fakeGateway(fakeSuccessEnvelope([
            'project' => [
                'name' => 'orbit',
                'status' => 'active',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, command: 'solo:project:show', params: [
            'project' => 'orbit',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_contains($request->url(), '/api/solo/project/show')
                && ($request->data()['project'] ?? null) === 'orbit'
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['project']['name'])
            ->toBe('orbit');
    });

    it('validates missing identifiers before calling the gateway', function (): void {
        enable_solo_read_extension();
        fakeGateway(fakeSuccessEnvelope(['project' => ['name' => 'orbit']]));

        [$exitCode, $output] = runCommand($this, command: 'solo:project:show', params: [
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

    it('maps gateway Solo errors into the CLI envelope', function (): void {
        enable_solo_read_extension();
        fakeGateway(fakeErrorEnvelope(
            code: 'solo_upstream_unavailable',
            message: 'Solo API is unavailable on gateway-1.',
            meta: ['node' => 'gateway-1'],
        ), status: 503);

        [$exitCode, $output] = runCommand($this, command: 'solo:process:list', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('solo_upstream_unavailable')
            ->and($decoded['error']['meta']['node'])
            ->toBe('gateway-1');
    });

    it('implements the planned read-only command set', function (string $command): void {
        enable_solo_read_extension();
        fakeGateway(fakeSuccessEnvelope(solo_read_success_payload($command)));

        [$exitCode] = runCommand($this, command: $command, params: solo_read_command_params($command));

        expect($exitCode)->toBe(0);
    })->with([
        'solo:tools',
        'solo:project:list',
        'solo:project:show',
        'solo:project:status',
        'solo:project:stats',
        'solo:process:list',
        'solo:process:show',
        'solo:process:output',
        'solo:scratchpad:list',
        'solo:scratchpad:show',
        'solo:scratchpad:find',
        'solo:todo:list',
        'solo:todo:show',
        'solo:service:list',
        'solo:timer:list',
        'solo:lock:status',
        'solo:agent-tool:list',
    ]);

    it('keeps unimplemented registered commands deferred', function (): void {
        enable_solo_read_extension();

        [$exitCode, $output] = runCommand($this, command: 'solo:status', params: [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('solo_command_deferred');
    });

    it('does not expose a Solo node transport selector', function (): void {
        enable_solo_read_extension();
        Artisan::all();

        $command = Artisan::all()['solo:tools'];

        expect($command->getDefinition()->hasOption('node'))
            ->toBeTrue()
            ->and($command->getDefinition()->hasOption('node-transport'))
            ->toBeFalse();
    });
});

function enable_solo_read_extension(): void
{
    app(OrbitConfigStore::class)->enableExtension('solo');
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
}

/**
 * @return array<string, mixed>
 */
function solo_read_command_params(string $command): array
{
    return match ($command) {
        'solo:project:show', 'solo:project:status', 'solo:project:stats' => ['project' => 'orbit', '--json' => true],
        'solo:process:show', 'solo:process:output' => ['process' => 'worker', '--json' => true],
        'solo:scratchpad:show' => ['scratchpad' => 'plan', '--json' => true],
        'solo:scratchpad:find' => ['query' => 'plan', '--json' => true],
        'solo:todo:show' => ['todo' => '42', '--json' => true],
        default => ['--json' => true],
    };
}

/**
 * @return array<string, mixed>
 */
function solo_read_success_payload(string $command): array
{
    return match ($command) {
        'solo:tools' => ['tools' => [['name' => 'spawn_agent']]],
        'solo:project:list' => ['projects' => [['name' => 'orbit']]],
        'solo:project:show' => ['project' => ['name' => 'orbit']],
        'solo:project:status' => ['status' => ['project' => 'orbit', 'state' => 'active']],
        'solo:project:stats' => ['stats' => ['project' => 'orbit', 'processes' => 1]],
        'solo:process:list' => ['processes' => [['name' => 'worker']]],
        'solo:process:show' => ['process' => ['name' => 'worker']],
        'solo:process:output' => ['output' => ['process' => 'worker', 'lines' => ['ready']]],
        'solo:scratchpad:list' => ['scratchpads' => [['name' => 'plan']]],
        'solo:scratchpad:show' => ['scratchpad' => ['name' => 'plan']],
        'solo:scratchpad:find' => ['scratchpads' => [['name' => 'plan']]],
        'solo:todo:list' => ['todos' => [['id' => 42, 'title' => 'Check']]],
        'solo:todo:show' => ['todo' => ['id' => 42, 'title' => 'Check']],
        'solo:service:list' => ['services' => [['name' => 'solo']]],
        'solo:timer:list' => ['timers' => [['name' => 'idle']]],
        'solo:lock:status' => ['lock' => ['held' => false]],
        'solo:agent-tool:list' => ['agent_tools' => [['name' => 'codex']]],
        default => [],
    };
}
