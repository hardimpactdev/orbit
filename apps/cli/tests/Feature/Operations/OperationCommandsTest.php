<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\DeployInstanceRequest;
use Orbit\Sdk\Requests\Deployments\RollbackInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Operations\CancelOperationRequest;
use Orbit\Sdk\Requests\Operations\ListOperationsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Tasks\CheckTaskRequest;
use Orbit\Sdk\Requests\Tasks\PublishTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\StartTaskRequest;
use Orbit\Sdk\Requests\Tasks\SyncTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\TurnTaskRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    MockClient::destroyGlobal();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-operations-'.Str::uuid();
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
    Sleep::fake(false);
    new Filesystem()->deleteDirectory($this->orbitHome);
});

const OPERATION_REQUEST_ID = '0198e15c-bf97-7c23-8f1f-61b8fe67a846';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cli_operation(array $overrides = []): array
{
    return [
        'id' => 42,
        'kind' => 'instance.deploy',
        'target_type' => 'instance',
        'target_id' => 17,
        'input' => [],
        'status' => 'queued',
        'sequence' => 1,
        'current_step' => null,
        'failed_step' => null,
        'error_code' => null,
        'result' => null,
        'cancel_requested' => false,
        'attempts' => 0,
        'triggered_by' => 'laptop',
        'created_at' => '2026-10-15T10:00:00+00:00',
        'started_at' => null,
        'finished_at' => null,
        'poll_after_seconds' => 2,
        ...$overrides,
    ];
}

/** @param array<string, mixed> $data */
function cli_operation_response(array $data, int $status = 200): MockResponse
{
    return MockResponse::make(['data' => $data, 'meta' => ['request_id' => OPERATION_REQUEST_ID]], $status, ['X-Orbit-Request-Id' => OPERATION_REQUEST_ID]);
}

/**
 * A Gateway without Reverb that answers the start request and then each read in turn.
 *
 * @param  list<array<string, mixed>>  $reads
 * @param  list<string>  $seen  the query of each read
 * @param  class-string  $start
 * @param  array<string, mixed>  $started
 */
function cli_deploy_gateway(array $reads, array &$seen, string $start = DeployInstanceRequest::class, array $started = []): MockClient
{
    return MockClient::global([
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit'], 'meta' => ['request_id' => OPERATION_REQUEST_ID]]),
        $start => cli_operation_response(cli_operation($started), 202),
        ShowOperationRequest::class => static function (PendingRequest $request) use (&$reads, &$seen): MockResponse {
            $seen[] = http_build_query($request->query()->all());

            return cli_operation_response(count($reads) > 1 ? array_shift($reads) : $reads[0]);
        },
    ]);
}

