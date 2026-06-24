<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Services\GatewayApiClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;

// Minimal test command wired to GatewayCommand.
class TestGatewayCommand extends GatewayCommand
{
    protected $signature = 'test:gateway-command {--json}';

    protected $description = 'Test command for GatewayCommandTest';

    public string $lastAction = '';

    public function handle(): int
    {
        return match ($this->lastAction) {
            'get' => $this->renderSuccess($this->gatewayGet('/api/test')),
            'post' => $this->renderSuccess($this->gatewayPost('/api/test', ['key' => 'value'])),
            'delete' => $this->renderSuccess($this->gatewayDelete('/api/test', ['id' => 1])),
            'passthrough' => $this->renderSuccess($this->gateway()->get('/api/test')),
            'fail' => $this->renderFailure('gateway_unavailable', 'Gateway failed.'),
            default => $this->renderSuccess(['ok' => true]),
        };
    }
}

/**
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runGatewayCommand(object $test, string $action = '', array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $command = new TestGatewayCommand;
    $command->lastAction = $action;

    app(Kernel::class)->registerCommand($command);

    $exitCode = $test->artisan('test:gateway-command', $params);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

describe('GatewayCommand', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
    });

    it('can be instantiated and renders success without double-wrapping (D12)', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response(
                JsonEnvelope::success(['result' => 'ok']),
                200,
            ),
        ]);

        [$exitCode, $output] = runGatewayCommand($this, 'get', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toHaveKey('success')
            ->and($decoded['success'])
            ->toHaveKey('data')
            ->and($decoded['success']['data'])
            ->toBe(['result' => 'ok'])
            ->and($decoded['success'])
            ->not->toHaveKey('success');
    });

    it('gatewayGet threads through the GatewayApiClient', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response([
                'success' => ['data' => ['x' => 1], 'meta' => []],
            ], 200),
        ]);

        [$exitCode] = runGatewayCommand($this, 'get');

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/test'),
        );

        expect($exitCode)->toBe(0);
    });

    it('gatewayPost threads through the GatewayApiClient with JSON payload', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response([
                'success' => ['data' => ['created' => true], 'meta' => []],
            ], 200),
        ]);

        [$exitCode] = runGatewayCommand($this, 'post');

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->isJson()
                && isset($request->data()['key'])
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('gatewayDelete threads through the GatewayApiClient', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response([
                'success' => ['data' => ['deleted' => true], 'meta' => []],
            ], 200),
        ]);

        [$exitCode] = runGatewayCommand($this, 'delete');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');

        expect($exitCode)->toBe(0);
    });

    it('renderSuccess unwraps a gateway success envelope (D12)', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response(
                ['success' => ['data' => ['key' => 'value'], 'meta' => ['page' => 1]]],
                200,
            ),
        ]);

        [$exitCode, $output] = runGatewayCommand($this, 'get', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data'])
            ->toBe(['key' => 'value'])
            ->and($decoded['success']['meta'])
            ->toBe(['page' => 1]);
    });

    it('gateway() accessor returns a GatewayApiClient', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response([
                'success' => ['data' => ['ok' => true], 'meta' => []],
            ], 200),
        ]);

        [$exitCode] = runGatewayCommand($this, 'passthrough');

        expect($exitCode)->toBe(0);
    });

    it('renders key:value lines in human mode', function (): void {
        Http::fake([
            'https://gateway.test/api/test*' => Http::response(
                ['success' => ['data' => ['status' => 'running'], 'meta' => []]],
                200,
            ),
        ]);

        [$exitCode, $output] = runGatewayCommand($this, 'get');

        expect($exitCode)->toBe(0)->and($output)->toBe('status: running');
    });

    it('renderFailure produces a canonical error envelope in JSON mode', function (): void {
        [$exitCode, $output] = runGatewayCommand($this, 'fail', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toHaveKey('error')
            ->and($decoded['error']['code'])
            ->toBe('gateway_unavailable');
    });
});
