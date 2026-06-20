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
use Symfony\Component\Console\Output\StreamOutput;
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

function assertProgressTreeSpacerContract(string $text): bool
{
    $lines = array_values(array_filter(explode("\n", rtrim($text, "\n")), static fn (string $line): bool => trim($line) !== ''));

    if ($lines === [] || ! str_contains($lines[0], 'Updating Orbit nodes')) {
        return false;
    }

    $rowCount = 0;

    for ($index = 1; $index < count($lines); $index++) {
        if (! str_contains($lines[$index], '│')) {
            return false;
        }

        $index++;

        if ($index >= count($lines)) {
            return false;
        }

        if (str_contains($lines[$index], '└')) {
            return $rowCount > 0;
        }

        $rowCount++;
    }

    return false;
}

function progressRowPosition(string $text, string $target): int|false
{
    $text = preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $text) ?? $text;
    $pattern = '/^\s+\S+\s+'.preg_quote($target, '/').'\b/m';

    if (preg_match($pattern, $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
        return false;
    }

    return $matches[0][1];
}

/**
 * @param  list<string>  $targets
 */
function assertProgressTargetOrder(string $text, array $targets): void
{
    $positions = [];

    foreach ($targets as $target) {
        $position = progressRowPosition($text, $target);

        expect($position)->not->toBeFalse("Expected progress row for {$target}");

        $positions[] = $position;
    }

    for ($index = 1, $max = count($positions); $index < $max; $index++) {
        expect($positions[$index])->toBeGreaterThan($positions[$index - 1]);
    }
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

/**
 * Run an Artisan command against decorated stdout so forked progress tickers
 * remain visible through output buffering.
 *
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runDecoratedCommand(object $test, string $command, array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $capturePath = tempnam(sys_get_temp_dir(), 'orbit-cli-progress-');

    if ($capturePath === false) {
        throw new RuntimeException('Could not allocate a temporary file for decorated command output.');
    }

    $handle = fopen($capturePath, 'ab');

    if ($handle === false) {
        @unlink($capturePath);

        throw new RuntimeException('Could not open the temporary decorated command output file.');
    }

    $output = new StreamOutput($handle, decorated: true);
    $exitCode = app(Kernel::class)->call($command, $params, $output);
    fclose($handle);

    $captured = (string) file_get_contents($capturePath);
    @unlink($capturePath);

    return [$exitCode, $captured];
}

function restoreHostCwd(string|false $previousHostCwd): void
{
    if ($previousHostCwd === false) {
        putenv('ORBIT_HOST_CWD');

        return;
    }

    putenv("ORBIT_HOST_CWD={$previousHostCwd}");
}
