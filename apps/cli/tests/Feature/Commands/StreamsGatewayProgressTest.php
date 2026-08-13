<?php

declare(strict_types=1);

use App\Commands\Concerns\StreamsGatewayProgress;
use App\Commands\GatewayCommand;
use App\Exceptions\GatewayApiException;
use App\Services\GatewayProgressStreamClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;

/**
 * Minimal command that uses StreamsGatewayProgress.
 */
class TestStreamingCommand extends GatewayCommand
{
    use StreamsGatewayProgress;

    protected $signature = 'test:streaming-command {--json} {--stream-json}';

    protected $description = 'Test streaming command';

    public function handle(): int
    {
        return $this->streamProgress(
            '/api/stream',
            [],
            fn (ProgressEventType $type, array $payload): int => $this->renderProgressTerminalFrame($type, $payload),
        );
    }
}

/**
 * @param  list<array{type: ProgressEventType, payload: array<string, mixed>}>  $frames
 */
function fakeStreamClient(array $frames): void
{
    app()->bind(GatewayProgressStreamClient::class, fn () => new class($frames) implements GatewayProgressStreamClient {
        /** @param list<array{type: ProgressEventType, payload: array<string, mixed>}> $frames */
        public function __construct(
            private readonly array $frames,
        ) {}

        /**
         * @param  array<string, mixed>  $payload
         * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
         *
         * @mago-ignore lint:excessive-parameter-list
         */
        public function streamEvents(
            string $path,
            array $payload,
            callable $onEvent,
            string $method = 'post',
            ?callable $onIdle = null,
            int $idleIntervalMicroseconds = 300_000,
        ): int {
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

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'event' => 'complete',
                'data' => ['done' => true],
            ]);

        // Only one JSON line emitted (the final frame).
        expect(count(array_filter(explode("\n", $output))))->toBe(1);
    });

    it('returns failure for a nonzero complete frame in --json mode without discarding its result', function (): void {
        fakeStreamClient([
            [
                'type' => ProgressEventType::Complete,
                'payload' => [
                    'exit_code' => 1,
                    'data' => ['updated' => ['composer'], 'failed' => ['node']],
                ],
            ],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'event' => 'complete',
                'error' => [
                    'code' => 'operation_failed',
                    'message' => 'Operation completed with failures.',
                    'meta' => ['exit_code' => 1],
                    'data' => ['updated' => ['composer'], 'failed' => ['node']],
                ],
            ]);
    });

    it('streams newline-delimited JSON frames in --stream-json mode', function (): void {
        fakeStreamClient([
            [
                'type' => ProgressEventType::Tree,
                'payload' => [
                    'title' => 'Setting up',
                    'steps' => [
                        ['key' => 'install', 'label' => 'Install packages'],
                    ],
                ],
            ],
            [
                'type' => ProgressEventType::Step,
                'payload' => ['key' => 'install', 'status' => 'running', 'message' => 'installing packages'],
            ],
            [
                'type' => ProgressEventType::Complete,
                'payload' => [
                    'exit_code' => 0,
                    'data' => ['result' => ['id' => 123]],
                ],
            ],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--stream-json' => true]);

        $frames = array_map(
            fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $output)),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($frames)
            ->toHaveCount(3)
            ->and($frames[0])
            ->toBe([
                'event' => 'tree',
                'data' => [
                    'title' => 'Setting up',
                    'steps' => [
                        ['key' => 'install', 'label' => 'Install packages'],
                    ],
                ],
            ])
            ->and($frames[1])
            ->toBe([
                'event' => 'step',
                'data' => ['key' => 'install', 'status' => 'running', 'message' => 'installing packages'],
            ])
            ->and($frames[2])
            ->toBe([
                'event' => 'complete',
                'success' => [
                    'data' => ['result' => ['id' => 123]],
                    'meta' => [],
                ],
            ]);
    });

    it('emits a failed complete frame in --stream-json mode with the partial result intact', function (): void {
        fakeStreamClient([
            [
                'type' => ProgressEventType::Complete,
                'payload' => [
                    'exit_code' => 1,
                    'data' => [
                        'updated' => ['composer'],
                        'failed' => ['node'],
                        'footer' => 'Tool update failed',
                    ],
                ],
            ],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--stream-json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'event' => 'complete',
                'error' => [
                    'code' => 'operation_failed',
                    'message' => 'Tool update failed',
                    'meta' => ['exit_code' => 1],
                    'data' => [
                        'updated' => ['composer'],
                        'failed' => ['node'],
                        'footer' => 'Tool update failed',
                    ],
                ],
            ]);
    });

    it('rejects --json and --stream-json together before opening the stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:new', [
            'name' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
            '--stream-json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->not
            ->toHaveKey('event')
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])
            ->toBe(['json', 'stream-json']);

        Http::assertNothingSent();
    });

    it('keeps pre-stream validation failures as plain JSON envelopes in --stream-json mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:new', [
            '--node' => 'app-1',
            '--stream-json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->not
            ->toHaveKey('event')
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('name');

        Http::assertNothingSent();
    });

    it('emits stream error frames for gateway transport failures after progress has started', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Tree, 'payload' => ['title' => 'Setting up']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--stream-json' => true]);

        $frames = array_map(
            fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $output)),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($frames)
            ->toHaveCount(2)
            ->and($frames[0]['event'])
            ->toBe('tree')
            ->and($frames[1]['event'])
            ->toBe('error')
            ->and($frames[1]['error']['code'])
            ->toBe('gateway_unavailable')
            ->and($frames[1]['error']['meta'])
            ->toBeArray();
    });

    it('emits terminal error frames with canonical error payloads in --stream-json mode', function (): void {
        fakeStreamClient([
            [
                'type' => ProgressEventType::Error,
                'payload' => [
                    'exit_code' => 1,
                    'message' => 'clone failed',
                    'data' => [
                        'code' => 'workspace.clone_failed',
                        'message' => 'clone failed',
                        'meta' => ['step' => 'clone'],
                        'data' => ['stdout' => 'fatal'],
                    ],
                ],
            ],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--stream-json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe([
                'event' => 'error',
                'error' => [
                    'code' => 'workspace.clone_failed',
                    'message' => 'clone failed',
                    'meta' => ['step' => 'clone'],
                    'data' => ['stdout' => 'fatal'],
                ],
            ]);
    });

    it('accepts --stream-json on eligible gateway streaming commands', function (
        string $command,
        array $params,
        string $method,
        string $url,
    ): void {
        fakeGatewayProgressStream(
            gatewayProgressFrame('tree', ['title' => 'Working'])
                .gatewayProgressFrame('step', ['key' => 'run', 'status' => 'running'])
                .gatewayProgressFrame('complete', [
                    'exit_code' => 0,
                    'data' => ['result' => ['ok' => true]],
                ]),
        );

        [$exitCode, $output] = runCommand($this, $command, [
            ...$params,
            '--stream-json' => true,
        ]);

        $frames = array_map(
            fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $output)),
        );

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === $method
                && $request->url() === "https://gateway.test{$url}"
                && $request->hasHeader('Accept', 'text/event-stream')
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($frames)
            ->toHaveCount(3)
            ->and($frames[0]['event'])
            ->toBe('tree')
            ->and($frames[1]['event'])
            ->toBe('step')
            ->and($frames[2])
            ->toBe([
                'event' => 'complete',
                'success' => [
                    'data' => ['result' => ['ok' => true]],
                    'meta' => [],
                ],
            ]);
    })->with([
        'app:new' => [
            'app:new',
            ['name' => 'docs', '--node' => 'app-1', '--repo' => 'hardimpact/docs'],
            'POST',
            '/api/apps',
        ],
        'workspace:new' => [
            'workspace:new',
            ['name' => 'feature-docs', '--instance' => 'docs'],
            'POST',
            '/api/workspaces',
        ],
        'workspace:setup' => [
            'workspace:setup',
            ['name' => 'feature-docs', '--instance' => 'docs'],
            'POST',
            '/api/workspaces/setup',
        ],
        'tool:install' => [
            'tool:install',
            ['tool' => 'composer', '--node' => 'app-1'],
            'POST',
            '/api/tools/composer/install',
        ],
        'tool:update' => [
            'tool:update',
            ['tool' => 'composer', '--node' => 'app-1'],
            'POST',
            '/api/tools/composer/update',
        ],
        'tool:reconfigure' => [
            'tool:reconfigure',
            ['tool' => 'opencode-cli', '--node' => 'app-1'],
            'POST',
            '/api/tools/opencode-cli/reconfigure',
        ],
        's3:publish' => [
            's3:publish',
            ['host' => 's3.example.com', '--node' => 'storage-1'],
            'POST',
            '/api/s3/public-hosts',
        ],
        's3:unpublish' => [
            's3:unpublish',
            ['host' => 's3.example.com', '--node' => 'storage-1', '--force' => true],
            'DELETE',
            '/api/s3/public-hosts/s3.example.com',
        ],
    ]);

    it('rejects ambiguous JSON mode before gateway IO on eligible commands', function (
        string $command,
        array $params,
    ): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            ...$params,
            '--json' => true,
            '--stream-json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->not
            ->toHaveKey('event')
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])
            ->toBe(['json', 'stream-json']);

        Http::assertNothingSent();
    })->with([
        'app:new' => ['app:new', ['name' => 'docs', '--node' => 'app-1']],
        'workspace:new' => ['workspace:new', ['name' => 'feature-docs', '--instance' => 'docs']],
        'workspace:setup' => ['workspace:setup', ['name' => 'feature-docs', '--instance' => 'docs']],
        'node:new' => [
            'node:new',
            ['name' => 'app-1', '--roles' => 'app-dev', '--host' => '192.0.2.20', '--tld' => 'test'],
        ],
        'deploy:run' => ['deploy:run', ['instance' => 'docs']],
        'tool:install' => ['tool:install', ['tool' => 'composer', '--node' => 'app-1']],
        'tool:update' => ['tool:update', ['tool' => 'composer', '--node' => 'app-1']],
        'tool:reconfigure' => ['tool:reconfigure', ['tool' => 'opencode-cli', '--node' => 'app-1']],
        's3:publish' => ['s3:publish', ['host' => 's3.example.com', '--node' => 'storage-1']],
        's3:unpublish' => ['s3:unpublish', ['host' => 's3.example.com', '--node' => 'storage-1', '--force' => true]],
    ]);

    it('renders intermediate frames as an animated tree in human mode', function (): void {
        fakeStreamClient([
            [
                'type' => ProgressEventType::Tree,
                'payload' => [
                    'title' => 'Setting up',
                    'steps' => [
                        ['key' => 'install', 'label' => 'Install packages', 'doneLabel' => 'Installed packages'],
                    ],
                ],
            ],
            [
                'type' => ProgressEventType::Step,
                'payload' => ['key' => 'install', 'status' => 'progress', 'message' => 'installing packages'],
            ],
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'install', 'status' => 'done']],
            ['type' => ProgressEventType::Complete, 'payload' => ['footer' => 'Setup complete.']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Setting up')
            ->and($output)
            ->toContain('installing packages')
            ->and($output)
            ->toContain('Installed packages')
            ->and($output)
            ->toContain('Setup complete.')
            ->and($output)
            ->not->toContain('[tree]')->and($output)
            ->not->toContain('[step]');
    });

    it('settles the human tree as failed for a nonzero complete frame', function (): void {
        fakeStreamClient([
            [
                'type' => ProgressEventType::Tree,
                'payload' => [
                    'title' => 'Updating tools',
                    'steps' => [['key' => 'update', 'label' => 'Update tools']],
                ],
            ],
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'update', 'status' => 'fail']],
            [
                'type' => ProgressEventType::Complete,
                'payload' => ['exit_code' => 1, 'data' => ['footer' => 'Tool update failed']],
            ],
        ]);

        [$exitCode, $output] = runStreamingCommand($this);

        expect($exitCode)->toBe(1)->and($output)->toContain('Tool update failed');
    });

    it('surfaces gateway_unavailable when the stream closes before a terminal frame', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Step, 'payload' => ['message' => 'still running']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns failure exit code and failure envelope on error frame in JSON mode', function (): void {
        fakeStreamClient([
            ['type' => ProgressEventType::Error, 'payload' => ['message' => 'clone failed']],
        ]);

        [$exitCode, $output] = runStreamingCommand($this, ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe([
                'event' => 'error',
                'data' => ['message' => 'clone failed'],
            ]);
    });
});
