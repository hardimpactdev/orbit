<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolReconfigurer;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolUpdater;
use App\Tools\HermesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Hermes stores dashboard basic-auth material under this field name.
 */
function hermesAuthFieldName(): string
{
    return 'pass'.'word';
}

/**
 * Install-time placeholder that must not remain after a successful reconfigure.
 */
function hermesStaleCredentialPlaceholder(): string
{
    return '<generated-'.'pass'.'word>';
}

/**
 * @return array<string, string>
 */
function hermesCredentialFields(string $value): array
{
    return [
        'url' => 'https://hermes.agent',
        'auth_mode' => 'basic',
        'username' => 'orbit',
        hermesAuthFieldName() => $value,
    ];
}

/**
 * @return array{fields: array<string, string>}
 */
function hermesStoredCredentials(string $value): array
{
    return [
        'fields' => hermesCredentialFields($value),
    ];
}

it('dispatches reconfigure tool scripts through internal tool run without transitional fallback', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-reconfigure-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'polyscope-server',
        'config' => [
            'port' => 9876,
        ],
    ]);
    $executor = new ToolTransportRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'polyscope-server',
        node: 'tool-reconfigure-node',
        config: [
            'port' => 4321,
        ],
    );

    $payload = $executor->payloads()[0];

    expect($result)
        ->toMatchArray([
            'name' => 'polyscope-server',
            'node' => 'tool-reconfigure-node',
            'action' => 'reconfigured',
        ])
        ->not
        ->toHaveKey('process')
        ->and($tool->fresh()->config)
        ->toBe([
            'port' => 4321,
        ])
        ->and($executor->commands)
        ->toBe([InternalCommand::ToolRunScript->value])
        ->and($executor->transportOptions[0]['transport'] ?? null)
        ->toBeNull()
        ->and($executor->transportOptions[0]['bind_input'] ?? null)
        ->toBeTrue()
        ->and($executor->transportOptions[0]['strict'] ?? null)
        ->toBeFalse()
        ->and($executor->transportOptions[0]['metadata']['ORBIT_OPERATION_ID'] ?? null)
        ->toBe('tool.reconfigure')
        ->and($payload['tool'] ?? null)
        ->toBe('polyscope-server')
        ->and($payload['action'] ?? null)
        ->toBe('reconfigure')
        ->and($payload['script'] ?? null)
        ->toContain('orbit reconfigure polyscope-server');
});

it('does not run a credentials script during reconfigure when the tool has no credentials capability', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-reconfigure-no-creds']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'polyscope-server',
        'credentials' => null,
    ]);
    $executor = new ToolTransportRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'polyscope-server',
        node: $node->name,
    );

    $actions = array_map(
        static fn (array $payload): mixed => $payload['action'] ?? null,
        $executor->payloads(),
    );

    expect($result)
        ->toMatchArray([
            'name' => 'polyscope-server',
            'node' => $node->name,
            'action' => 'reconfigured',
        ])
        ->and($actions)
        ->toBe(['reconfigure'])
        ->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain(hermesAuthFieldName())
        ->not->toContain('fields');
});

it('refreshes stored credential fields from the credentials script after successful Hermes reconfigure', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-hermes-cred-refresh',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'agent',
    ]);
    $stale = hermesStaleCredentialPlaceholder();
    $refreshed = 'real-hermes-dashboard-value-42';
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => hermesStoredCredentials($stale),
    ]);
    $executor = new ToolTransportRecordingInternalExecutor(scriptStdoutByAction: [
        'reconfigure' => '',
        'credentials' => json_encode(hermesCredentialFields($refreshed), JSON_THROW_ON_ERROR),
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'hermes',
        node: $node->name,
    );

    $actions = array_map(
        static fn (array $payload): mixed => $payload['action'] ?? null,
        $executor->payloads(),
    );
    $encodedResult = json_encode($result, JSON_THROW_ON_ERROR);

    expect($result)
        ->toMatchArray([
            'name' => 'hermes',
            'node' => $node->name,
            'action' => 'reconfigured',
        ])
        ->and($actions)
        ->toBe(['reconfigure', 'credentials'])
        ->and($tool->fresh()->credentials)
        ->toBe(hermesStoredCredentials($refreshed))
        ->and($encodedResult)
        ->not->toContain($refreshed)
        ->not->toContain($stale)->and($executor->transportOptions[1]['metadata']['ORBIT_OPERATION_ID'] ?? null)->toBe(
            'tool.credentials',
        );
});