describe('instance:deploy', function (): void {
    it('renders the native deployment outcome', function (bool $fails): void {
        $seen = [];
        cli_deploy_gateway([
            cli_operation([
                'status' => $fails ? 'failed' : 'succeeded', 'sequence' => 5,
                'failed_step' => $fails ? 'process_restart' : null,
                'error_code' => $fails ? 'process.restart_failed' : null,
                'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 5, 'release' => 'r2', 'selected_release' => 'r2', 'commit' => null, 'warnings' => 1],
            ]),
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe($fails ? 1 : 0)
            ->and($output)->toContain($fails ? 'Deployment failed.' : 'Deployment succeeded.')
            ->not->toContain('Warning:');
        if ($fails) {
            expect($output)->toContain('process.restart_failed')->not->toContain('Deployment succeeded.');
        }
    })->with([false, true]);

    it('follows the parent Operation until its native result arrives', function (): void {
        $seen = [];
        $done = cli_operation(['status' => 'succeeded', 'sequence' => 9, 'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null,
            'result' => ['deployment_id' => 5, 'release' => 'r2', 'selected_release' => 'r2', 'commit' => null]]);
        cli_deploy_gateway([
            cli_operation(['status' => 'running', 'sequence' => 3, 'started_at' => '2026-10-15T10:00:01+00:00', 'current_step' => 'source_preparation']),
            cli_operation(['status' => 'running', 'sequence' => 6, 'started_at' => '2026-10-15T10:00:01+00:00', 'current_step' => 'before_activation.migrate']),
            $done,
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Deploy Instance [17]', 'Deployment succeeded.', 'Selected release: r2', 'Operation: 42')
            ->and($seen)->toBe(['', '', '']);
    });

    it('warns about a best-effort step that did not exit 0 and still succeeds', function (): void {
        $seen = [];
        cli_deploy_gateway([
            cli_operation(['status' => 'succeeded', 'sequence' => 5, 'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 5, 'commit' => str_repeat('a', 40),
                    'best_effort_steps' => [['name' => 'tia-warm', 'exit_code' => 1], ['name' => 'cache-warm', 'exit_code' => 0]]]]),
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Deployment succeeded.', 'Warning: best-effort step tia-warm exited 1')
            ->not->toContain('cache-warm');
    });

    it('fails with the failed step and error code of the operation', function (): void {
        $seen = [];
        cli_deploy_gateway([
            cli_operation(['status' => 'failed', 'sequence' => 5, 'started_at' => '2026-10-15T10:00:01+00:00', 'failed_step' => 'source_preparation',
                'error_code' => 'deployment.prepare_failed', 'poll_after_seconds' => null]),
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('Deployment failed.', 'Failed step: source_preparation', 'Error code: deployment.prepare_failed');
    });

    it('prints only the final parent Operation in JSON mode', function (): void {
        $seen = [];
        $final = cli_operation(['status' => 'succeeded', 'sequence' => 4, 'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null]);
        cli_deploy_gateway([$final], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--json' => true, '--no-interaction' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['status'])->toBe('succeeded')
            ->and($payload)->not->toHaveKey('steps')
            ->and(end($seen))->toBe('');
    });

    it('answers a busy Instance with the error envelope and follows nothing', function (): void {
        $mock = MockClient::global([
            DeployInstanceRequest::class => MockResponse::make(['error' => [
                'code' => 'operation.target_busy',
                'message' => 'Another operation on this target has not ended.',
                'details' => ['operation_id' => 41],
            ]], 409, ['X-Orbit-Request-Id' => OPERATION_REQUEST_ID]),
        ]);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--json' => true, '--no-interaction' => true]);

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), true)['error']['code'])->toBe('operation.target_busy');
        $mock->assertSentCount(1);
    });
});

describe('instance:rollback', function (): void {
    it('starts the operation with only the release and follows it until it ends', function (): void {
        $seen = [];
        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-a']];
        $mock = cli_deploy_gateway([
            cli_operation([...$rollback, 'status' => 'running', 'sequence' => 3, 'started_at' => '2026-10-16T10:00:01+00:00', 'current_step' => 'activation']),
            cli_operation([...$rollback, 'status' => 'succeeded', 'sequence' => 6, 'started_at' => '2026-10-16T10:00:01+00:00', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 7, 'release' => 'release-a', 'selected_release' => 'release-a', 'commit' => null]]),
        ], $seen, RollbackInstanceRequest::class, $rollback);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Roll back Instance [17]', 'Rollback succeeded.', 'Selected release: release-a', 'Operation: 43')
            ->and($seen)->toBe(['', '']);
        $mock->assertSentCount(1, RollbackInstanceRequest::class);
        expect($mock->findResponseByRequest(RollbackInstanceRequest::class)?->getPendingRequest()->body()?->all())->toBe(['release' => 'release-a']);
    });

    it('fails with the failed step and error code of the operation', function (): void {
        $seen = [];
        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-x']];
        cli_deploy_gateway([
            cli_operation([...$rollback, 'status' => 'failed', 'sequence' => 4, 'started_at' => '2026-10-16T10:00:01+00:00', 'failed_step' => 'rollback',
                'error_code' => 'rollback.release_invalid', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 7, 'release' => null, 'selected_release' => 'release-b', 'commit' => null]]),
        ], $seen, RollbackInstanceRequest::class, $rollback);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-x', '--no-interaction' => true]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('Rollback failed.', 'Failed step: rollback', 'Error code: rollback.release_invalid', 'Selected release: release-b');
    });

    it('prints only the final operation in JSON mode', function (): void {
        $seen = [];
        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-a']];
        cli_deploy_gateway([
            cli_operation([...$rollback, 'status' => 'succeeded', 'sequence' => 4, 'started_at' => '2026-10-16T10:00:01+00:00', 'poll_after_seconds' => null]),
        ], $seen, RollbackInstanceRequest::class, $rollback);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--json' => true, '--no-interaction' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['kind'])->toBe('instance.rollback')
            ->and($payload['input'])->toBe(['release' => 'release-a'])
            ->and($payload)->not->toHaveKey('steps')
            ->and(end($seen))->toBe('');
    });

    it('answers an Instance that a running deploy holds with the error envelope and follows nothing', function (): void {
        $mock = MockClient::global([
            RollbackInstanceRequest::class => MockResponse::make(['error' => [
                'code' => 'operation.target_busy',
                'message' => 'Another operation on this target has not ended.',
                'details' => ['operation_id' => 41],
            ]], 409, ['X-Orbit-Request-Id' => OPERATION_REQUEST_ID]),
        ]);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--json' => true, '--no-interaction' => true]);
        $error = json_decode(trim(Artisan::output()), true)['error'];

        expect($exitCode)->toBe(1)
            ->and($error['code'])->toBe('operation.target_busy')
            ->and($error['details'])->toBe(['operation_id' => 41]);
        $mock->assertSentCount(1);

        Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--no-interaction' => true]);
        expect(Artisan::output())->toContain('41');
    });

    it('stops following on Ctrl-C, leaves the rollback running, and sends no cancel', function (string $mode): void {
        if (! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-a']];
        $mock = MockClient::global([
            ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit'], 'meta' => ['request_id' => OPERATION_REQUEST_ID]]),
            RollbackInstanceRequest::class => cli_operation_response(cli_operation($rollback), 202),
            ShowOperationRequest::class => static function () use ($rollback): MockResponse {
                // The person presses Ctrl-C while the CLI follows the running rollback.
                posix_kill(getmypid(), SIGINT);

                return cli_operation_response(cli_operation([...$rollback, 'status' => 'running', 'sequence' => 2, 'current_step' => 'rollback']));
            },
        ]);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', $mode => true, '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(130);
        if ($mode === '--json') {
            expect(trim($output))->toBe('');
        } else {
            expect($output)->toContain('The operation keeps running. Follow it with: orbit operation:show 43');
        }
        $mock->assertNotSent(CancelOperationRequest::class);
    })->with(['human' => '--no-ansi', 'JSON' => '--json']);
});

describe('a follow that ends early', function (): void {
    // A signal can end the CLI before any handler runs (the released binary dies on SIGINT): the id must already be on screen.
    it('names the followed Operation before it follows, on stdout for a person and on stderr with --json', function (): void {
        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-a']];
        $ended = cli_operation([...$rollback, 'status' => 'succeeded', 'sequence' => 3, 'poll_after_seconds' => null]);
        MockClient::global([
            ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit'], 'meta' => ['request_id' => OPERATION_REQUEST_ID]]),
            RollbackInstanceRequest::class => cli_operation_response(cli_operation($rollback), 202),
            ShowOperationRequest::class => cli_operation_response($ended),
        ]);
        $line = 'Following operation 43. Read it with: orbit operation:show 43';

        expect(Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--no-ansi' => true, '--no-interaction' => true]))->toBe(0);
        $human = Artisan::output();
        expect($human)->toContain($line)->and(strpos($human, $line))->toBeLessThan((int) strpos($human, 'running...'));

        $output = new class extends BufferedOutput implements ConsoleOutputInterface
        {
            public BufferedOutput $errors;

            public function __construct()
            {
                parent::__construct();
                $this->errors = new BufferedOutput;
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errors;
            }

            public function setErrorOutput(OutputInterface $error): void {}

            public function section(): ConsoleSectionOutput
            {
                throw new LogicException('No sections in this test.');
            }
        };
        expect(Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--json' => true, '--no-interaction' => true], $output))->toBe(0)
            ->and(json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)['id'])->toBe(43)
            ->and($output->errors->fetch())->toBe($line."\n");
    });
});

describe('operation commands', function (): void {
    it('lists operations with filters', function (): void {
        $mock = MockClient::global([
            ListOperationsRequest::class => MockResponse::make(['data' => [cli_operation(['status' => 'running', 'current_step' => 'activation'])], 'meta' => []], 200, ['X-Orbit-Request-Id' => OPERATION_REQUEST_ID]),
        ]);

        $exitCode = Artisan::call('operation:list', ['--status' => 'running', '--instance' => '17', '--no-interaction' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('instance.deploy', 'instance 17', 'running');
        $mock->assertSent(static fn ($request): bool => $request instanceof ListOperationsRequest
            && $request->query()->all() === ['status' => 'running', 'target_type' => 'instance', 'target_id' => 17]);

        expect(Artisan::call('operation:list', ['--status' => 'sleeping', '--json' => true, '--no-interaction' => true]))->toBe(1);
    });

    it('pages the list with --limit and --cursor and names the next cursor', function (): void {
        $mock = MockClient::global([
            ListOperationsRequest::class => MockResponse::make(['data' => [cli_operation(['status' => 'succeeded'])], 'meta' => ['next_cursor' => '42']]),
        ]);

        expect(Artisan::call('operation:list', ['--limit' => '1', '--cursor' => '50', '--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('More operations: --cursor=42');
        $mock->assertSent(static fn ($request): bool => $request instanceof ListOperationsRequest && $request->query()->all() === ['limit' => 1, 'cursor' => '50']);

        expect(Artisan::call('operation:list', ['--json' => true, '--no-interaction' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true))->toMatchArray(['next_cursor' => '42'])
            ->and(Artisan::call('operation:list', ['--limit' => '101', '--no-interaction' => true]))->toBe(1)
            ->and(Artisan::call('operation:list', ['--cursor' => 'next', '--no-interaction' => true]))->toBe(1);
    });

    it('lists waiting operations and passes the filter to the Gateway', function (): void {
        $mock = MockClient::global([
            ListOperationsRequest::class => MockResponse::make(['data' => [cli_operation(['status' => 'waiting', 'current_step' => 'external'])], 'meta' => []]),
        ]);

        expect(Artisan::call('operation:list', ['--status' => 'waiting', '--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('waiting');
        $mock->assertSent(static fn ($request): bool => $request instanceof ListOperationsRequest && $request->query()->all() === ['status' => 'waiting']);
    });

    it('follows a waiting parent until its native result succeeds', function (): void {
        $seen = [];
        cli_deploy_gateway([
            cli_operation(['status' => 'waiting', 'sequence' => 3, 'current_step' => 'source_preparation']),
            cli_operation(['status' => 'waiting', 'sequence' => 5, 'current_step' => 'source_preparation']),
            cli_operation(['status' => 'succeeded', 'sequence' => 7, 'poll_after_seconds' => null]),
        ], $seen);

        expect(Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('Deployment succeeded.')
            ->and(Artisan::output())->not->toContain('Deployment failed.')
            ->and($seen)->toBe(['', '', '']);
    });

    it('shows the parent result without Gateway step output', function (): void {
        MockClient::global([
            ShowOperationRequest::class => cli_operation_response(cli_operation(['status' => 'failed', 'failed_step' => 'before_activation.migrate', 'error_code' => 'deployment.step_failed'])),
        ]);

        $exitCode = Artisan::call('operation:show', ['operation' => '42', '--no-interaction' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('Operation: 42', 'deployment.step_failed')->not->toContain('Output of before_activation.migrate:', 'boom');
    });

    it('asks an operation to stop and says how to follow it', function (): void {
        $mock = MockClient::global([
            CancelOperationRequest::class => cli_operation_response(cli_operation(['status' => 'running', 'cancel_requested' => true])),
        ]);

        $exitCode = Artisan::call('operation:cancel', ['operation' => '42', '--no-interaction' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('Cancel requested', 'orbit operation:show 42');
        $mock->assertSent(static fn ($request): bool => $request instanceof CancelOperationRequest && (string) $request->body() === '{}');
    });
});

describe('task:start', function (): void {
    beforeEach(function (): void {
        MockClient::global(gateway_fixture_mock());
        app(GatewayExtensionState::class)->discover();
        MockClient::destroyGlobal();
    });

    it('waits by default until the copy is active, as instance:create --wait-active does, and prints the completion last', function (string $completion, string $instance, int $exit): void {
        $mock = MockClient::global(clone_create_responses($completion, $instance, StartTaskRequest::class));

        expect(Artisan::call('task:start', ['task' => '17', '--idempotency-key' => 'group.start.c1', '--json' => true, '--no-interaction' => true]))->toBe($exit);
        $output = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect([$output['id'], $output['kind'], $output['status']])->toBe([902, 'instance.complete', $completion])
            ->and($mock->findResponseByRequest(StartTaskRequest::class)?->getPendingRequest()->headers()->get('Idempotency-Key'))->toBe('group.start.c1');
        $mock->assertSentCount(1, StartTaskRequest::class);
        $completion === 'succeeded' ? $mock->assertSent(ShowInstanceRequest::class) : $mock->assertNotSent(ShowInstanceRequest::class);
    })->with([
        'completed and active' => ['succeeded', 'active', 0],
        'completion failed' => ['failed', 'source_resolved', 1],
        'completed but not active' => ['succeeded', 'source_resolved', 1],
    ]);

    it('with --no-wait ends at the copy\'s create and names the completion', function (): void {
        $mock = MockClient::global(clone_create_responses('running', 'source_resolved', StartTaskRequest::class));

        expect(Artisan::call('task:start', ['task' => '17', '--no-wait' => true, '--json' => true, '--no-interaction' => true]))->toBe(0);
        $output = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        expect([$output['kind'], $output['result']['completion_operation_id']])->toBe(['instance.create', 902]);
        $mock->assertNotSent(ShowInstanceRequest::class);

        expect(Artisan::call('task:start', ['task' => '17', '--no-wait' => true, '--no-interaction' => true]))->toBe(0)
            ->and(preg_replace('/\s+/', ' ', Artisan::output()))->toContain('Provision succeeded.', 'Completion: 902 Active when it succeeds: orbit operation:show 902');
    });

    it('returns the error of a failed create and does not wait', function (): void {
        $seen = [];
        $identity = ['kind' => 'instance.create', 'target_type' => 'instance'];
        $mock = cli_deploy_gateway([
            cli_operation([...$identity, 'status' => 'failed', 'sequence' => 5, 'failed_step' => 'clone', 'error_code' => 'instance.clone_failed', 'poll_after_seconds' => null]),
        ], $seen, StartTaskRequest::class, $identity);

        expect(Artisan::call('task:start', ['task' => '17', '--json' => true, '--no-interaction' => true]))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error_code'])->toBe('instance.clone_failed');
        $mock->assertNotSent(ShowInstanceRequest::class);
    });

    it('refuses missing or invalid group input before Gateway access', function (?string $group, string $code): void {
        $mock = MockClient::global([]);
        expect(Artisan::call('task:start', [...($group === null ? [] : ['task' => $group]), '--json' => true, '--no-interaction' => true]))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
        $mock->assertNothingSent();
    })->with([[null, 'task.task_required'], ['bad', 'task.task_invalid']]);
});

describe('task:check', function (): void {
    beforeEach(function (): void {
        MockClient::global(gateway_fixture_mock());
        app(GatewayExtensionState::class)->discover();
        MockClient::destroyGlobal();
    });

    it('follows the check wait and returns its outcome without treating a failed check as an operation failure', function (bool $json, string $outcome): void {
        $seen = [];
        $identity = ['kind' => 'tasks.check', 'target_type' => 'task_group'];
        $result = ['outcome' => $outcome, 'check_id' => 31, 'commit' => $outcome === 'passed' ? str_repeat('c', 40) : null, 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40), 'deliverables' => [], 'output' => 'Check result.'];
        $mock = cli_deploy_gateway([
            cli_operation([...$identity, 'status' => 'waiting', 'sequence' => 4, 'current_step' => 'check']),
            cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 7, 'poll_after_seconds' => null, 'result' => $result]),
        ], $seen, CheckTaskRequest::class, $identity);

        expect(Artisan::call('task:check', ['task' => '21', '--phase' => 'handoff', '--idempotency-key' => 'group.check.c1', '--json' => $json, '--no-interaction' => true]))->toBe(0);
        $output = Artisan::output();
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toBe($result);
        } else {
            expect($output)->toContain('Check task [21]', 'Check: 31', match ($outcome) {
                'passed' => 'Check passed.',
                'failed' => 'Check failed.',
                'changed' => 'Check changed the tree.',
            });
            expect($output)->not->toContain('Check succeeded.');
            if ($outcome === 'passed') {
                expect($output)->toContain('Commit: '.str_repeat('c', 40));
            }
        }
        $mock->assertSentCount(1, CheckTaskRequest::class);
        $pending = $mock->findResponseByRequest(CheckTaskRequest::class)->getPendingRequest();
        expect($pending->body()->all())->toBe(['phase' => 'handoff']);
        expect($pending->headers()->get('Idempotency-Key'))->toBe('group.check.c1');
    })->with([[false, 'passed'], [false, 'failed'], [false, 'changed'], [true, 'passed'], [true, 'failed'], [true, 'changed']]);

    it('refuses missing and invalid inputs before Gateway access', function (array $arguments, string $code): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('task:check', [...$arguments, '--json' => true, '--no-interaction' => true]))->toBe(1);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
        $mock->assertNothingSent();
    })->with([
        [[], 'task.task_required'],
        [['task' => 'bad'], 'task.task_invalid'],
        [['task' => 'bad'], 'task.task_invalid'],
        [['task' => '21'], 'task.phase_required'],
        [['task' => '21', '--phase' => 'bad'], 'task.phase_invalid'],
    ]);
});

it('follows a turn operation and preserves its typed input in JSON', function (): void {
    $seen = [];
    $mock = cli_deploy_gateway([
        cli_operation(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'status' => 'succeeded', 'result' => ['receipt' => ['outcome' => 'ready_for_review'], 'thread' => 9], 'poll_after_seconds' => null]),
    ], $seen, TurnTaskRequest::class, ['kind' => 'tasks.copy_turn', 'target_type' => 'task_group']);

    expect(Artisan::call('task:turn', ['task' => '18', '--role' => 'implementer', '--prompt' => 'Do the work.', '--thread' => 'new', '--idempotency-key' => 'turn.c1', '--json' => true]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['result']['receipt']['outcome'])->toBe('ready_for_review');
    $mock->assertSent(static fn ($request): bool => $request instanceof TurnTaskRequest
        && $request->headers()->get('Idempotency-Key') === 'turn.c1'
        && json_decode((string) $request->body(), true) === ['role' => 'implementer', 'prompt' => 'Do the work.', 'thread' => 'new']);
});

it('sends no thread when --thread is omitted, so a task copy continues the role\'s thread', function (): void {
    $seen = [];
    $mock = cli_deploy_gateway([
        cli_operation(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'status' => 'succeeded', 'result' => ['state' => 'done'], 'poll_after_seconds' => null]),
    ], $seen, TurnTaskRequest::class, ['kind' => 'tasks.copy_turn', 'target_type' => 'task_group']);

    expect(Artisan::call('task:turn', ['task' => '18', '--role' => 'implementer', '--prompt' => 'Fix the failing test.', '--json' => true, '--no-interaction' => true]))->toBe(0);
    $mock->assertSent(static fn ($request): bool => $request instanceof TurnTaskRequest
        && json_decode((string) $request->body(), true) === ['role' => 'implementer', 'prompt' => 'Fix the failing test.']);
});

it('prints the agent thread and the agent\'s reply under the turn\'s outcome, and keeps both in JSON', function (bool $json): void {
    $seen = [];
    $result = ['state' => 'done', 'agent_id' => 9, 'reply' => "Committed d867aba2.\nThe tests pass."];
    cli_deploy_gateway([
        cli_operation(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'status' => 'succeeded', 'result' => $result, 'poll_after_seconds' => null]),
    ], $seen, TurnTaskRequest::class, ['kind' => 'tasks.copy_turn', 'target_type' => 'task_group']);

    expect(Artisan::call('task:turn', ['task' => '18', '--role' => 'implementer', '--prompt' => 'Do the work.', '--json' => $json, '--no-interaction' => true]))->toBe(0);
    $output = Artisan::output();

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toMatchArray(['agent_id' => 9, 'reply' => "Committed d867aba2.\nThe tests pass."]);
    } else {
        expect($output)->toContain("Agent thread: 9\nReply\n  Committed d867aba2.\n  The tests pass.\n")
            ->and(strpos($output, 'Turn succeeded.'))->toBeLessThan((int) strpos($output, 'Agent thread: 9'));
    }
})->with(['human' => false, 'JSON' => true]);

it('prints no reply for a turn whose agent wrote none', function (): void {
    $seen = [];
    cli_deploy_gateway([
        cli_operation(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'status' => 'failed', 'error_code' => 'tasks.copy_turn_failed',
            'result' => ['state' => 'failed', 'agent_id' => 9, 'reply' => null], 'poll_after_seconds' => null]),
    ], $seen, TurnTaskRequest::class, ['kind' => 'tasks.copy_turn', 'target_type' => 'task_group']);

    expect(Artisan::call('task:turn', ['task' => '18', '--role' => 'implementer', '--prompt' => 'Do the work.', '--no-interaction' => true]))->toBe(1);

    expect(Artisan::output())->toContain('Turn failed.', 'Agent thread: 9', 'Reply: none');
});

it('prints no agent lines for a turn whose result names no agent thread', function (): void {
    $seen = [];
    cli_deploy_gateway([
        cli_operation(['kind' => 'tasks.copy_turn', 'target_type' => 'task_group', 'status' => 'failed', 'error_code' => 'swamp.run_failed',
            'result' => ['error_code' => 'swamp.run_failed'], 'poll_after_seconds' => null]),
    ], $seen, TurnTaskRequest::class, ['kind' => 'tasks.copy_turn', 'target_type' => 'task_group']);

    expect(Artisan::call('task:turn', ['task' => '18', '--role' => 'implementer', '--prompt' => 'Do the work.', '--no-interaction' => true]))->toBe(1);

    expect(Artisan::output())->toContain('Turn failed.')->not->toContain('Agent thread:')->not->toContain('Reply');
});

describe('task:publish', function (): void {
    beforeEach(function (): void {
        MockClient::global(gateway_fixture_mock());
        app(GatewayExtensionState::class)->discover();
        MockClient::destroyGlobal();
    });

    it('follows a waiting publish and renders the published branch and pull request', function (bool $json, bool $pull): void {
        $seen = [];
        $identity = ['kind' => 'tasks.publish', 'target_type' => 'task_group'];
        $result = ['branch' => 'task-17', 'commit' => str_repeat('a', 40), 'pull_request_number' => $pull ? 12 : null, 'pull_request_url' => $pull ? 'https://github.com/acme/shop/pull/12' : null];
        $mock = cli_deploy_gateway([
            cli_operation([...$identity, 'status' => 'waiting', 'sequence' => 4, 'current_step' => 'push']),
            cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 7, 'poll_after_seconds' => null, 'result' => $result]),
        ], $seen, PublishTaskGroupRequest::class, $identity);

        expect(Artisan::call('task:publish', ['task' => '17', '--check' => '31', ...($pull ? ['--title' => 'Title', '--body' => 'Body'] : []), '--idempotency-key' => 'group.publish.c1', '--json' => $json, '--no-interaction' => true]))->toBe(0);

        $output = Artisan::output();
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toBe($result);
        } else {
            expect($output)->toContain('Publish task group [17]', 'Branch: task-17', 'Commit: '.str_repeat('a', 40));
            if ($pull) {
                expect($output)->toContain('Pull request: https://github.com/acme/shop/pull/12');
            }
        }
        $mock->assertSentCount(1, PublishTaskGroupRequest::class);
        $pending = $mock->findResponseByRequest(PublishTaskGroupRequest::class)->getPendingRequest();
        expect($pending->body()->all())->toBe($pull ? ['check' => 31, 'pull_request' => ['title' => 'Title', 'body' => 'Body']] : ['check' => 31]);
        expect($pending->headers()->get('Idempotency-Key'))->toBe('group.publish.c1');
    })->with([false, true])->with([false, true]);

    it('refuses missing, invalid, or incomplete publish inputs before Gateway access', function (array $arguments, string $code): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('task:publish', [...$arguments, '--json' => true, '--no-interaction' => true]))->toBe(1);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
        $mock->assertNothingSent();
    })->with([
        [[], 'task.task_required'],
        [['task' => 'bad'], 'task.task_invalid'],
        [['task' => '17', '--title' => 'Title', '--body' => 'Body'], 'task.pull_request_invalid'],
        [['task' => '17', '--check' => 'bad'], 'task.check_invalid'],
        [['task' => '17', '--check' => '31', '--title' => 'Title'], 'task.pull_request_invalid'],
        [['task' => '17', '--check' => '31', '--body' => 'Body'], 'task.pull_request_invalid'],
        [['task' => '17', '--check' => '31', '--draft' => true], 'task.pull_request_invalid'],
    ]);

    it('releases a task copy\'s done check without --check, sending the empty object', function (): void {
        $seen = [];
        $identity = ['kind' => 'tasks.publish', 'target_type' => 'task_group'];
        $result = ['branch' => 'task-17', 'commit' => str_repeat('a', 40), 'pull_request_number' => 12, 'pull_request_url' => 'https://github.com/acme/shop/pull/12'];
        $mock = cli_deploy_gateway([cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 7, 'poll_after_seconds' => null, 'result' => $result])], $seen, PublishTaskGroupRequest::class, $identity);

        expect(Artisan::call('task:publish', ['task' => '17', '--json' => true, '--no-interaction' => true]))->toBe(0);

        $pending = $mock->findResponseByRequest(PublishTaskGroupRequest::class)->getPendingRequest();
        expect($pending->body()->all())->toBe([])->and((string) $pending->body())->toBe('{}');
    });

    it('says why a publish of a check only pushed', function (): void {
        $seen = [];
        $identity = ['kind' => 'tasks.publish', 'target_type' => 'task_group'];
        $why = "Check 31 is not the group's done check 32, so no pull request opened.";
        $result = ['branch' => 'task-17', 'commit' => str_repeat('a', 40), 'pull_request_number' => null, 'pull_request_url' => null, 'pull_request_withheld' => $why];
        cli_deploy_gateway([cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 7, 'poll_after_seconds' => null, 'result' => $result])], $seen, PublishTaskGroupRequest::class, $identity);

        expect(Artisan::call('task:publish', ['task' => '17', '--check' => '31', '--no-interaction' => true]))->toBe(0);

        expect(Artisan::output())->toContain('Branch: task-17', 'No pull request: '.$why)->not->toContain('Pull request: ');
    });

    it('opens the pull request as a draft with --draft', function (): void {
        $seen = [];
        $identity = ['kind' => 'tasks.publish', 'target_type' => 'task_group'];
        $result = ['branch' => 'task-17', 'commit' => str_repeat('a', 40), 'pull_request_number' => 12, 'pull_request_url' => 'https://github.com/acme/shop/pull/12'];
        $mock = cli_deploy_gateway([cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 7, 'poll_after_seconds' => null, 'result' => $result])], $seen, PublishTaskGroupRequest::class, $identity);

        expect(Artisan::call('task:publish', ['task' => '17', '--check' => '31', '--title' => 'Spec', '--body' => 'Chart, page and cases', '--draft' => true, '--json' => true, '--no-interaction' => true]))->toBe(0);

        $pending = $mock->findResponseByRequest(PublishTaskGroupRequest::class)->getPendingRequest();
        expect($pending->body()->all())->toBe(['check' => 31, 'pull_request' => ['title' => 'Spec', 'body' => 'Chart, page and cases', 'draft' => true]]);
    });
});

