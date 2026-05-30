<?php

declare(strict_types=1);

use App\Commands\Schedule\ScheduleLogsCommand;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Tester\CommandTester;

describe('schedule:logs', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'run' => ['id' => 18, 'schedule' => 'laravel-scheduler', 'status' => 'completed'],
            'output' => ['stdout' => "ok\n", 'stderr' => "warning\n"],
        ], ['lines' => 10, 'truncated' => false]));

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
            '--run' => 18,
            '--lines' => 10,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/schedules/laravel-scheduler/logs')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'run=18')
                && str_contains($url, 'lines=10');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['run']['id'])->toBe(18)
            ->and($decoded['success']['data']['output']['stdout'])->toBe("ok\n")
            ->and($decoded['success']['meta']['lines'])->toBe(10);
    });

    it('renders captured stdout and stderr for human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'run' => ['id' => 18, 'schedule' => 'laravel-scheduler', 'status' => 'completed'],
            'output' => ['stdout' => "ok\n", 'stderr' => "warning\n"],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('stdout')
            ->and($output)->toContain('ok')
            ->and($output)->toContain('stderr')
            ->and($output)->toContain('warning');
    });

    it('prompts for a visible schedule when interactive name input is omitted', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $query = parse_url($request->url(), PHP_URL_QUERY) ?? '';
            parse_str($query, $parameters);

            if ($request->method() === 'GET' && $path === '/api/schedules') {
                return Http::response(fakeSuccessEnvelope([
                    'schedules' => [
                        [
                            'name' => 'laravel-scheduler',
                            'scope' => 'app',
                            'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                        ],
                    ],
                ]));
            }

            if (
                $request->method() === 'GET'
                && $path === '/api/schedules/laravel-scheduler/logs'
                && ($parameters['app'] ?? null) === 'docs'
                && ($parameters['lines'] ?? null) === '100'
            ) {
                return Http::response(fakeSuccessEnvelope([
                    'run' => ['id' => 18, 'schedule' => 'laravel-scheduler', 'status' => 'completed'],
                    'output' => ['stdout' => "ok\n", 'stderr' => ''],
                ]));
            }

            return Http::response(fakeErrorEnvelope('unexpected_request', 'Unexpected gateway request.'), 500);
        });

        $command = app(ScheduleLogsCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        Prompt::fake([Key::ENTER]);

        $exitCode = $tester->execute([]);

        Http::assertSentCount(2);

        expect($exitCode)->toBe(0)
            ->and($tester->getDisplay())->toContain('ok');
    });

    it('fails validation before opening the gateway request when name is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:logs', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('fails validation before opening the gateway request when app and node filters are combined', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])->toBe(['app', 'node']);
    });

    it('fails validation before opening the gateway request when run is invalid', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--run' => 0,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('run');
    });

    it('fails validation before opening the gateway request when lines is invalid', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--lines' => 0,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('lines');
    });

    it('passes through gateway schedule run not found errors', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.run_not_found', 'Schedule run not found.', [
            'name' => 'laravel-scheduler',
            'run' => 19,
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--run' => 19,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('schedule.run_not_found')
            ->and($decoded['error']['meta']['run'])->toBe(19);
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'schedule:logs', [
            'name' => 'laravel-scheduler',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