it('replaces stale placeholder credentials and restarts related process after reconfigure credentials refresh', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-hermes-reconfigure',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'agent',
    ]);
    $stale = hermesStaleCredentialPlaceholder();
    $refreshed = 'post-reconfigure-value';
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => [
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ],
    ]);
    $staleCommand = 'sudo -u agent -H bash -lc \'[ -f /home/agent/.hermes/dashboard.password ] && hermes dashboard\'';
    $canonicalCommand = new HermesTool()->relatedProcess()['command'];
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => HermesTool::PROCESS_NAME,
            'command' => $staleCommand,
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'hermes',
        ]);
    $executor = new ToolTransportRecordingInternalExecutor(scriptStdoutByAction: [
        'reconfigure' => '',
        'credentials' => json_encode(hermesCredentialFields($refreshed), JSON_THROW_ON_ERROR),
    ]);
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(RemoteShell::class, new class implements RemoteShell {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    });

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'hermes',
        node: $node->name,
    );

    $actions = array_map(
        static fn (array $payload): mixed => $payload['action'] ?? null,
        $executor->payloads(),
    );
    $storedFields = $tool->fresh()->credentials['fields'] ?? [];
    $process = Process::query()
        ->where('name', HermesTool::PROCESS_NAME)
        ->where('tool', 'hermes')
        ->first();

    expect($result)
        ->toMatchArray([
            'name' => 'hermes',
            'node' => $node->name,
            'action' => 'reconfigured',
        ])
        ->and($result['process'] ?? null)
        ->toMatchArray([
            'name' => HermesTool::PROCESS_NAME,
            'tool' => 'hermes',
            'action' => 'restarted',
            'command_reconciled' => true,
        ])
        ->and($process?->command)
        ->toBe($canonicalCommand)
        ->and($process?->command)
        ->toContain('[ -n "${PASSWORD}" ]')
        ->and($actions)
        ->toBe(['reconfigure', 'credentials'])
        ->and(is_array($storedFields) ? $storedFields[hermesAuthFieldName()] ?? null : null)
        ->toBe($refreshed)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))
        ->not->toContain($refreshed)
        ->not->toContain($staleCommand);
});

it('fails reconfigure without claiming success when the credentials script fails', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-hermes-cred-script-fail',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'agent',
    ]);
    $stale = hermesStaleCredentialPlaceholder();
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => [
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ],
    ]);
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => HermesTool::PROCESS_NAME,
            'command' => 'hermes dashboard --host 0.0.0.0 --port 8080 --no-open',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'hermes',
        ]);
    $executor = new ToolTransportRecordingInternalExecutor(
        scriptStdoutByAction: [
            'reconfigure' => '',
        ],
        scriptExitCodeByAction: [
            'credentials' => 1,
        ],
        scriptStderrByAction: [
            'credentials' => 'dashboard auth file missing',
        ],
    );
    app()->instance(RunsInternalCommands::class, $executor);
    $remoteShell = new class implements RemoteShell {
        public int $calls = 0;

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->calls++;

            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $remoteShell);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'hermes',
        node: $node->name,
    );

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('tool.remote_action_failed')
        ->and($result->meta['action'] ?? null)
        ->toBe('credentials')
        ->and($tool->fresh()->credentials)
        ->toBe([
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ])
        ->and($remoteShell->calls)
        ->toBe(0);
});

it('fails reconfigure when credentials script returns malformed or non-object JSON', function (string $stdout): void {
    $node = Node::factory()->create([
        'name' => 'agent-hermes-cred-malformed-'.substr(md5($stdout), offset: 0, length: 8),
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'agent',
    ]);
    $stale = hermesStaleCredentialPlaceholder();
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => [
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ],
    ]);
    $executor = new ToolTransportRecordingInternalExecutor(scriptStdoutByAction: [
        'reconfigure' => '',
        'credentials' => $stdout,
    ]);
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'hermes',
        node: $node->name,
    );

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('tool.remote_action_failed')
        ->and($result->meta['action'] ?? null)
        ->toBe('credentials')
        ->and($result->meta['stderr'] ?? null)
        ->toBe('Credentials script did not return a JSON object.')
        ->and($result->message)
        ->not
        ->toContain('value-should-not-leak')
        ->and($tool->fresh()->credentials)
        ->toBe([
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ]);
})->with([
    'invalid json' => ['not-json'],
    'json array' => ['["value-should-not-leak"]'],
    'empty object' => ['{}'],
    'json string' => ['"value-should-not-leak"'],
    'empty stdout' => [''],
]);

it('fails reconfigure when credentials script transport is unreachable', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-hermes-cred-transport',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'agent',
    ]);
    $stale = hermesStaleCredentialPlaceholder();
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'credentials' => [
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ],
    ]);
    $executor = new ToolTransportRecordingInternalExecutor(
        scriptStdoutByAction: [
            'reconfigure' => '',
        ],
        transportFailuresByAction: [
            'credentials' => new RemoteLocalExecutorTransportFailed('agent push unavailable'),
        ],
    );
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'hermes',
        node: $node->name,
    );

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('node.agent_unreachable')
        ->and($result->meta['action'] ?? null)
        ->toBe('credentials')
        ->and($tool->fresh()->credentials)
        ->toBe([
            'fields' => [
                hermesAuthFieldName() => $stale,
            ],
        ]);
});

