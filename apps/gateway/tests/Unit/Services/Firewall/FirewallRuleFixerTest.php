<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Firewall\FirewallRuleFixer;
use App\Services\Firewall\RemoteFirewallRule;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );
});

describe('FirewallRuleFixer', function (): void {
    it('re-applies missing firewall rules from gateway intent', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.73:9477/v1/commands' => Http::sequence()
                ->push(firewall_rule_probe_agent_payload(<<<'UFW'
                    Status: active

                         To                         Action      From
                         --                         ------      ----
                    UFW))
                ->push(firewall_rule_agent_payload('apply')),
        ]);
        $node = Node::factory()
            ->appDev()
            ->orbitAgentCapable()
            ->create([
                'name' => 'app-1',
                'platform' => 'ubuntu',
                'wireguard_address' => '10.44.0.73',
            ]);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
            'reason' => 'local development',
        ]);
        $shell = new FirewallFixerRecordingRemoteShell([]);

        $action = new FirewallRuleFixer($shell, firewall_rule_remote())->fix($rule, new DriftEntry(
            family: 'firewall_rule',
            key: 'firewall_rule.rule_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));

        expect($action)
            ->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'status' => 'completed',
            ])
            ->and($shell->scripts)
            ->toBeEmpty();

        $requests = firewall_rule_agent_requests();

        expect($requests)
            ->toHaveCount(1)
            ->and($requests[0]['action'])
            ->toBe('apply')
            ->and($requests[0]['shape'])
            ->toMatchArray([
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => '10.6.0.0/24',
                'destination' => null,
                'port' => '5173',
                'protocol' => 'tcp',
                'address_family' => 'v4',
                'interface' => null,
                'reason' => 'local development',
            ]);
    });

    it('deletes mismatched observed rules before re-applying intent', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.73:9477/v1/commands' => Http::sequence()
                ->push(firewall_rule_probe_agent_payload(<<<'UFW'
                    Status: active

                         To                         Action      From
                         --                         ------      ----
                    [ 1] 5173/tcp                   ALLOW IN    Anywhere

                    UFW))
                ->push(firewall_rule_agent_payload('delete'))
                ->push(firewall_rule_agent_payload('apply')),
        ]);
        $node = Node::factory()
            ->appDev()
            ->orbitAgentCapable()
            ->create([
                'name' => 'app-1',
                'platform' => 'ubuntu',
                'wireguard_address' => '10.44.0.73',
            ]);
        $rule = FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        $shell = new FirewallFixerRecordingRemoteShell([]);

        new FirewallRuleFixer($shell, firewall_rule_remote())->fix($rule, new DriftEntry(
            family: 'firewall_rule',
            key: 'firewall_rule.rule_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
            detail: [
                'observed' => [
                    'direction' => 'incoming',
                    'action' => 'allow',
                    'source' => 'any',
                    'destination' => null,
                    'port' => '5173',
                    'protocol' => 'tcp',
                ],
            ],
        ));

        $requests = firewall_rule_agent_requests();

        expect($shell->scripts)
            ->toBeEmpty()
            ->and(array_column($requests, 'action'))
            ->toBe(['delete', 'apply'])
            ->and($requests[0]['shape'])
            ->toMatchArray([
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => 'any',
                'destination' => null,
                'port' => '5173',
                'protocol' => 'tcp',
            ])
            ->and($requests[1]['shape']['source'])
            ->toBe('10.6.0.0/24');
    });
});

function firewall_rule_remote(): RemoteFirewallRule
{
    return new RemoteFirewallRule(new RemoteLocalExecutor(
        transport: new class implements RemoteExecutor {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                throw new RuntimeException('SSH transport should not be called for firewall rule mutations.');
            }

            public function start(Node $node, string $script, array $options = []): InvokedProcess
            {
                throw new RuntimeException('Firewall rule mutation tests do not start long-running transports.');
            }
        },
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: firewall_rule_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: firewall_rule_operation_secret(),
    ));
}

function firewall_rule_agent_response(string $action): mixed
{
    return Http::response(firewall_rule_agent_payload($action));
}

/**
 * @return array<string, mixed>
 */
function firewall_rule_probe_agent_payload(string $output): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'firewall.rule.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'output' => $output,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function firewall_rule_agent_payload(string $action): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => "firewall.rule.{$action}",
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'action' => $action,
                            'changed' => true,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

/**
 * @return list<array{action: string, shape: array<string, mixed>}>
 */
function firewall_rule_agent_requests(): array
{
    return collect(Http::recorded())
        ->map(function (array $record): ?array {
            /** @var Request $request */
            $request = $record[0];
            $argv = $request['argv'];

            if (($argv[0] ?? null) !== 'internal:firewall-rule') {
                return null;
            }

            $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

            expect($request->url())
                ->toBe('http://10.44.0.73:9477/v1/commands')
                ->and($request['binary'])
                ->toBe('orbit')
                ->and($argv[0] ?? null)
                ->toBe('internal:firewall-rule')
                ->and(str_starts_with((string) ($argv[1] ?? ''), '--operation-token='))
                ->toBeTrue()
                ->and($argv[2] ?? null)
                ->toBe('--json')
                ->and($request['stream'])
                ->toBeTrue();

            if (! is_array($input) || ! is_string($input['action'] ?? null) || ! is_array($input['shape'] ?? null)) {
                return null;
            }

            return [
                'action' => $input['action'],
                'shape' => $input['shape'],
            ];
        })
        ->filter()
        ->values()
        ->all();
}

function firewall_rule_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

final class FirewallFixerRecordingRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
