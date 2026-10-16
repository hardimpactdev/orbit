<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\DeployInstanceRequest;
use Orbit\Sdk\Requests\Deployments\RollbackInstanceRequest;
use Orbit\Sdk\Requests\Operations\CancelOperationRequest;
use Orbit\Sdk\Requests\Operations\ListOperationsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

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
 * @param  list<array<string, mixed>>  $steps
 * @return array<string, mixed>
 */
function cli_operation(array $overrides = [], array $steps = []): array
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
        ...($steps === [] ? [] : ['steps' => $steps]),
    ];
}

/** @return array<string, mixed> */
function cli_step(string $name, int $position, string $status, int $sequence, ?string $output = null, ?string $error = null): array
{
    return [
        'name' => $name, 'position' => $position, 'status' => $status, 'resumable' => ! str_contains($name, '.'), 'sequence' => $sequence,
        'started_at' => null, 'finished_at' => null, 'error_code' => $error, 'output' => $output, 'output_truncated' => false,
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
    it('renders the Process restart step and its deployment outcome', function (bool $fails): void {
        $seen = [];
        cli_deploy_gateway([
            cli_operation([
                'status' => $fails ? 'failed' : 'succeeded', 'sequence' => 5,
                'failed_step' => $fails ? 'process_restart' : null,
                'error_code' => $fails ? 'process.restart_failed' : null,
                'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 5, 'release' => 'r2', 'selected_release' => 'r2', 'commit' => null],
            ], [cli_step('process_restart', 1, $fails ? 'failed' : 'succeeded', 5,
                $fails ? "Failed to restart Process 12.\n" : "Restarted Process 12.\n", $fails ? 'process.restart_failed' : null)]),
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe($fails ? 1 : 0)
            ->and($output)->toContain($fails ? 'Restarting Processes' : 'Restarted Processes')
            ->and($output)->toContain($fails ? 'Deployment failed.' : 'Deployment succeeded.');
        if ($fails) {
            expect($output)->toContain('process.restart_failed')->not->toContain('Deployment succeeded.');
        }
    })->with([false, true]);

    it('starts the operation and follows it by polling until it ends, printing new step output once', function (): void {
        $seen = [];
        $done = cli_operation(['status' => 'succeeded', 'sequence' => 9, 'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null,
            'result' => ['deployment_id' => 5, 'release' => 'r2', 'selected_release' => 'r2', 'commit' => null]]);
        cli_deploy_gateway([
            cli_operation(['status' => 'running', 'sequence' => 3, 'started_at' => '2026-10-15T10:00:01+00:00', 'current_step' => 'source_preparation'], [
                cli_step('source_preparation', 1, 'running', 3, "cloning\n"),
                cli_step('before_activation.migrate', 2, 'pending', 2),
            ]),
            cli_operation(['status' => 'running', 'sequence' => 6, 'started_at' => '2026-10-15T10:00:01+00:00', 'current_step' => 'before_activation.migrate'], [
                cli_step('source_preparation', 1, 'succeeded', 4, "cloning\ncloned\n"),
                cli_step('before_activation.migrate', 2, 'running', 6, "migrating\n"),
            ]),
            [...$done, 'steps' => [cli_step('before_activation.migrate', 2, 'succeeded', 9, "migrating\nmigrated\n")]],
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Deploy Instance [17]', 'Prepared source', 'Ran migrate', 'Deployment succeeded.', 'Selected release: r2', 'Operation: 42')
            ->and(substr_count($output, '"cloning"'))->toBe(1)
            ->and($output)->toContain('source_preparation: "cloned"', 'before_activation.migrate: "migrated"')
            ->and($seen)->toBe(['after_sequence=0', 'after_sequence=3', 'after_sequence=6']);
    });

    it('fails with the failed step and error code of the operation', function (): void {
        $seen = [];
        cli_deploy_gateway([
            cli_operation(['status' => 'failed', 'sequence' => 5, 'started_at' => '2026-10-15T10:00:01+00:00', 'failed_step' => 'source_preparation',
                'error_code' => 'deployment.prepare_failed', 'poll_after_seconds' => null], [
                    cli_step('source_preparation', 1, 'failed', 5, null, 'deployment.prepare_failed'),
                    cli_step('cleanup', 2, 'pending', 2),
                ]),
        ], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--no-interaction' => true]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('Deployment failed.', 'Failed step: source_preparation', 'Error code: deployment.prepare_failed', 'Not reached.');
    });

    it('prints only the final operation with every step in JSON mode', function (): void {
        $seen = [];
        $final = cli_operation(['status' => 'succeeded', 'sequence' => 4, 'started_at' => '2026-10-15T10:00:01+00:00', 'poll_after_seconds' => null], [
            cli_step('source_preparation', 1, 'succeeded', 4),
        ]);
        cli_deploy_gateway([$final], $seen);

        $exitCode = Artisan::call('instance:deploy', ['instance' => '17', '--json' => true, '--no-interaction' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['status'])->toBe('succeeded')
            ->and(array_column($payload['steps'], 'name'))->toBe(['source_preparation'])
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
            cli_operation([...$rollback, 'status' => 'running', 'sequence' => 3, 'started_at' => '2026-10-16T10:00:01+00:00', 'current_step' => 'activation'], [
                cli_step('rollback', 1, 'succeeded', 2),
                cli_step('activation', 2, 'running', 3),
                cli_step('process_restart', 3, 'pending', 1),
            ]),
            cli_operation([...$rollback, 'status' => 'succeeded', 'sequence' => 6, 'started_at' => '2026-10-16T10:00:01+00:00', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 7, 'release' => 'release-a', 'selected_release' => 'release-a', 'commit' => null]], [
                    cli_step('activation', 2, 'succeeded', 4),
                    cli_step('process_restart', 3, 'succeeded', 6, "Restarted Process 12.\n"),
                ]),
        ], $seen, RollbackInstanceRequest::class, $rollback);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Roll back Instance [17]', 'Found retained release', 'Activated release', 'Restarted Processes', 'Rollback succeeded.', 'Selected release: release-a', 'Operation: 43')
            ->and($output)->toContain('process_restart: "Restarted Process 12."')
            ->and($seen)->toBe(['after_sequence=0', 'after_sequence=3']);
        $mock->assertSentCount(1, RollbackInstanceRequest::class);
        expect($mock->findResponseByRequest(RollbackInstanceRequest::class)?->getPendingRequest()->body()?->all())->toBe(['release' => 'release-a']);
    });

    it('fails with the failed step and error code of the operation', function (): void {
        $seen = [];
        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-x']];
        cli_deploy_gateway([
            cli_operation([...$rollback, 'status' => 'failed', 'sequence' => 4, 'started_at' => '2026-10-16T10:00:01+00:00', 'failed_step' => 'rollback',
                'error_code' => 'rollback.release_invalid', 'poll_after_seconds' => null,
                'result' => ['deployment_id' => 7, 'release' => null, 'selected_release' => 'release-b', 'commit' => null]], [
                    cli_step('rollback', 1, 'failed', 4, null, 'rollback.release_invalid'),
                    cli_step('activation', 2, 'pending', 1),
                ]),
        ], $seen, RollbackInstanceRequest::class, $rollback);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-x', '--no-interaction' => true]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('Rollback failed.', 'Failed step: rollback', 'Error code: rollback.release_invalid', 'Selected release: release-b', 'Not reached.');
    });

    it('prints only the final operation in JSON mode', function (): void {
        $seen = [];
        $rollback = ['id' => 43, 'kind' => 'instance.rollback', 'input' => ['release' => 'release-a']];
        cli_deploy_gateway([
            cli_operation([...$rollback, 'status' => 'succeeded', 'sequence' => 4, 'started_at' => '2026-10-16T10:00:01+00:00', 'poll_after_seconds' => null], [
                cli_step('rollback', 1, 'succeeded', 2),
                cli_step('activation', 2, 'succeeded', 4),
            ]),
        ], $seen, RollbackInstanceRequest::class, $rollback);

        $exitCode = Artisan::call('instance:rollback', ['instance' => '17', '--release' => 'release-a', '--json' => true, '--no-interaction' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['kind'])->toBe('instance.rollback')
            ->and($payload['input'])->toBe(['release' => 'release-a'])
            ->and(array_column($payload['steps'], 'name'))->toBe(['rollback', 'activation'])
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

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), true)['error']['code'])->toBe('operation.target_busy');
        $mock->assertSentCount(1);
    });
});

describe('operation commands', function (): void {
    it('lists operations with filters', function (): void {
        $mock = MockClient::global([
            ListOperationsRequest::class => MockResponse::make(['data' => [cli_operation(['status' => 'running', 'current_step' => 'activation'])], 'meta' => []], 200, ['X-Orbit-Request-Id' => OPERATION_REQUEST_ID]),
        ]);

        $exitCode = Artisan::call('operation:list', ['--status' => 'running', '--instance' => '17', '--no-interaction' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('instance.deploy', 'instance 17', 'running', 'activation');
        $mock->assertSent(static fn ($request): bool => $request instanceof ListOperationsRequest
            && $request->query()->all() === ['status' => 'running', 'target_type' => 'instance', 'target_id' => 17]);

        expect(Artisan::call('operation:list', ['--status' => 'sleeping', '--json' => true, '--no-interaction' => true]))->toBe(1);
    });

    it('shows an operation with its steps and output', function (): void {
        MockClient::global([
            ShowOperationRequest::class => cli_operation_response(cli_operation(['status' => 'failed', 'failed_step' => 'before_activation.migrate', 'error_code' => 'deployment.step_failed'], [
                cli_step('before_activation.migrate', 1, 'failed', 3, "boom\n", 'deployment.step_failed'),
            ])),
        ]);

        $exitCode = Artisan::call('operation:show', ['operation' => '42', '--no-interaction' => true]);

        expect($exitCode)->toBe(0)
            ->and(Artisan::output())->toContain('Operation: 42', 'deployment.step_failed', 'Output of before_activation.migrate:', 'boom');
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
