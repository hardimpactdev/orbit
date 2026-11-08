<?php

declare(strict_types=1);

use App\Commands\Nodes\ShowNodeMetricsCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;

/**
 * Contract tests replay recorded Gateway responses and compare the complete command
 * output with tests/Expected. A fixture change that reaches a command fails here first.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('node metrics contract', function (): void {
    it('selects an omitted Node from the searchable registry or stops before reading metrics', function (string $outcome, array $keys): void {
        $directory = $this->orbitHome.'/pty';
        new Filesystem()->ensureDirectoryExists($directory);
        $body = json_decode(gateway_fixture('nodes/node-metrics/default')['body'], true, flags: JSON_THROW_ON_ERROR);
        $body['data']['node_id'] = 94;
        $body['data']['node_name'] = 'app-prod';
        $configuration = [
            'home' => $directory.'/home',
            'trace' => $directory.'/requests.jsonl',
            'arguments' => ['node:metrics'],
            'prompt' => 'Node',
            'keys' => array_map(base64_encode(...), $keys),
            'columns' => 120,
            'replies' => [
                ['class' => ListNodesRequest::class, 'body' => ['data' => $outcome === 'empty' ? [] : [
                    ['id' => 17, 'name' => 'app-dev', 'roles' => ['app-dev'], 'assignments' => [[
                        'id' => 12, 'role' => 'app-dev', 'status' => 'failed',
                        'failed_step' => 'converge:Require supported Ubuntu', 'error_code' => 'node_role.convergence_failed',
                    ]]],
                    ['id' => 94, 'name' => 'app-prod', 'roles' => ['app-prod'], 'assignments' => [[
                        'id' => 13, 'role' => 'app-prod', 'status' => 'failed',
                        'failed_step' => 'converge:Check the role', 'error_code' => 'node_role.convergence_failed',
                    ]]],
                ]]],
                ['class' => ShowNodeMetricsRequest::class, 'body' => $body],
            ],
        ];
        file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $fixtures = base_path('tests/Fixtures/Console/');
        $process = new Process(['python3', $fixtures.'command-consent-pty.py', PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'], env: ['PAO_DISABLE' => '1'], timeout: 15);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $raw = base64_decode($result['raw'], strict: true);

        expect($result['status'])->toBe($outcome === 'selection' ? 0 : 1, $raw)
            ->and($result['restored'])->toBeTrue()
            ->and($result['keys_remaining'])->toBe(0);

        if ($outcome === 'empty') {
            expect($raw)->toContain('No matching records were found.')->not->toContain('/ Search');
        } else {
            expect($raw)->toContain('Node', 'ID', 'Name', 'app-dev', 'app-prod', '/ Search');
        }

        $requests = array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($directory.'/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
        expect(array_column($requests, 'class'))->toBe($outcome === 'selection'
            ? [ListNodesRequest::class, ShowNodeMetricsRequest::class]
            : [ListNodesRequest::class]);

        if ($outcome === 'selection') {
            expect($requests[1]['url'])->toBe('https://fixture.invalid/api/v1/nodes/94/metrics')
                ->and($raw)->toContain('Node app-prod metrics');
        } elseif ($outcome === 'cancel') {
            expect($raw)->toContain('Input cancelled.')->not->toContain('Node app-prod metrics');
        }
    })->with([
        'arrow selection' => ['selection', [Key::DOWN, Key::ENTER]],
        'search selection' => ['selection', ['p', 'r', 'o', 'd', Key::ENTER]],
        'cancel Ctrl-C' => ['cancel', [Key::CTRL_C]],
        'cancel Ctrl-D' => ['cancel', [Key::CTRL_D]],
        'empty registry' => ['empty', []],
    ]);

    it('renders node:metrics from the recorded response', function (): void {
        run_contract('nodes/node-metrics/default', 'node:metrics', ['node' => '1'], 'nodes/node-metrics/default.human.txt', 0);
        run_contract('nodes/node-metrics/default', 'node:metrics', ['node' => '1', '--json' => true], 'nodes/node-metrics/default.json', 0);
    });

    it('resolves a failed Node by name before looking up metrics with its numeric ID', function (string $label): void {
        $assignment = [
            'id' => 12,
            'role' => 'app-dev',
            'status' => 'failed',
            'failed_step' => $label,
            'error_code' => 'node_role.convergence_failed',
        ];
        $body = json_decode(gateway_fixture('nodes/node-metrics/default')['body'], true, flags: JSON_THROW_ON_ERROR);
        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [[
                    'id' => 1,
                    'name' => 'app-dev',
                    'status' => 'active',
                    'roles' => ['app-dev'],
                    'assignments' => [$assignment],
                    'failed_step' => $label,
                    'error_code' => 'node_role.convergence_failed',
                ]],
                'meta' => $body['meta'],
            ]),
            ShowNodeMetricsRequest::class => MockResponse::make($body),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $nodes = $connector->send(new ListNodesRequest)->dtoOrFail();
        expect($nodes)->toBeInstanceOf(NodesResponse::class)
            ->and($nodes->nodes[0]->failedStep)->toBe($label)
            ->and($nodes->nodes[0]->roles)->toBe(['app-dev'])
            ->and($nodes->nodes[0]->assignments[0]->failedStep)->toBe($label)
            ->and($nodes->nodes[0]->toArray()['assignments'])->toBe([$assignment]);

        // Exercise the inherited resolver without changing metrics' numeric-only input contract.
        $nodeId = new ReflectionMethod(ShowNodeMetricsCommand::class, 'resolveNodeId')->invoke(
            app(ShowNodeMetricsCommand::class), $connector, 'app-dev', $nodes,
        );
        expect($nodeId)->toBe(1);
        expect(Artisan::call('node:metrics', ['node' => (string) $nodeId, '--json' => true]))->toBe(0);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['node_id'])->toBe(1)
            ->and($output['node_name'])->toBe('app-dev')
            ->and($output['observed_at'])->toBe($body['data']['observed_at']);
        $mock->assertSentCount(1, ListNodesRequest::class);
        $mock->assertSentCount(1, ShowNodeMetricsRequest::class);
        expect($mock->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/nodes/1/metrics');
    })->with([
        'owner reproduction' => ['converge:Require supported Ubuntu'],
        'role-list reproduction' => ['converge:Check the role'],
    ]);

    it('refuses invalid and omitted IDs locally without looking up names or contacting the Gateway', function (?string $id, bool $json): void {
        $mock = MockClient::global([]);
        $arguments = ['--json' => $json, '--no-interaction' => true];
        if ($id !== null) {
            $arguments['node'] = $id;
        }
        expect(Artisan::call('node:metrics', $arguments))->toBe(1);
        expect(Artisan::output())->toContain('node.id_invalid');
        $mock->assertNothingSent();
    })->with([null, '0', '-1', '999999999999999999999999999999', 'not-a-node-id', '1.5', '--not-a-node-id'])->with([true, false]);

    it('shows retained snapshots with their observation time and shared agent wording', function (?array $agent, string $label): void {
        $body = json_decode(gateway_fixture('nodes/node-metrics/default')['body'], true, flags: JSON_THROW_ON_ERROR);
        $body['data']['agent'] = $agent;
        $body['data']['possibly_outdated'] = true;
        MockClient::global([ShowNodeMetricsRequest::class => MockResponse::make($body)]);
        expect(Artisan::call('node:metrics', ['node' => '1']))->toBe(0);
        expect(Artisan::output())->toContain('Node app-dev metrics', 'Observed at', '2026-11-06T10:00:00Z', $label, 'possibly outdated');
        expect(Artisan::call('node:metrics', ['node' => '1', '--json' => true]))->toBe(0);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['observed_at'])->toBe('2026-11-06T10:00:00Z')
            ->and($output['agent'])->toBe($agent)->and($output['possibly_outdated'])->toBeTrue();
    })->with([
        'offline' => [['state' => 'offline', 'since' => '2026-11-06T10:00:10Z'], 'offline since 2026-11-06T10:00:10Z'],
        'not seen' => [['state' => 'not_seen', 'since' => null], 'not seen'],
        'unknown' => [['state' => 'unknown', 'since' => null], 'unknown'],
        'absent' => [null, '—'],
    ]);

    it('renders snapshot and authority refusals without inventing readings', function (string $code, int $status): void {
        MockClient::global([ShowNodeMetricsRequest::class => MockResponse::make([
            'error' => ['code' => $code, 'message' => 'Metrics read refused.'],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ], $status)]);
        expect(Artisan::call('node:metrics', ['node' => '1', '--json' => true]))->toBe(1);
        expect(Artisan::output())->toContain($code)->not->toContain('uptime_seconds');
    })->with([['node.metrics_unavailable', 422], ['http.404', 404], ['node_access.required', 403]]);
});
