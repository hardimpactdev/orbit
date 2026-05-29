<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('deploy interactive input mode', function (): void {
    it('prompts for app and command before adding a deployment step', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'app' => 'docs',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force',
            ],
        ]));

        $this->artisan('deploy:step-add')
            ->expectsQuestion('App', 'docs')
            ->expectsQuestion('Command', 'php artisan migrate --force')
            ->expectsOutputToContain('step')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/deploy/steps')
            && $request->data()['app'] === 'docs'
            && $request->data()['command'] === 'php artisan migrate --force');
    });

    it('prompts for app before running a deployment', function (): void {
        fakeGatewayProgressStream(gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'footer' => 'Deployment completed',
                'run' => ['id' => 43, 'app' => 'docs', 'status' => 'completed'],
            ],
        ]));

        $this->artisan('deploy:run')
            ->expectsQuestion('App', 'docs')
            ->expectsOutputToContain('Deployment completed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/deploy/run')
            && $request->data() === [
                'app' => 'docs',
                'detach' => false,
            ]);
    });

    it('prompts for app and step before removing with confirmation', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'step' => [
                'id' => 12,
                'app' => 'docs',
                'title' => 'Run migrations',
            ],
        ]));

        $this->artisan('deploy:step-remove')
            ->expectsQuestion('App', 'docs')
            ->expectsQuestion('Step', 'Run migrations')
            ->expectsConfirmation("Remove deployment step 'Run migrations' from 'docs'?", 'yes')
            ->expectsOutputToContain('step')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/deploy/steps/Run%20migrations')
            && $request->data() === [
                'app' => 'docs',
                'destructive_consent' => true,
            ]);
    });

    it('prompts for app and run before reading deployment logs', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 1,
                    'title' => 'Deploy',
                    'output' => ['stdout' => "deployed\n", 'stderr' => ''],
                ],
            ],
        ], [
            'app' => 'docs',
            'run' => 42,
            'lines' => 100,
        ]));

        $this->artisan('deploy:log')
            ->expectsQuestion('App', 'docs')
            ->expectsQuestion('Run', '42')
            ->expectsOutputToContain('deployed')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/deploy/log/42')
                && str_contains($url, 'app=docs');
        });
    });
});
