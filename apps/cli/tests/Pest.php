<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayLogStreamClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\GatewayStreamClient;
use App\Services\OrbitConfigStore;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\Requests\GenericGatewayStreamRequest;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockResponse;
use Orbit\Sdk\Laravel\Testing\GatewayPendingRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
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
function fakeErrorEnvelope(
    string $code = 'internal_error',
    string $message = 'Something went wrong.',
    array $meta = [],
): array {
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
    app()->forgetInstance(FakeGatewayStreamHttpClient::class);
    GatewayMockClient::destroyGlobal();

    Http::fake(['https://gateway.test/*' => Http::response($body, $status)]);
}

/**
 * @param  array<string, mixed>  $data
 */
function gatewayProgressFrame(string $event, array $data): string
{
    return "event: {$event}\n".'data: '.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n\n";
}

function fakeGatewayProgressStream(string|StreamInterface $body, int $status = 200): void
{
    fakeGatewayProgressStreamClient($body, $status);

    Http::fake([
        'https://gateway.test/*' => Http::response($body, $status, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);
}

function fakeGatewayProgressStreamClient(string|StreamInterface $body, int $status = 200): FakeGatewayStreamHttpClient
{
    return fakeGatewayProgressStreamSequence([$body], $status);
}

/**
 * @param  list<string|StreamInterface>  $bodies
 */
function fakeGatewayProgressStreamSequence(array $bodies, int $status = 200): FakeGatewayStreamHttpClient
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);
    app()->forgetInstance(GatewayStreamClient::class);

    $responses = array_map(
        fn (string|StreamInterface $body): Psr7Response => new Psr7Response(
            $status,
            ['Content-Type' => 'text/event-stream'],
            $body,
        ),
        $bodies,
    );

    $httpClient = new FakeGatewayStreamHttpClient($responses);

    app()->instance(FakeGatewayStreamHttpClient::class, $httpClient);
    GatewayMockClient::destroyGlobal();
    GatewayMockClient::global([
        GenericGatewayStreamRequest::class =>
            fn (GatewayPendingRequest $pendingRequest): GatewayMockResponse => new FakeGatewayStreamMockResponse(
                $httpClient->recordPendingRequest($pendingRequest),
            ),
    ]);

    app()->instance(GatewayStreamClient::class, new GatewayStreamClient(
        baseUrl: 'https://gateway.test',
        timeout: 30,
    ));

    return $httpClient;
}

/**
 * @implements ArrayAccess<string, mixed>
 */
final readonly class FakeGatewayStreamRequest implements ArrayAccess
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private string $method,
        private string $url,
        private array $options,
    ) {}

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function hasHeader(string $header, ?string $value = null): bool
    {
        $headers = $this->options['headers'] ?? [];

        if (! is_array($headers) || ! array_key_exists($header, $headers)) {
            return false;
        }

        if ($value === null) {
            return true;
        }

        return $headers[$header] === $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->options['json'] ?? [];

        return is_array($data) ? $data : [];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->data());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Fake gateway stream requests are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Fake gateway stream requests are read-only.');
    }
}

/**
 * @param  list<ResponseInterface|Throwable>  $queue
 */
final class FakeGatewayStreamHttpClient implements ClientInterface
{
    /**
     * @var list<array{method: string, uri: string, options: array<string, mixed>}>
     */
    public array $requests = [];

    public function __construct(
        private array $queue,
    ) {}

    public function recordPendingRequest(GatewayPendingRequest $request): ResponseInterface
    {
        $headers = [];

        foreach (['Accept', 'X-Orbit-Node-Transport-Preference'] as $header) {
            $value = $request->header($header);

            if (is_string($value)) {
                $headers[$header] = $value;
            }
        }

        $this->requests[] = [
            'method' => $request->method(),
            'uri' => $request->url(),
            'options' => [
                'headers' => $headers,
                'json' => $request->body(),
            ],
        ];

        return $this->nextResponse();
    }

    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        return $this->request($request->getMethod(), (string) $request->getUri(), $options);
    }

    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        return Create::promiseFor($this->send($request, $options));
    }

    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        $this->requests[] = [
            'method' => $method,
            'uri' => (string) $uri,
            'options' => $options,
        ];

        return $this->nextResponse();
    }

    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface
    {
        return Create::promiseFor($this->request($method, $uri, $options));
    }

    public function getConfig(?string $option = null): mixed
    {
        return $option === null ? [] : null;
    }

    private function nextResponse(): ResponseInterface
    {
        $response = array_shift($this->queue);

        if ($response instanceof Throwable) {
            throw $response;
        }

        if (! $response instanceof ResponseInterface) {
            throw new RuntimeException('No fake gateway stream response queued.');
        }

        return $response;
    }
}