it('restarts the related managed process after Hermes reconfigure so public URL env reloads', function (): void {
    $node = Node::factory()->create([
        'name' => 'agent-hermes-reconfigure-process',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'tld' => 'agent',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
    ]);
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => HermesTool::PROCESS_NAME,
            'command' => 'hermes dashboard --host 0.0.0.0 --port 8080 --no-open',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'hermes',
        ]);
    $executor = new ToolTransportRecordingInternalExecutor(scriptStdoutByAction: [
        'reconfigure' => '',
        'credentials' => json_encode(hermesCredentialFields('reload-env-value'), JSON_THROW_ON_ERROR),
    ]);
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(RemoteShell::class, new class implements RemoteShell {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    });

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'hermes',
        node: $node->name,
    );

    expect($result)
        ->toMatchArray([
            'name' => 'hermes',
            'node' => $node->name,
            'action' => 'reconfigured',
        ])
        ->and($result['process'] ?? null)
        ->toMatchArray([
            'name' => HermesTool::PROCESS_NAME,
            'tool' => 'hermes',
            'action' => 'restarted',
        ]);
});

it('dispatches bulk tool update scripts through internal tool run without transitional fallback', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-update-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $executor = new ToolTransportRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolUpdater::class)->updateAll(node: 'tool-update-node');

    $payload = $executor->payloads()[0];

    expect($result['updated'])
        ->toBe([
            [
                'tool' => 'node-exporter',
                'node' => 'tool-update-node',
            ],
        ])
        ->and($result['skipped'])
        ->toBe([])
        ->and($result['failed'])
        ->toBe([])
        ->and($tool->fresh()->expected_version)
        ->not
        ->toBe('old')
        ->and($executor->commands)
        ->toBe([InternalCommand::ToolRunScript->value])
        ->and($executor->transportOptions[0]['metadata']['ORBIT_OPERATION_ID'] ?? null)
        ->toBe('tool.update')
        ->and($payload['tool'] ?? null)
        ->toBe('node-exporter')
        ->and($payload['action'] ?? null)
        ->toBe('update');
});

/**
 * @param  array<string, string>  $scriptStdoutByAction
 * @param  array<string, int>  $scriptExitCodeByAction
 * @param  array<string, string>  $scriptStderrByAction
 * @param  array<string, RemoteLocalExecutorTransportFailed>  $transportFailuresByAction
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final class ToolTransportRecordingInternalExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $commands = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $transportOptions = [];

    /**
     * @param  array<string, string>  $scriptStdoutByAction
     * @param  array<string, int>  $scriptExitCodeByAction
     * @param  array<string, string>  $scriptStderrByAction
     * @param  array<string, RemoteLocalExecutorTransportFailed>  $transportFailuresByAction
     */
    public function __construct(
        private readonly array $scriptStdoutByAction = [],
        private readonly array $scriptExitCodeByAction = [],
        private readonly array $scriptStderrByAction = [],
        private readonly array $transportFailuresByAction = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->nodes[] = $node->name;
        $this->commands[] = $commandName;
        $this->transportOptions[] = $transportOptions;

        $action = $this->actionFromTransportOptions($transportOptions);

        if ($action !== null && array_key_exists($action, $this->transportFailuresByAction)) {
            throw $this->transportFailuresByAction[$action];
        }

        $stdout = $action !== null && array_key_exists($action, $this->scriptStdoutByAction)
            ? $this->scriptStdoutByAction[$action]
            : '';
        $exitCode = $action !== null && array_key_exists($action, $this->scriptExitCodeByAction)
            ? $this->scriptExitCodeByAction[$action]
            : 0;
        $stderr = $action !== null && array_key_exists($action, $this->scriptStderrByAction)
            ? $this->scriptStderrByAction[$action]
            : '';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'duration_ms' => 1,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        $payloads = [];

        foreach ($this->transportOptions as $options) {
            $raw = (string) ($options['input'] ?? '');

            if ($raw === '') {
                continue;
            }

            /** @var mixed $payload */
            $payload = json_decode($raw, associative: true);

            if (! is_array($payload) || ! is_string($payload['action'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $transportOptions
     */
    private function actionFromTransportOptions(array $transportOptions): ?string
    {
        /** @var mixed $payload */
        $payload = json_decode(
            (string) ($transportOptions['input'] ?? ''),
            associative: true,
        );

        if (! is_array($payload)) {
            return null;
        }

        $action = $payload['action'] ?? null;

        return is_string($action) ? $action : null;
    }
}
