<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayLogStreamClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\GatewayStreamClient;
use App\Services\OrbitConfigStore;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('.');

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function fakeSuccessEnvelope(array $data = [], array $meta = []): array
{
    return JsonEnvelope::success($data, $meta);
}

/**
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function fakeErrorEnvelope(string $code = 'internal_error', string $message = 'Something went wrong.', array $meta = []): array
{
    return JsonEnvelope::failure($code, $message, $meta);
}

/**
 * Set up a fake gateway returning the given body with the given status code.
 *
 * @param  array<string, mixed>  $body
 */
function fakeGateway(array $body, int $status = 200): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);
    app()->forgetInstance(GatewayStreamClient::class);

    Http::fake(['https://gateway.test/*' => Http::response($body, $status)]);
}

/**
 * @param  array<string, mixed>  $data
 */
function gatewayProgressFrame(string $event, array $data): string
{
    return "event: {$event}\n"
        .'data: '.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n";
}

function fakeGatewayProgressStream(string $body, int $status = 200): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);
    app()->forgetInstance(GatewayStreamClient::class);

    Http::fake([
        'https://gateway.test/*' => Http::response($body, $status, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);
}

function fakeGatewayTextStream(string $body, int $status = 200): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);
    app()->forgetInstance(GatewayStreamClient::class);

    Http::fake([
        'https://gateway.test/*' => Http::response($body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]),
    ]);
}

/**
 * Set up a fake gateway that throws a connection exception.
 */
function fakeGatewayDown(string $message = 'connection refused'): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);
    app()->forgetInstance(GatewayStreamClient::class);

    Http::fake(function () use ($message): never {
        throw new ConnectionException($message);
    });
}

function fakeNoGatewayConfig(string $configPath): void
{
    config()->set('orbit.gateway.url', null);
    config()->set('orbit.gateway.timeout', null);
    config()->set('orbit.gateway.ca_pem_path', null);
    @unlink($configPath);

    app()->instance(OrbitConfigStore::class, new OrbitConfigStore(overridePath: $configPath));
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);
    app()->forgetInstance(GatewayStreamClient::class);
}

/**
 * Run an Artisan command and return [exitCode, output].
 *
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runCommand(object $test, string $command, array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan($command, $params);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

function restoreHostCwd(string|false $previousHostCwd): void
{
    if ($previousHostCwd === false) {
        putenv('ORBIT_HOST_CWD');

        return;
    }

    putenv("ORBIT_HOST_CWD={$previousHostCwd}");
}
