<?php

declare(strict_types=1);

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayStreamClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Progress\ProgressEventType;

/**
 * Minimal command that uses StreamsGatewayProgress.
 */
class TestStreamingCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

    protected $signature = 'test:streaming-command {--json}';

    protected $description = 'Test streaming command';

    public function handle(): int
    {
        return $this->streamProgress('/api/stream', [], function (ProgressEventType $type, array $payload): int {
            if ($type === ProgressEventType::Error) {
                return $this->renderFailure(
                    'stream_error',
                    $payload['message'] ?? 'Stream error.',
                );
            }

            return $this->renderSuccess($payload);
        });
    }
}

/**
 * @param  list<array{type: ProgressEventType, payload: array<string, mixed>}>  $frames
 */
function fakeStreamClient(array $frames): void
{
    app()->bind(GatewayStreamClient::class, fn () => new class($frames)
    {
        /** @param list<array{type: ProgressEventType, payload: array<string, mixed>}> $frames */
        public function __construct(private readonly array $frames) {}

        /**
         * @param  array<string, mixed>  $payload
         * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
         */
        public function streamEvents(string $path, array $payload, callable $onEvent, string $method = 'post'): int
        {
            foreach ($this->frames as $frame) {
                $onEvent($frame['type'], $frame['payload']);

                if ($frame['type'] === ProgressEventType::Complete || $frame['type'] === ProgressEventType::Error) {
                    return $frame['type'] === ProgressEventType::Complete ? 0 : 1;
                }
            }

            throw GatewayApiException::streamClosedBeforeTerminal(
                new RuntimeException('Stream closed without terminal frame.'),
            );
        }
    });
}

/**
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runStreamingCommand(object $test, array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    app(Kernel::class)->registerCommand(new TestStreamingCommand);

    $exitCode = $test->artisan('test:streaming-command', $params);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

describe('StreamsGatewayProgress', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
    });

    it('emits only the final complete frame as JSON in --json mode', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Tree, 'payload' => ['name' => 'setup']],
            ['type' => ProgressEventType::Step, 'payload' => ['message' => 'cloning']],
            ['type' => ProgressEventType::Complete, 'payload' => ['done' => true]],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded)->toHaveKey('success')
            ->and($decoded['success']['data'])->toBe(['done' => true]);

        // Only one JSON line emitted (the final frame).
        expect(count(array_filter(explode("\n", $output))))->toBe(1);
    });

    it('renders intermediate frames as an animated tree in human mode', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Tree, 'payload' => [
                'title' => 'Setting up',
                'steps' => [
                    ['key' => 'install', 'label' => 'Install packages', 'doneLabel' => 'Installed packages'],
                ],
            ]],
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'install', 'status' => 'progress', 'message' => 'installing packages']],
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'install', 'status' => 'done']],
            ['type' => ProgressEventType::Complete, 'payload' => ['footer' => 'Setup complete.']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Setting up')
            ->and($output)->toContain('installing packages')
            ->and($output)->toContain('Installed packages')
            ->and($output)->toContain('Setup complete.')
            ->and($output)->not->toContain('[tree]')
            ->and($output)->not->toContain('[step]');
    });

    it('surfaces gateway_unavailable when the stream closes before a terminal frame', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Step, 'payload' => ['message' => 'still running']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns failure exit code and failure envelope on error frame in JSON mode', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Error, 'payload' => ['message' => 'clone failed']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('stream_error')
            ->and($decoded['error']['message'])->toBe('clone failed');
    });
});
