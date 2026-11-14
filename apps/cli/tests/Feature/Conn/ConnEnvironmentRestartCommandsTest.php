<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Conn\RestartConnEnvironmentRequest;
use Orbit\Sdk\Requests\Conn\RestartNodeConnEnvironmentsRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const RestartRequestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-conn-restart-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/unused-ca'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function restartOperation(string $status, int $sequence, ?array $result = null, ?string $error = null): array
{
    return ['id' => 9, 'kind' => 'conn.environment_restart', 'target_type' => 'instance', 'target_id' => 4, 'status' => $status,
        'sequence' => $sequence, 'poll_after_seconds' => 0, 'result' => $result, 'error_code' => $error];
}

function restartAnswers(): array
{
    return ['restarts' => [
        ['instance_id' => 3, 'instance' => 'default', 'answer' => 'current', 'reason' => null, 'operation_id' => null],
        ['instance_id' => 4, 'instance' => 'clone', 'answer' => 'restarting', 'reason' => null, 'operation_id' => 9],
        ['instance_id' => 5, 'instance' => 'review', 'answer' => 'busy', 'reason' => 't3_working', 'operation_id' => null],
        ['instance_id' => 6, 'instance' => 'asleep', 'answer' => 'skipped', 'reason' => 'frozen', 'operation_id' => null],
    ]];
}

it('walks a Node, follows each admitted restart to its end, and prints one answer per Instance in JSON', function (array $final, string $answer, ?string $reason, int $exit): void {
    $mock = MockClient::global([
        RestartNodeConnEnvironmentsRequest::class => MockResponse::make(['data' => restartAnswers(), 'meta' => ['request_id' => RestartRequestId]]),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => RestartRequestId]]),
    ]);

    $code = Artisan::call('conn:node-environments-restart', ['node' => 7, '--json' => true, '--no-interaction' => true]);

    expect($code)->toBe($exit)->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))->toBe(['restarts' => [
        ['instance_id' => 3, 'instance' => 'default', 'answer' => 'current', 'reason' => null, 'operation_id' => null],
        ['instance_id' => 4, 'instance' => 'clone', 'answer' => $answer, 'reason' => $reason, 'operation_id' => 9],
        ['instance_id' => 5, 'instance' => 'review', 'answer' => 'busy', 'reason' => 't3_working', 'operation_id' => null],
        ['instance_id' => 6, 'instance' => 'asleep', 'answer' => 'skipped', 'reason' => 'frozen', 'operation_id' => null],
    ]]);
    $mock->assertSent(fn ($request): bool => $request instanceof RestartNodeConnEnvironmentsRequest && $request->resolveEndpoint() === '/api/v1/conn/nodes/7/t3-restart');
    $mock->assertSent(ShowOperationRequest::class);
})->with([
    'restarted' => [restartOperation('succeeded', 4, ['answer' => 'restarted', 'window_ms' => 6100]), 'restarted', null, 0],
    'busy in the run' => [restartOperation('succeeded', 4, ['answer' => 'busy', 'reason' => 'processes', 'terminals' => 1, 'commands' => 1]), 'busy', 'processes: 1 terminal(s), 1 command(s)', 0],
    'failed' => [restartOperation('failed', 4, ['answer' => 'failed', 'error_code' => 'conn.t3_unready'], 'conn.t3_unready'), 'failed', 'conn.t3_unready', 1],
]);

it('prints one line per answer for one Instance and needs no Operation read without a restart', function (): void {
    $mock = MockClient::global([RestartConnEnvironmentRequest::class => MockResponse::make(['data' => ['restarts' => [
        ['instance_id' => 5, 'instance' => 'review', 'answer' => 'busy', 'reason' => 't3_working', 'operation_id' => null],
    ]], 'meta' => ['request_id' => RestartRequestId]])]);

    $code = Artisan::call('conn:environment-restart', ['instance' => 5, '--no-interaction' => true]);

    expect($code)->toBe(0)->and(Artisan::output())->toContain('5 review: busy (t3_working)');
    $mock->assertSent(fn ($request): bool => $request instanceof RestartConnEnvironmentRequest && $request->resolveEndpoint() === '/api/v1/conn/instances/5/t3-restart');
    $mock->assertNotSent(ShowOperationRequest::class);
});

it('refuses an Instance ID that is not a positive number before it calls the Gateway', function (): void {
    $mock = MockClient::global([]);

    expect(Artisan::call('conn:environment-restart', ['instance' => 'abc', '--json' => true, '--no-interaction' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('instance.id_invalid');
    $mock->assertNothingSent();
});
