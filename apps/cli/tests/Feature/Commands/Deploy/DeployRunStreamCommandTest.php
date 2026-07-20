<?php

declare(strict_types=1);

use App\Services\GatewayOperationStreamSubscriber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('deploy:run operation stream', function (): void {
    beforeEach(function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
    });

    it('subscribes to durable deployment progress over the operations WebSocket plane', function (): void {
        fakeDeployOperationStart();
        fakeDeployOperationFrames([
            [
                'type' => 'tree',
                'payload' => [
                    'title' => 'Running Deployment',
                    'steps' => [['key' => 'pull-source', 'label' => 'Pull source']],
                ],
            ],
            [
                'type' => 'step',
                'payload' => ['key' => 'pull-source', 'status' => 'done'],
            ],
            [
                'type' => 'complete',
                'payload' => [
                    'exit_code' => 0,
                    'data' => [
                        'run' => ['id' => 43, 'instance' => 'docs', 'status' => 'completed'],
                        'footer' => 'Deployment completed',
                    ],
                ],
            ],
        ]);

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/deploy/run'
                && $request->data() === ['instance' => 'docs', 'detach' => false]
                && ! $request->hasHeader('Accept', 'text/event-stream')
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe([
                'event' => 'complete',
                'data' => [
                    'exit_code' => 0,
                    'data' => [
                        'run' => ['id' => 43, 'instance' => 'docs', 'status' => 'completed'],
                        'footer' => 'Deployment completed',
                    ],
                ],
            ]);
    });

    it('preserves newline-delimited progress frames in stream JSON mode', function (): void {
        fakeDeployOperationStart();
        fakeDeployOperationFrames([
            ['type' => 'tree', 'payload' => ['title' => 'Running Deployment', 'steps' => []]],
            ['type' => 'step', 'payload' => ['key' => 'resolve-instance', 'status' => 'done']],
            [
                'type' => 'complete',
                'payload' => [
                    'exit_code' => 0,
                    'data' => ['run' => ['id' => 43], 'footer' => 'Deployment completed'],
                ],
            ],
        ]);

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            'instance' => 'docs',
            '--stream-json' => true,
        ]);

        $frames = array_map(
            fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", $output)),
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
                    'data' => ['run' => ['id' => 43], 'footer' => 'Deployment completed'],
                    'meta' => [],
                ],
            ]);
    });

    it('fails when the operation stream contains a malformed frame', function (): void {
        fakeDeployOperationStart();
        fakeDeployOperationFrames([['type' => 'not-a-progress-frame', 'payload' => []]]);

        [$exitCode, $output] = runCommand($this, 'deploy:run', [
            'instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('gateway_unavailable')
            ->and($decoded['error']['message'])
            ->toContain('malformed');
    });
});

function fakeDeployOperationStart(): void
{
    Http::fake([
        'https://gateway.test/api/deploy/run' => Http::response(fakeSuccessEnvelope([
            'operation' => [
                'uuid' => 'deploy-operation-1',
                'stream_descriptor_url' => '/api/operations/deploy-operation-1/stream',
                'events_url' => '/api/operations/deploy-operation-1/events',
            ],
        ]), 202),
    ]);
}

/**
 * @param  list<array<string, mixed>>  $frames
 */
function fakeDeployOperationFrames(array $frames): void
{
    app()->instance(
        GatewayOperationStreamSubscriber::class,
        new DeployRunFakeOperationStreamSubscriber($frames),
    );
}

final class DeployRunFakeOperationStreamSubscriber extends GatewayOperationStreamSubscriber
{
    /**
     * @param  list<array<string, mixed>>  $frames
     */
    public function __construct(
        private readonly array $frames,
    ) {}

    /**
     * @param  callable(array<string, mixed>): void  $onFrame
     */
    #[Override]
    public function subscribe(string $operationRunId, ?int $lastReplayCursor, callable $onFrame): void
    {
        expect($operationRunId)->toBe('deploy-operation-1')->and($lastReplayCursor)->toBeNull();

        foreach ($this->frames as $frame) {
            $onFrame($frame);
        }
    }
}
