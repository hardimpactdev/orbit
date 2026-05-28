<?php

declare(strict_types=1);

use App\Console\Commands\AppExecCommand;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Http\Gateway\Requests\Apps\AppExecRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => true]);
});

afterEach(function (): void {
    // Clear ORBIT_HOST_CWD env between tests so cwd resolution doesn't bleed.
    unset($_SERVER['ORBIT_HOST_CWD'], $_ENV['ORBIT_HOST_CWD']);
    putenv('ORBIT_HOST_CWD');
    MockClient::destroyGlobal();
});

function createExecCommandApp(array $overrides = []): App
{
    $node = Node::factory()->create(['name' => 'app-1', 'tld' => 'test']);

    return App::factory()->for($node, 'node')->create(array_merge([
        'name' => 'docs',
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
        'php_version' => '8.5',
        'runtime_kind' => AppRuntimeKind::Php,
    ], $overrides));
}

function preflightRunningResult(): RemoteShellResult
{
    return new RemoteShellResult(exitCode: 0, stdout: "true\n", stderr: '', durationMs: 1);
}

/**
 * @param  list<RemoteShellResult>  $results  Sequence of exec-call results;
 *                                            a preflight "running" result is
 *                                            prepended automatically before
 *                                            every exec call so tests only
 *                                            specify the actual exec outcomes.
 */
