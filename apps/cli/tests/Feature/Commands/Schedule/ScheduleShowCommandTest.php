<?php

declare(strict_types=1);

use App\Commands\Schedule\ScheduleShowCommand;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Tester\CommandTester;

describe('schedule:show', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedule' => [
                'name' => 'laravel-scheduler',
                'scope' => 'app',
                'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                'timezone' => 'Europe/Amsterdam',
            ],
        ], ['app' => 'docs', 'node' => null]));

        [$exitCode, $output] = runCommand($this, 'schedule:show', [
            'name' => 'laravel-scheduler',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/schedules/laravel-scheduler')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['schedule']['name'])->toBe('laravel-scheduler')
            ->and($decoded['success']['meta']['app'])->toBe('docs');
    });

    it('renders human output containing schedule fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedule' => [
                'name' => 'laravel-scheduler',
                'scope' => 'app',
                'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                'interval' => 'daily',
                'timezone' => 'Europe/Amsterdam',
                'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                'enabled' => true,
                'status' => 'expected',
                'last_run' => null,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:show', ['name' => 'laravel-scheduler']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Schedule: laravel-scheduler')
            ->and($output)->toContain('Target')
            ->and($output)->toContain('docs')
            ->and($output)->toContain('Execution')
            ->and($output)->toContain('command: php artisan schedule:run')
            ->and($output)->not->toContain('schedule: {');
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

            if ($request->method() === 'GET' && $path === '/api/schedules/laravel-scheduler' && ($parameters['app'] ?? null) === 'docs') {
                return Http::response(fakeSuccessEnvelope([
                    'schedule' => [
                        'name' => 'laravel-scheduler',
                        'scope' => 'app',
                    ],
                ]));
            }

            return Http::response(fakeErrorEnvelope('unexpected_request', 'Unexpected gateway request.'), 500);
        });

        $command = app(ScheduleShowCommand::class);
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        Prompt::fake([Key::ENTER]);

        $exitCode = $tester->execute([]);

        Http::assertSentCount(2);

        expect($exitCode)->toBe(0)
            ->and($tester->getDisplay())->toContain('laravel-scheduler');
    });

    it('fails validation before opening the gateway request when name is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('name');
    });

    it('fails validation before opening the gateway request when app and node filters are combined', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:show', [
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

    it('passes through gateway schedule not found errors', function (): void {
        fakeGateway(fakeErrorEnvelope('schedule.not_found', 'Schedule not found.', [
            'name' => 'missing',
            'app' => 'docs',
            'node' => null,
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'schedule:show', [
            'name' => 'missing',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('schedule.not_found')
            ->and($decoded['error']['meta']['name'])->toBe('missing');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'schedule:show', [
            'name' => 'laravel-scheduler',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});
