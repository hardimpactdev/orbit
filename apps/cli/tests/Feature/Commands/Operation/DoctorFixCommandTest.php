<?php

declare(strict_types=1);

use App\Commands\Operation\DoctorCommand;
use App\Services\GatewayApiClient;
use App\Services\GatewayLogStreamClient;
use App\Services\GatewayStreamClient;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('doctor --fix', function (): void {
    it('rejects json interactive mode before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--fix' => true,
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('fix');

        Http::assertNothingSent();
    });

    it('rejects interactive fix mode without a tty', function (): void {
        Http::fake();

        $command = app(DoctorCommand::class);
        $command->setLaravel(app());
        $input = new ArrayInput(['--fix' => true]);
        $input->setInteractive(false);
        $output = new BufferedOutput;
        $exitCode = $command->run($input, $output);

        expect($exitCode)->toBe(1)
            ->and($output->fetch())->toContain('doctor --fix requires an interactive terminal');

        Http::assertNothingSent();
    });

    it('prompts for restorable findings and streams selected issues to the fix endpoint', function (): void {
        $issue = doctorFixIssue([
            'family' => 'node',
            'key' => 'node.config',
            'summary' => 'Node config is missing.',
            'restorable' => true,
        ]);

        fakeDoctorFixGatewayStreams(
            runBody: doctorFixDriftStream(doctorFixPayload([$issue])),
            fixBodies: [
                doctorFixCompleteStream(doctorFixPayload([], [
                    [
                        'family' => 'node',
                        'node' => 'app-1',
                        'key' => 'node.config',
                        'mode' => 'restore',
                        'status' => 'completed',
                        'summary' => 'Node config restored.',
                    ],
                ], true)),
            ],
        );

        $this->artisan('doctor --fix --node=app-1 --family=node')
            ->expectsQuestion('Resolve Node config is missing.', 'restore')
            ->expectsOutputToContain('Running Doctor')
            ->expectsOutputToContain('Doctor completed.')
            ->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/api/doctor/run'
            && $request->data() === [
                'mode' => 'verify',
                'families' => ['node'],
                'node' => 'app-1',
            ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/api/doctor/fix'
            && $request->data() === [
                'mode' => 'restore',
                'families' => ['node'],
                'node' => 'app-1',
                'issues' => [$issue],
            ]);
    });

    it('shows details then lets the operator skip without sending a fix request', function (): void {
        $issue = doctorFixIssue([
            'family' => 'proxy',
            'key' => 'proxy.route_mismatch',
            'summary' => 'Proxy route differs.',
            'restorable' => true,
            'detail' => [
                'domain' => 'docs.test',
                'expected' => 'http://127.0.0.1:8080',
                'actual' => 'http://127.0.0.1:5173',
            ],
        ]);

        fakeDoctorFixGatewayStreams(
            runBody: doctorFixDriftStream(doctorFixPayload([$issue])),
            fixBodies: [],
        );

        $this->artisan('doctor --fix --node=app-1 --family=proxy')
            ->expectsQuestion('Resolve Proxy route differs.', 'details')
            ->expectsOutputToContain('expected: http://127.0.0.1:8080')
            ->expectsQuestion('Resolve Proxy route differs.', 'skip')
            ->assertExitCode(1);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/api/doctor/run');
    });

    it('treats prompt cancellation as a validation failure without sending a fix request', function (): void {
        $issue = doctorFixIssue([
            'summary' => 'Node config is missing.',
            'restorable' => true,
        ]);

        fakeDoctorFixGatewayStreams(
            runBody: doctorFixDriftStream(doctorFixPayload([$issue])),
            fixBodies: [],
        );

        $output = new BufferedOutput;
        $style = Mockery::mock(OutputStyle::class.'[askQuestion]', [
            new ArrayInput(['--fix' => true, '--node' => 'app-1', '--family' => ['node']]),
            $output,
        ])->makePartial();
        $style->shouldReceive('askQuestion')->once()->andThrow(new RuntimeException('cancelled'));

        $exitCode = app(Kernel::class)->call('doctor', [
            '--fix' => true,
            '--node' => 'app-1',
            '--family' => ['node'],
        ], $style);

        expect($exitCode)->toBe(1)
            ->and($output->fetch())->toContain('validation_failed: Operation cancelled.');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/api/doctor/run');
    });
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function doctorFixIssue(array $overrides = []): array
{
    return array_merge([
        'family' => 'node',
        'node' => 'app-1',
        'kind' => 'missing',
        'code' => 'node.config_missing',
        'key' => 'node.config',
        'summary' => 'Node config is missing.',
        'restorable' => false,
        'adoptable' => false,
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $issues
 * @param  list<array<string, mixed>>  $actions
 * @return array<string, mixed>
 */
function doctorFixPayload(array $issues, array $actions = [], bool $healthy = false): array
{
    return [
        'mode' => $actions === [] ? 'verify' : 'restore',
        'healthy' => $healthy,
        'scope' => [
            'self' => false,
            'node' => 'app-1',
            'app' => null,
            'workspace' => null,
            'key' => null,
            'families' => ['node'],
        ],
        'summary' => [
            'issues' => count($issues),
            'fixed' => count(array_filter($actions, fn (array $action): bool => ($action['mode'] ?? null) === 'restore')),
            'adopted' => count(array_filter($actions, fn (array $action): bool => ($action['mode'] ?? null) === 'adopt')),
            'skipped' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'planned' => 0,
        ],
        'issues' => $issues,
        'actions' => $actions,
    ];
}

/**
 * @param  array<string, mixed>  $doctor
 */
function doctorFixDriftStream(array $doctor): string
{
    return gatewayProgressFrame('tree', [
        'title' => 'Running Doctor',
        'steps' => [['key' => 'node', 'label' => 'Check node']],
    ]).gatewayProgressFrame('error', [
        'exit_code' => 1,
        'message' => 'Doctor detected drift.',
        'data' => [
            'code' => 'drift_detected',
            'message' => 'Doctor detected drift.',
            'meta' => [],
            'data' => ['doctor' => $doctor],
            'footer' => 'Doctor detected drift.',
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $doctor
 */
function doctorFixCompleteStream(array $doctor): string
{
    return gatewayProgressFrame('tree', [
        'title' => 'Running Doctor',
        'steps' => [['key' => 'node', 'label' => 'restore node']],
    ]).gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => [
            'footer' => 'Doctor completed.',
            'doctor' => $doctor,
        ],
    ]);
}

/**
 * @param  list<string>  $fixBodies
 */
function fakeDoctorFixGatewayStreams(string $runBody, array $fixBodies): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayStreamClient::class);

    $fixIndex = 0;

    Http::fake(function (Request $request) use ($runBody, $fixBodies, &$fixIndex) {
        if ($request->url() === 'https://gateway.test/api/doctor/run') {
            return Http::response($runBody, 200, ['Content-Type' => 'text/event-stream']);
        }

        if ($request->url() === 'https://gateway.test/api/doctor/fix') {
            $body = $fixBodies[$fixIndex] ?? end($fixBodies) ?: '';
            $fixIndex++;

            return Http::response($body, 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response('', 404);
    });
}
