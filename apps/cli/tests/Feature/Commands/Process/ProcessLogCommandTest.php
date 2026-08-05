<?php

declare(strict_types=1);

use App\Services\GatewayOperationStreamSubscriber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('process:log', function (): void {
    it('is registered and process:logs is not a public command', function (): void {
        $commands = Artisan::all();

        expect(array_key_exists('process:log', $commands))
            ->toBeTrue()
            ->and(array_key_exists('process:logs', $commands))
            ->toBeFalse();
    });

    it('returns bounded logs as a canonical success envelope and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'runtime_unit' => 'orbit_docs_main_vite',
                'lines' => [
                    [
                        'timestamp' => '2026-04-30T12:00:00Z',
                        'message' => 'Vite ready',
                    ],
                ],
            ],
        ], ['line_count' => 1]));

        [$exitCode, $output] = runCommand($this, 'process:log', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--workspace' => 'feature-docs',
            '--lines' => 5,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/processes/vite/log')
                && str_contains($url, 'instance=docs')
                && str_contains($url, 'workspace=feature-docs')
                && str_contains($url, 'lines=5')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['logs']['runtime_unit'])
            ->toBe('orbit_docs_main_vite')
            ->and($decoded['success']['meta']['line_count'])
            ->toBe(1);
    });

    it('rejects json output for followed logs before opening a gateway stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'process:log', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--follow' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed');
    });

    it('streams followed logs through the operation websocket surface by default', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->instance(GatewayOperationStreamSubscriber::class, new ProcessLogFakeOperationStreamSubscriber([
            [
                'type' => 'stdout',
                'payload' => ['data' => "one\n"],
            ],
            [
                'type' => 'stdout',
                'payload' => ['data' => "two\n"],
            ],
        ]));

        Http::fake([
            'https://gateway.test/*' => Http::response(fakeSuccessEnvelope([
                'operation' => [
                    'uuid' => 'run-1',
                    'stream_descriptor_url' => '/api/operations/run-1/stream',
                ],
            ]), 202),
        ]);

        [$exitCode, $output] = runCommand($this, 'process:log', [
            'name' => 'vite',
            '--instance' => 'docs',
            '--follow' => true,
            '--lines' => 5,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'POST'
                && str_contains($url, '/api/processes/vite/log-stream')
                && $request['instance'] === 'docs'
                && $request['lines'] === 5
            );
        });

        expect($exitCode)->toBe(0)->and($output)->toBe("one\ntwo");
    });
});

class ProcessLogFakeOperationStreamSubscriber extends GatewayOperationStreamSubscriber
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
    #[\Override]
    public function subscribe(string $operationRunId, ?int $lastReplayCursor, callable $onFrame): void
    {
        foreach ($this->frames as $frame) {
            $onFrame($frame);
        }
    }
}