describe('task:sync', function (): void {
    beforeEach(function (): void {
        MockClient::global(gateway_fixture_mock());
        app(GatewayExtensionState::class)->discover();
        MockClient::destroyGlobal();
    });

    it('starts the sync that MCP task-sync starts, follows it and prints the default head and how far behind the branch is', function (bool $json): void {
        $seen = [];
        $identity = ['kind' => 'tasks.sync', 'target_type' => 'task_group'];
        $result = ['default_branch' => 'main', 'default_commit' => str_repeat('c', 40), 'branch' => 'task-17', 'branch_commit' => str_repeat('a', 40), 'behind_by' => 3, 'start' => str_repeat('d', 40)];
        $mock = cli_deploy_gateway([
            cli_operation([...$identity, 'status' => 'running', 'sequence' => 2, 'current_step' => 'Bring in the base']),
            cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 5, 'poll_after_seconds' => null, 'result' => $result]),
        ], $seen, SyncTaskGroupRequest::class, $identity);

        expect(Artisan::call('task:sync', ['task' => '17', '--idempotency-key' => 'sync-17-1', '--json' => $json, '--no-interaction' => true]))->toBe(0);

        $output = Artisan::output();
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'])->toBe($result);
        } else {
            expect($output)->toContain('Sync task group [17]', 'Default commit: '.str_repeat('c', 40), 'Behind by: 3', 'Branch commit: '.str_repeat('a', 40));
        }
        $pending = $mock->findResponseByRequest(SyncTaskGroupRequest::class)->getPendingRequest();
        expect($pending->getMethod())->toBe(Method::POST)
            ->and($pending->getUrl())->toEndWith('/api/v1/tasks/17/sync')
            ->and((string) $pending->body())->toBe('')
            ->and($pending->headers()->get('Idempotency-Key'))->toBe('sync-17-1');
    })->with([false, true]);

    it('sends a fresh key when none is given', function (): void {
        $seen = [];
        $identity = ['kind' => 'tasks.sync', 'target_type' => 'task_group'];
        $mock = cli_deploy_gateway([cli_operation([...$identity, 'status' => 'succeeded', 'sequence' => 5, 'poll_after_seconds' => null, 'result' => ['behind_by' => 0]])],
            $seen, SyncTaskGroupRequest::class, $identity);

        expect(Artisan::call('task:sync', ['task' => '17', '--json' => true, '--no-interaction' => true]))->toBe(0);

        expect($mock->findResponseByRequest(SyncTaskGroupRequest::class)->getPendingRequest()->headers()->get('Idempotency-Key'))->toStartWith('tasks.sync.');
    });

    it('refuses a missing or invalid task before Gateway access', function (array $arguments, string $code): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('task:sync', [...$arguments, '--json' => true, '--no-interaction' => true]))->toBe(1);

        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
        $mock->assertNothingSent();
    })->with([
        [[], 'task.task_required'],
        [['task' => 'bad'], 'task.task_invalid'],
    ]);
});