final class FakeGatewayStreamMockResponse extends GatewayMockResponse
{
    public function __construct(
        private readonly ResponseInterface $response,
    ) {
        parent::__construct('', $response->getStatusCode());
    }

    public function createPsrResponse(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): ResponseInterface {
        return $this->response;
    }
}

/**
 * @param  callable(FakeGatewayStreamRequest): bool  $callback
 */
function assertGatewayStreamSent(callable $callback): void
{
    $httpClient = app(FakeGatewayStreamHttpClient::class);

    foreach ($httpClient->requests as $request) {
        $fakeRequest = new FakeGatewayStreamRequest(
            method: $request['method'],
            url: $request['uri'],
            options: $request['options'],
        );

        if ($callback($fakeRequest)) {
            expect(true)->toBeTrue();

            return;
        }
    }

    expect(false)->toBeTrue('An expected gateway stream request was not recorded.');
}

/**
 * @param  callable(FakeGatewayStreamRequest): bool  $callback
 */
function assertGatewayStreamNotSent(callable $callback): void
{
    if (! app()->bound(FakeGatewayStreamHttpClient::class)) {
        expect(true)->toBeTrue();

        return;
    }

    $httpClient = app(FakeGatewayStreamHttpClient::class);

    foreach ($httpClient->requests as $request) {
        $fakeRequest = new FakeGatewayStreamRequest(
            method: $request['method'],
            url: $request['uri'],
            options: $request['options'],
        );

        if ($callback($fakeRequest)) {
            expect(false)->toBeTrue('An unexpected gateway stream request was recorded.');
        }
    }

    expect(true)->toBeTrue();
}

function assertGatewayStreamNothingSent(): void
{
    if (! app()->bound(FakeGatewayStreamHttpClient::class)) {
        expect(true)->toBeTrue();

        return;
    }

    expect(app(FakeGatewayStreamHttpClient::class)->requests)->toBe([]);
}

function assertGatewayStreamSentCount(int $count): void
{
    expect(app(FakeGatewayStreamHttpClient::class)->requests)->toHaveCount($count);
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
    app()->forgetInstance(FakeGatewayStreamHttpClient::class);
    GatewayMockClient::destroyGlobal();

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
    app()->forgetInstance(FakeGatewayStreamHttpClient::class);
    GatewayMockClient::destroyGlobal();

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
    app()->forgetInstance(FakeGatewayStreamHttpClient::class);
}

function assertProgressTreeSpacerContract(string $text): bool
{
    $lines = array_values(array_filter(
        explode("\n", rtrim($text, "\n")),
        static fn (string $line): bool => trim($line) !== '',
    ));

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
 * Run a registered command with interactive input enabled (cwd inference paths).
 *
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function run_application_log_command_interactive(string $command, array $params = []): array
{
    return run_application_log_command_with_interactivity($command, $params, interactive: true);
}

/**
 * Run a registered command with interactive input disabled (noninteractive omission paths).
 *
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function run_application_log_command_noninteractive(string $command, array $params = []): array
{
    return run_application_log_command_with_interactivity($command, $params, interactive: false);
}

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function run_application_log_command_with_interactivity(
    string $command,
    array $params,
    bool $interactive,
): array {
    $instance = Artisan::all()[$command] ?? null;

    if (! $instance instanceof SymfonyCommand) {
        throw new RuntimeException("Command [{$command}] is not registered.");
    }

    $instance->setLaravel(app());

    $input = new ArrayInput($params);
    $input->setInteractive($interactive);
    $output = new BufferedOutput;

    $exitCode = $instance->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function orbit_test_config_path(string $prefix): string
{
    return sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6)).'.json';
}

function unlink_orbit_test_file(?string $path): void
{
    if ($path === null || $path === '' || ! is_file($path)) {
        return;
    }

    unlink($path);
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

function terminateForkedFixtureProcess(): never
{
    $pid = getmypid();

    if (is_int($pid) && $pid > 0 && function_exists('posix_kill') && defined('SIGKILL')) {
        posix_kill($pid, SIGKILL);
    }

    exit(0);
}