function bindAppExecShell(array $results = []): void
{
    $scripted = [];

    foreach ($results as $result) {
        $scripted[] = preflightRunningResult();
        $scripted[] = $result;
    }

    app()->instance(RemoteShell::class, new class($scripted) implements RemoteShell
    {
        /** @var list<array{node: string, script: string}> */
        public array $calls = [];

        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            $this->calls[] = ['node' => $node->name, 'script' => $script];

            $next = array_shift($this->results);

            if ($next instanceof RemoteShellResult) {
                return $next;
            }

            // Default for unscripted calls: a successful preflight when the
            // script is an inspect, and a successful exec otherwise. Tests that
            // care about the exact second call should script it explicitly.
            if (str_contains($script, 'docker container inspect')) {
                return preflightRunningResult();
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: 'PHP 8.5.0 (cli)',
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

/**
 * Bind a shell where the FIRST call (preflight) returns the supplied result,
 * exercising preflight-failure error paths. Tests that need to assert
 * preflight-specific behavior (no such container, daemon down, ssh
 * unreachable) use this variant so the canned result is consumed by the
 * preflight call and the user command never runs.
 */
function bindAppExecPreflightShell(RemoteShellResult $preflight): void
{
    app()->instance(RemoteShell::class, new class([$preflight]) implements RemoteShell
    {
        public function __construct(public array $results) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: "true\n",
                stderr: '',
                durationMs: 1,
            );
        }
    });
}

describe('app:exec command', function (): void {
    it('runs a command inside the app runtime container and returns the JSON envelope', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toMatchArray([
                'app' => 'docs',
                'container' => 'orbit-app-docs',
                'command' => ['php', '-v'],
                'exit_code' => 0,
                'stdout' => "PHP 8.5.0\n",
                'stderr' => '',
            ]);
    });

    it('reports the underlying exit code in success.data.exit_code without failing the wrapper', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(exitCode: 2, stdout: '', stderr: "boom\n", durationMs: 1),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', 'artisan', 'migrate'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['exit_code'])->toBe(2)
            ->and($payload['success']['data']['stderr'])->toBe("boom\n");
    });

    it('passes the underlying exit code through to the caller in human mode', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(exitCode: 3, stdout: "out\n", stderr: "err\n", durationMs: 1),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', 'artisan', 'test'],
        ]);

        expect($exitCode)->toBe(3);
    });

    it('rejects an empty command list with validation_failed', function (): void {
        createExecCommandApp();
        bindAppExecShell();

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => [],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('command');
    });

    it('returns app.not_found for an unknown selector', function (): void {
        createExecCommandApp();
        bindAppExecShell();

        $exitCode = Artisan::call('app:exec', [
            'app' => 'missing',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.not_found');
    });

    it('refuses to exec into a static app with app.exec_unsupported_runtime', function (): void {
        createExecCommandApp(['runtime_kind' => AppRuntimeKind::Static]);
        bindAppExecShell();

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_unsupported_runtime')
            ->and($payload['error']['meta']['runtime_kind'])->toBe('static');
    });

    it('returns app.exec_container_not_running when preflight reports no such container', function (): void {
        createExecCommandApp();
        bindAppExecPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: 'Error response from daemon: No such container: orbit-app-docs',
            durationMs: 1,
        ));

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_container_not_running')
            ->and($payload['error']['meta']['container'])->toBe('orbit-app-docs')
            ->and($payload['error']['meta']['node'])->toBe('app-1');
    });

    it('returns app.exec_container_not_running when preflight reports the container is stopped', function (): void {
        createExecCommandApp();
        bindAppExecPreflightShell(new RemoteShellResult(
            exitCode: 0,
            stdout: "false\n",
            stderr: '',
            durationMs: 1,
        ));

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_container_not_running')
            ->and($payload['error']['meta']['state'])->toBe('false');
    });

    it('returns app.exec_docker_unavailable when preflight reports the docker daemon is down', function (): void {
        createExecCommandApp();
        bindAppExecPreflightShell(new RemoteShellResult(
            exitCode: 1,
            stdout: '',
            stderr: "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?\n",
            durationMs: 1,
        ));

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_docker_unavailable')
            ->and($payload['error']['meta']['node'])->toBe('app-1');
    });

    it('returns app.exec_node_unreachable when preflight returns a generic SSH-level failure', function (): void {
        createExecCommandApp();
        bindAppExecPreflightShell(new RemoteShellResult(
            exitCode: 255,
            stdout: '',
            stderr: "ssh: connect to host 10.6.0.7 port 22: Connection refused\n",
            durationMs: 1,
        ));

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_node_unreachable')
            ->and($payload['error']['meta']['node'])->toBe('app-1')
            ->and($payload['error']['meta']['exit_code'])->toBe(255);
    });

    it('returns app.exec_container_not_running when the exec call hits a vanished container after preflight', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(
                exitCode: 125,
                stdout: '',
                stderr: 'Error response from daemon: Container orbit-app-docs is not running',
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_container_not_running');
    });

    it('treats a non-zero exec exit that does not match docker wrapper patterns as a child failure, not infra failure', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(
                exitCode: 7,
                stdout: '',
                stderr: "PHP Fatal error in user code\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', 'artisan', 'migrate'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['exit_code'])->toBe(7)
            ->and($payload['success']['data']['stderr'])->toBe("PHP Fatal error in user code\n");
    });

    it('resolves the app from ORBIT_HOST_CWD when the selector argument is omitted', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        putenv('ORBIT_HOST_CWD=/home/orbit/apps/docs/public');

        $exitCode = Artisan::call('app:exec', [
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app'])->toBe('docs');
    });

    it('fails with validation_failed when no selector is provided and cwd does not match', function (): void {
        createExecCommandApp();
        bindAppExecShell();

        putenv('ORBIT_HOST_CWD=/tmp/unrelated');

        $exitCode = Artisan::call('app:exec', [
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('app');
    });

    it('returns gateway_unavailable when a control-mode caller cannot reach the gateway exec endpoint', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            AppExecRequest::class => MockResponse::make([], 500),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('forwards a successful gateway exec response back to the caller in control mode', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            AppExecRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'app' => 'docs',
                        'container' => 'orbit-app-docs',
                        'command' => ['php', '-v'],
                        'exit_code' => 0,
                        'stdout' => "PHP 8.5.0\n",
                        'stderr' => '',
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app'])->toBe('docs')
            ->and($payload['success']['data']['exit_code'])->toBe(0);
    });

    it('resolves by exact app name first; a domain collision does not redirect resolution', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'tld' => 'test']);

        App::factory()->for($node, 'node')->create([
            'name' => 'alpha',
            'domain' => 'docs.test',
            'path' => '/home/orbit/apps/alpha',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        App::factory()->for($node, 'node')->create([
            'name' => 'docs.test',
            'domain' => 'other.test',
            'path' => '/home/orbit/apps/docs.test',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        bindAppExecShell([
            new RemoteShellResult(exitCode: 0, stdout: "PHP 8.5.0\n", stderr: '', durationMs: 1),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs.test',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['app'])->toBe('docs.test');
    });

    it('treats child stderr that mentions Docker daemon as a child failure rather than infra failure when exit code is not 125', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(
                exitCode: 7,
                stdout: '',
                stderr: "Cannot connect to the Docker daemon (printed by the user app, not the wrapper)\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', 'artisan', 'check-docker'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        // Wrapper succeeded (0); child reported exit 7 with daemon-down
        // stderr. The classifier must NOT promote this to
        // app.exec_docker_unavailable because docker's exit code (7) is not
        // its wrapper-failure code (125).
        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['exit_code'])->toBe(7)
            ->and($payload['success']['data']['stderr'])->toContain('Cannot connect to the Docker daemon');
    });

    it('forwards the raw ORBIT_HOST_CWD through the gateway typed request without touching local App rows in control mode', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $captured = [];
        MockClient::global([
            AppExecRequest::class => function ($pending) use (&$captured) {
                $captured = [
                    'endpoint' => $pending->getRequest()->resolveEndpoint(),
                    'body' => $pending->getRequest()->body()->all(),
                ];

                return MockResponse::make([
                    'success' => [
                        'data' => [
                            'app' => 'docs',
                            'container' => 'orbit-app-docs',
                            'command' => ['php', '-v'],
                            'exit_code' => 0,
                            'stdout' => "PHP\n",
                            'stderr' => '',
                        ],
                    ],
                ], 200);
            },
        ]);

        putenv('ORBIT_HOST_CWD=/home/orbit/apps/docs/public');

        $exitCode = Artisan::call('app:exec', [
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);

        // Local App table is empty — proves the CLI did not try to resolve
        // gateway-owned state locally. The Saloon request body carries the
        // raw host cwd.
        expect($exitCode)->toBe(0)
            ->and(App::query()->count())->toBe(0)
            ->and($captured['endpoint'])->toBe('/api/apps/exec/by-path')
            ->and($captured['body']['host_cwd'])->toBe('/home/orbit/apps/docs/public')
            ->and($captured['body']['command'])->toBe(['php', '-v']);
    });

    it('forwards an explicit selector through the gateway request in control mode', function (): void {
        config(['orbit.is_gateway' => false]);
        Node::factory()->create(['name' => 'control-1']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $captured = [];
        MockClient::global([
            AppExecRequest::class => function ($pending) use (&$captured) {
                $captured = [
                    'endpoint' => $pending->getRequest()->resolveEndpoint(),
                    'body' => $pending->getRequest()->body()->all(),
                ];

                return MockResponse::make([
                    'success' => [
                        'data' => [
                            'app' => 'docs',
                            'container' => 'orbit-app-docs',
                            'command' => ['php', '-v'],
                            'exit_code' => 0,
                            'stdout' => '',
                            'stderr' => '',
                        ],
                    ],
                ], 200);
            },
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['php', '-v'],
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($captured['endpoint'])->toBe('/api/apps/docs/exec')
            ->and(array_key_exists('host_cwd', $captured['body']))->toBeFalse();
    });

    it('reports app.exec_command_not_executable when docker exec returns exit code 126', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(
                exitCode: 126,
                stdout: '',
                stderr: "OCI runtime exec failed: exec failed: unable to start container process: exec: \"./missing-binary\": permission denied\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['./missing-binary'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_command_not_executable')
            ->and($payload['error']['meta']['exit_code'])->toBe(126);
    });

    it('routes child stdout and stderr to separate streams in human mode', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "stdout payload\n",
                stderr: "stderr payload\n",
                durationMs: 1,
            ),
        ]);

        $command = app(AppExecCommand::class);
        $command->setLaravel(app());

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([
            'app' => 'docs',
            'cmd' => ['php', '-v'],
        ], [
            'capture_stderr_separately' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($tester->getDisplay())->toBe("stdout payload\n")
            ->and($tester->getErrorOutput())->toBe("stderr payload\n");
    });

    it('reports app.exec_command_not_found when docker exec returns exit code 127', function (): void {
        createExecCommandApp();
        bindAppExecShell([
            new RemoteShellResult(
                exitCode: 127,
                stdout: '',
                stderr: "OCI runtime exec failed: exec failed: unable to start container process: exec: \"nonsuchcmd\": executable file not found in \$PATH\n",
                durationMs: 1,
            ),
        ]);

        $exitCode = Artisan::call('app:exec', [
            'app' => 'docs',
            'cmd' => ['nonsuchcmd'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('app.exec_command_not_found')
            ->and($payload['error']['meta']['exit_code'])->toBe(127);
    });
});