it('shows the caller Node name and retains a removed caller ID in human output', function (?string $name, string $label): void {
    MockClient::global([
        ShowOperationRequest::class => cli_operation_response(cli_operation([
            'status' => 'succeeded',
            'caller_node_id' => 8,
            'caller_node_name' => $name,
        ])),
    ]);

    Artisan::call('operation:show', ['operation' => '42']);

    expect(Artisan::output())->toContain('Caller Node')->toContain($label);
})->with(['named' => ['services', 'services'], 'removed' => [null, 'Removed Node (8)']]);

it('shows the failed native step and its safe detail in human and JSON operation output', function (): void {
    MockClient::global([ShowOperationRequest::class => cli_operation_response(cli_operation([
        'status' => 'failed', 'failed_step' => 'create-mint', 'error_code' => 'operation.native_failed',
        'error' => ['code' => 'operation.native_failed', 'detail' => 'instance.git_mint_unavailable'],
    ]))]);

    Artisan::call('operation:show', ['operation' => '42', '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($json['error']['detail'])->toBe('instance.git_mint_unavailable');

    Artisan::call('operation:show', ['operation' => '42', '--no-interaction' => true]);
    expect(Artisan::output())->toContain('create-mint', 'instance.git_mint_unavailable');
});

it('renders an unreachable fleet reboot failure and diagnostic from the recorded Gateway response', function (): void {
    run_contract('operations/operation-show/fleet-reboot-unreachable', 'operation:show', ['operation' => '1'],
        'operations/operation-show/fleet-reboot-unreachable.human.txt', 0);
    run_contract('operations/operation-show/fleet-reboot-unreachable', 'operation:show', ['operation' => '1', '--json' => true],
        'operations/operation-show/fleet-reboot-unreachable.json', 0);
});

it('renders unknown Node outcomes from an interrupted fleet reboot response', function (): void {
    run_contract('operations/operation-show/fleet-reboot-interrupted', 'operation:show', ['operation' => '1'],
        'operations/operation-show/fleet-reboot-interrupted.human.txt', 0);
    run_contract('operations/operation-show/fleet-reboot-interrupted', 'operation:show', ['operation' => '1', '--json' => true],
        'operations/operation-show/fleet-reboot-interrupted.json', 0);
});

it('shows the overdue audit of an already timed-out Operation in human and JSON output', function (): void {
    run_contract('operations/operation-show/overdue', 'operation:show', ['operation' => '1'],
        'operations/operation-show/overdue.human.txt', 0);
    run_contract('operations/operation-show/overdue', 'operation:show', ['operation' => '1', '--json' => true],
        'operations/operation-show/overdue.json', 0);
});
