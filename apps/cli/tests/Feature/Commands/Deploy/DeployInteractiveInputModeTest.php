<?php

declare(strict_types=1);

use App\Services\GatewayOperationStreamSubscriber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('deploy interactive input mode', function (): void {
    it('prompts for instance and command before adding a deployment step', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'instance' => 'docs',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force',
            ],
        ]));

        $this
            ->artisan('deploy:step-add')
            ->expectsQuestion('Instance', 'docs')
            ->expectsQuestion('Command', 'php artisan migrate --force')
            ->expectsOutputToContain('step')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && str_contains($request->url(), '/api/deploy/steps')
                && $request->data()['instance'] === 'docs'
                && $request->data()['command'] === 'php artisan migrate --force'
            ),
        );
    });

    it('prompts for instance before running a deployment', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'operation' => [
                'uuid' => 'deploy-operation-interactive',
                'stream_descriptor_url' => '/api/operations/deploy-operation-interactive/stream',
                'events_url' => '/api/operations/deploy-operation-interactive/events',
            ],
        ]), 202);
        app()->instance(
            GatewayOperationStreamSubscriber::class,
            new class extends GatewayOperationStreamSubscriber {
                public function __construct() {}

                #[\Override]
                public function subscribe(string $operationRunId, ?int $lastReplayCursor, callable $onFrame): void
                {
                    expect($operationRunId)
                        ->toBe('deploy-operation-interactive')
                        ->and($lastReplayCursor)
                        ->toBeNull();

                    $onFrame([
                        'type' => 'complete',
                        'payload' => [
                            'exit_code' => 0,
                            'data' => [
                                'footer' => 'Deployment completed',
                                'run' => ['id' => 43, 'instance' => 'docs', 'status' => 'completed'],
                            ],
                        ],
                    ]);
                }
            },
        );

        $this
            ->artisan('deploy:run')
            ->expectsQuestion('Instance', 'docs')
            ->expectsOutputToContain('Deployment completed')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && str_contains($request->url(), '/api/deploy/run')
                && $request->data() === [
                    'instance' => 'docs',
                    'detach' => false,
                ]
                && ! $request->hasHeader('Accept', 'text/event-stream')
            ),
        );
    });

    it('prompts for instance and step before removing with confirmation', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'instance' => 'docs',
                'title' => 'Run migrations',
            ],
        ]));

        $this
            ->artisan('deploy:step-remove')
            ->expectsQuestion('Instance', 'docs')
            ->expectsQuestion('Step', 'Run migrations')
            ->expectsConfirmation("Remove deployment step 'Run migrations' from 'docs'?", 'yes')
            ->expectsOutputToContain('step')
            ->assertSuccessful();

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/deploy/steps/Run%20migrations')
                && $request->data() === [
                    'instance' => 'docs',
                    'destructive_consent' => true,
                ]
            ),
        );
    });

    it('prompts for instance and run before reading deployment logs', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 1,
                    'title' => 'Deploy',
                    'output' => ['stdout' => "deployed\n", 'stderr' => ''],
                ],
            ],
        ], [
            'instance' => 'docs',
            'run' => 42,
            'lines' => 100,
        ]));

        $this
            ->artisan('deploy:log')
            ->expectsQuestion('Instance', 'docs')
            ->expectsQuestion('Run', '42')
            ->expectsOutputToContain('deployed')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/deploy/log/42')
                && str_contains($url, 'instance=docs')
            );
        });
    });
});
