<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\LocalExecutorCommandComposer;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenEnvironment;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Tests\Fakes\RemoteLocalExecutorCountingCommands;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @mago-expect lint:cyclomatic-complexity */
describe(RemoteLocalExecutor::class, function (): void {
    it('runs gateway-targeted node execution through the local gateway runtime by default', function (): void {
        Http::preventStrayRequests();
        ProcessFacade::preventStrayProcesses();
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "gateway-ok\n"),
        ]);
        $sshTransport = new RemoteLocalExecutorRecordingTransport(
            static fn (): RemoteShellResult => throw new RuntimeException(
                'SSH transport must never be called for a gateway target by default.',
            ),
        );
        $executor =
            remoteLocalExecutor(
                transport: $sshTransport,
            );
        $node = remoteLocalExecutorNode(['gateway']);

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:executor:verify',
            transportOptions: [
                'input' => '{"round_trip":true}',
                'environment' => [
                    'ORBIT_TRUSTED_EXECUTION_LANE' => 'attacker-controlled',
                ],
                'metadata' => [
                    'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000428',
                ],
            ],
        );

        expect($result->stdout)
            ->toBe("gateway-ok\n")
            ->and($sshTransport->calls)
            ->toBeEmpty();

        Http::assertNothingSent();
        ProcessFacade::assertRan(function (PendingProcess $process): bool {
            $command = (string) $process->command;

            return (
                str_contains($command, 'docker exec -i')
                && str_contains($command, 'orbit-gateway')
                && str_contains($command, 'internal:executor:verify')
                && str_contains($command, 'ORBIT_TRUSTED_EXECUTION_LANE=gateway-local')
                && ! str_contains($command, 'attacker-controlled')
                && ! str_contains($command, ' ssh ')
            );
        });

        expect(DB::table('operation_runs')->value('operation_token_consumed_at'))
            ->not->toBeNull();
    });

    it('forces host-owned gateway commands onto the gateway host boundary', function (): void {
        $previousExposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        Http::preventStrayRequests();
        ProcessFacade::preventStrayProcesses();
        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "gateway-host-ok\n"),
        ]);

        try {
            $executor = remoteLocalExecutor(
                transport: new RemoteLocalExecutorRecordingTransport(
                    static fn (): RemoteShellResult => throw new RuntimeException(
                        'Agent-push transport must not be used for forced gateway host work.',
                    ),
                ),
            );
            $node = remoteLocalExecutorNode(['gateway']);

            $result = $executor->runInternal(
                node: $node,
                commandName: 'internal:wireguard-self-route',
                arguments: ['10.6.0.2'],
                transportOptions: [
                    'force_remote_host' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000429',
                    ],
                ],
            );

            expect($result->stdout)->toBe("gateway-host-ok\n");
            ProcessFacade::assertRan(function (PendingProcess $process): bool {
                $command = (string) $process->command;

                return (
                    str_contains($command, 'internal:wireguard-self-route')
                    && ! str_contains($command, 'docker exec -i')
                    && (str_contains($command, 'ssh ') || str_starts_with($command, 'bash -c '))
                );
            });
        } finally {
            if ($previousExposureMode === false) {
                putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
            } else {
                putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previousExposureMode}");
            }
        }
    });

    it('does not pre-authorize force_remote_host gateway work so the host CLI remains the sole token consumer', function (): void {
        $previousExposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        Http::preventStrayRequests();
        ProcessFacade::preventStrayProcesses();
        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "gateway-host-token-ok\n"),
        ]);

        try {
            $executor = remoteLocalExecutor(
                transport: new RemoteLocalExecutorRecordingTransport(
                    static fn (): RemoteShellResult => throw new RuntimeException(
                        'Agent-push transport must not be used for forced gateway host work.',
                    ),
                ),
            );
            $node = remoteLocalExecutorNode(['gateway']);
            $operationId = '00000000-0000-4000-8000-000000000430';

            $result = $executor->runInternal(
                node: $node,
                commandName: 'internal:wireguard-self-route',
                arguments: ['10.6.0.2'],
                transportOptions: [
                    'force_remote_host' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operationId,
                    ],
                ],
            );

            expect($result->stdout)->toBe("gateway-host-token-ok\n");

            $hostBoundaryDispatched = false;
            ProcessFacade::assertRan(function (PendingProcess $process) use (&$hostBoundaryDispatched): bool {
                $command = (string) $process->command;
                $matches =
                    str_contains($command, 'internal:wireguard-self-route')
                    && str_contains($command, '--operation-token=')
                    && ! str_contains($command, 'docker exec -i')
                    && (str_contains($command, 'ssh ') || str_starts_with($command, 'bash -c '))
                    && ! str_contains($command, 'ORBIT_TRUSTED_EXECUTION_LANE=');

                if ($matches) {
                    $hostBoundaryDispatched = true;
                }

                return $matches;
            });

            expect($hostBoundaryDispatched)->toBeTrue();

            $row = remote_local_executor_operation_run($operationId);

            // ProcessFacade fakes the host process, so the host CLI never
            // verifies/consumes. Pre-auth would wrongly mark the row consumed.
            expect($row->operation_token_consumed_at)->toBeNull();
        } finally {
            if ($previousExposureMode === false) {
                putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
            } else {
                putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previousExposureMode}");
            }
        }
    });

    it('uses the installed host orbit binary for force_remote_host instead of container orbit-cli', function (): void {
        $previousExposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        $previousBinary = config('orbit.local_executor_binary');
        Http::preventStrayRequests();
        ProcessFacade::preventStrayProcesses();
        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');
        config()->set('orbit.local_executor_binary', '/usr/local/bin/orbit-cli');
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "gateway-host-binary-ok\n"),
        ]);

        try {
            $executor = remoteLocalExecutor(
                transport: new RemoteLocalExecutorRecordingTransport(
                    static fn (): RemoteShellResult => throw new RuntimeException(
                        'Agent-push transport must not be used for forced gateway host work.',
                    ),
                ),
            );
            $node = remoteLocalExecutorNode(['gateway']);
            $capturedCommand = null;

            $result = $executor->runInternal(
                node: $node,
                commandName: 'internal:app-runtime-containers:probe',
                transportOptions: [
                    'force_remote_host' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000440',
                    ],
                ],
            );

            expect($result->stdout)->toBe("gateway-host-binary-ok\n");

            ProcessFacade::assertRan(function (PendingProcess $process) use (&$capturedCommand): bool {
                $command = (string) $process->command;
                $matches =
                    str_contains($command, 'internal:app-runtime-containers:probe')
                    && str_contains($command, '--operation-token=')
                    && (str_contains($command, 'ssh ') || str_starts_with($command, 'bash -c '));

                if ($matches) {
                    $capturedCommand = $command;
                }

                return $matches;
            });

            expect($capturedCommand)
                ->toBeString()
                ->and($capturedCommand)
                ->toContain('/home/orbit/.local/bin/orbit')
                ->and($capturedCommand)
                ->not->toContain('/usr/local/bin/orbit-cli');
        } finally {
            config()->set('orbit.local_executor_binary', $previousBinary);

            if ($previousExposureMode === false) {
                putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
            } else {
                putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previousExposureMode}");
            }
        }
    });

    it('mints force_remote_host command context matching the host CLI verification payload including bound input', function (): void {
        $previousExposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        Http::preventStrayRequests();
        ProcessFacade::preventStrayProcesses();
        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "gateway-host-context-ok\n"),
        ]);

        try {
            $executor = remoteLocalExecutor(
                transport: new RemoteLocalExecutorRecordingTransport(
                    static fn (): RemoteShellResult => throw new RuntimeException(
                        'Agent-push transport must not be used for forced gateway host work.',
                    ),
                ),
            );
            // internal:caddy-config is gateway-allowed and binds JSON stdin.
            $node = remoteLocalExecutorNode(['gateway']);
            $hostHome = '/home/orbit';
            $operationId = '00000000-0000-4000-8000-000000000431';
            $boundInput = json_encode([
                'container' => 'orbit-caddy',
                'action' => 'read-global',
            ], JSON_THROW_ON_ERROR);
            $capturedCommand = null;
            $capturedInput = null;

            $result = $executor->runInternal(
                node: $node,
                commandName: 'internal:caddy-config',
                arguments: ['read-global'],
                transportOptions: [
                    'force_remote_host' => true,
                    'input' => $boundInput,
                    'bind_input' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operationId,
                    ],
                ],
            );

            expect($result->stdout)->toBe("gateway-host-context-ok\n");

            ProcessFacade::assertRan(function (PendingProcess $process) use (&$capturedCommand, &$capturedInput): bool {
                $command = (string) $process->command;
                $matches =
                    str_contains($command, 'internal:caddy-config')
                    && str_contains($command, '--operation-token=')
                    && ! str_contains($command, 'docker exec -i')
                    && (str_contains($command, 'ssh ') || str_starts_with($command, 'bash -c '));

                if ($matches) {
                    $capturedCommand = $command;
                    $capturedInput = $process->input;
                }

                return $matches;
            });

            expect($capturedCommand)
                ->toBeString()
                ->and($capturedCommand)
                ->toContain('cd ')
                ->and($capturedCommand)
                ->toContain($hostHome)
                ->and($capturedCommand)
                ->toContain("{$hostHome}/.local/bin/orbit")
                ->and($capturedCommand)
                ->toContain('unset '.implode(' ', OperationTokenEnvironment::forceRemoteHostUnsetKeys()))
                ->and($capturedCommand)
                ->not->toContain('/usr/local/bin/orbit-cli')->and($capturedCommand)
                ->not->toContain('APP_KEY=')->and($capturedCommand)
                ->not->toContain('gateway-secret')->and($capturedInput)->toBe($boundInput);

            $compactToken = remoteLocalExecutorTokenFromNestedSshCommand((string) $capturedCommand);
            $token = OperationToken::parse($compactToken);
            $hostArgv = new LocalExecutorCommandBuilder()->buildArgv(
                targetNode: $node,
                commandName: 'internal:caddy-config',
                arguments: ['read-global'],
                options: [],
                operationToken: $compactToken,
            );

            // Host CLI OperationTokenGuard submits getcwd(), allowlisted env present in
            // the remote process, and the piped stdin payload. force_remote_host SSH
            // must mint that exact context (HOME only + bound input, no APP_KEY).
            // After the host script unsets allowlisted keys, the CLI guard only
            // observes HOME. Reconstruct with that process view via the shared helper.
            $hostVerificationContext = OperationTokenCommandContext::fromAgentVerification(
                argv: $hostArgv,
                cwd: $hostHome,
                environment: OperationTokenEnvironment::allowlisted([
                    'HOME' => $hostHome,
                ]),
                input: $boundInput,
                operationToken: $compactToken,
            );

            expect($token->commandContextHash)
                ->toBe($hostVerificationContext->hash())
                ->and(remote_local_executor_operation_run($operationId)->operation_token_consumed_at)
                ->toBeNull();

            $activities = remoteLocalExecutorActivityRows();
            expect($activities)
                ->toHaveCount(2)
                ->and($activities[0]->event)
                ->toBe('force_remote_host.dispatching')
                ->and(remoteLocalExecutorActivityProperties($activities[0]))
                ->toMatchArray([
                    'lane' => 'internal',
                    'transport' => 'force_remote_host',
                    'status' => 'dispatching',
                ])
                ->and($activities[1]->event)
                ->toBe('force_remote_host.completed')
                ->and(remoteLocalExecutorActivityProperties($activities[1]))
                ->toMatchArray([
                    'lane' => 'internal',
                    'transport' => 'force_remote_host',
                    'status' => 'succeeded',
                ]);
        } finally {
            if ($previousExposureMode === false) {
                putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
            } else {
                putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previousExposureMode}");
            }
        }
    });

    it('records gateway_local transport activity for container-local gateway targets', function (): void {
        Http::preventStrayRequests();
        ProcessFacade::preventStrayProcesses();
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "gateway-local-ok\n"),
        ]);
        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException(
                    'Agent-push transport must not be used for gateway-local targets.',
                ),
            ),
        );
        $node = remoteLocalExecutorNode(['gateway']);
        $operationId = '00000000-0000-4000-8000-000000000440';

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:executor:verify',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operationId,
                ],
            ],
        );

        $activities = remoteLocalExecutorActivityRows();

        expect($result->stdout)
            ->toBe("gateway-local-ok\n")
            ->and($activities)
            ->toHaveCount(2)
            ->and($activities[0]->event)
            ->toBe('gateway_local.dispatching')
            ->and(remoteLocalExecutorActivityProperties($activities[0]))
            ->toMatchArray([
                'lane' => 'internal',
                'transport' => 'gateway_local',
                'status' => 'dispatching',
                'operation_id' => $operationId,
            ])
            ->and($activities[1]->event)
            ->toBe('gateway_local.completed')
            ->and(remoteLocalExecutorActivityProperties($activities[1]))
            ->toMatchArray([
                'lane' => 'internal',
                'transport' => 'gateway_local',
                'status' => 'succeeded',
            ]);
    });

    it('mints agent-push context that the CLI verification reconstruction accepts without duplicating expected arrays', function (): void {
        Http::preventStrayRequests();
        $capturedEnvironment = null;
        $capturedArgv = null;
        $capturedToken = null;
        Http::fake([
            'http://10.44.0.70:9477/v1/commands' => function (Request $request) use (
                &$capturedEnvironment,
                &$capturedArgv,
                &$capturedToken,
            ) {
                $payload = remote_local_executor_json_object($request->body());
                $capturedEnvironment = is_array($payload['environment'] ?? null)
                    ? $payload['environment']
                    : null;
                $capturedArgv = is_array($payload['argv'] ?? null) ? $payload['argv'] : null;
                $capturedToken = remote_local_executor_agent_push_operation_token($payload);

                return Http::response([
                    'transport' => 'agent-push',
                    'operation_id' => $payload['operation_id'] ?? 'unknown',
                    'binary' => 'orbit',
                    'status' => 'succeeded',
                    'exit_code' => 0,
                    'frames' => [
                        ['type' => 'stdout', 'message' => "agent-context-ok\n"],
                        ['type' => 'exit', 'message' => '0'],
                    ],
                ]);
            },
        ]);

        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException(
                    'SSH transport should not be called for agent-push context minting.',
                ),
            ),
            stubAgent: false,
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            arguments: ['/srv/docs', 'feature-docs', 'main'],
            transportOptions: [
                'environment' => [
                    'ORBIT_REQUEST_MARKER' => 'must-not-bind',
                    'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
                ],
                'metadata' => [
                    'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000441',
                ],
            ],
        );

        expect($result->stdout)
            ->toBe("agent-context-ok\n")
            ->and($capturedToken)
            ->toBeString()
            ->and($capturedArgv)
            ->toBeArray()
            ->and($capturedEnvironment)
            ->toBeArray();

        /** @var list<string> $capturedArgv */
        /** @var array<string, string> $capturedEnvironment */
        /** @var string $capturedToken */
        $token = OperationToken::parse($capturedToken);
        $cliVerificationContext = OperationTokenCommandContext::fromAgentVerification(
            argv: array_values(array_map(
                static fn (mixed $argument): string => is_string($argument) ? $argument : '',
                $capturedArgv,
            )),
            cwd: null,
            environment: OperationTokenEnvironment::allowlisted($capturedEnvironment),
            input: null,
            operationToken: $capturedToken,
        );

        expect($token->commandContextHash)
            ->toBe($cliVerificationContext->hash())
            ->and(OperationTokenEnvironment::allowlisted($capturedEnvironment))
            ->toBe($capturedEnvironment)
            ->and($capturedEnvironment)
            ->not
            ->toHaveKey('ORBIT_REQUEST_MARKER')
            ->and($capturedEnvironment)
            ->toHaveKey('ORBIT_BIN_PATH')
            ->and($capturedEnvironment)
            ->toHaveKey('HOME')
            ->and($capturedEnvironment)
            ->toHaveKey('APP_KEY');
    });

    it('keeps agent-push ordinary when force_remote_host is requested for non-gateway targets', function (): void {
        $previousExposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        Http::preventStrayRequests();
        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');

        try {
            $transport = new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => new RemoteShellResult(
                    exitCode: 0,
                    stdout: "agent-push-ordinary\n",
                    stderr: '',
                    durationMs: 1,
                ),
            );
            $executor = remoteLocalExecutor(transport: $transport);
            $node = remoteLocalExecutorNode(['ingress']);

            $result = $executor->runInternal(
                node: $node,
                commandName: 'internal:caddy-config',
                arguments: ['read-global'],
                transportOptions: [
                    'force_remote_host' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000432',
                    ],
                ],
            );

            expect($result->stdout)
                ->toBe("agent-push-ordinary\n")
                ->and($transport->calls)
                ->not->toBeEmpty();

            $script = (string) ($transport->calls[0]['script'] ?? '');

            expect($script)
                ->toContain('internal:caddy-config')
                ->not->toContain("cd '/home/orbit'")
                ->not->toContain('unset APP_KEY');
        } finally {
            if ($previousExposureMode === false) {
                putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
            } else {
                putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previousExposureMode}");
            }
        }
    });

    it('defaults node-local internal command execution to agent-push without calling ssh transport', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands' => Http::response([
                'transport' => 'agent-push',
                'operation_id' => '00000000-0000-4000-8000-000000000406',
                'binary' => 'orbit',
                'status' => 'succeeded',
                'exit_code' => 0,
                'frames' => [
                    [
                        'type' => 'stdout',
                        'message' => "{\"ok\":true}\n",
                    ],
                    [
                        'type' => 'exit',
                        'message' => '0',
                    ],
                ],
            ]),
        ]);
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (): RemoteShellResult => throw new RuntimeException(
                'SSH transport should not be called by default.',
            ),
        );
        $executor = remoteLocalExecutor(
            transport: $transport,
            stubAgent: false,
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            arguments: ['/srv/docs', 'feature-docs', 'main'],
            commandOptions: [],
            transportOptions: [
                'input' => '{"probe":true}',
                'timeout' => 45,
                'environment' => [
                    'ORBIT_REQUEST_MARKER' => 'agent-push-env',
                    'ORBIT_BIN_PATH' => '/usr/local/bin/orbit',
                ],
                'metadata' => [
                    'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000406',
                ],
            ],
        );

        expect($result)
            ->toBeInstanceOf(RemoteShellResult::class)
            ->and($result->exitCode)
            ->toBe(0)
            ->and($result->stdout)
            ->toBe("{\"ok\":true}\n")
            ->and($transport->calls)
            ->toBeEmpty();

        Http::assertSent(
            fn (Request $request): bool => remote_local_executor_default_agent_push_request_matches($request),
        );
    });

    it('composes default agent-push dispatch without rendering an SSH shell script', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands' => function () {
                usleep(20_000);

                return Http::response([
                    'transport' => 'agent-push',
                    'operation_id' => '00000000-0000-4000-8000-000000000426',
                    'binary' => 'orbit',
                    'status' => 'succeeded',
                    'exit_code' => 0,
                    'frames' => [
                        [
                            'type' => 'stdout',
                            'message' => "ok\n",
                        ],
                    ],
                ]);
            },
        ]);
        $commands = new RemoteLocalExecutorCountingCommands;
        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException('SSH transport should not be called.'),
            ),
            commands: $commands,
            stubAgent: false,
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            arguments: ['/srv/docs', 'feature-docs', 'main'],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000426',
                ],
            ],
        );

        expect($commands->calls)
            ->toBe([
                'buildArgv',
                'buildAuditLine',
            ])
            ->and($result->durationMs)
            ->toBeGreaterThanOrEqual(1);
    });

    it('streams node-local internal command output through agent-push without calling ssh transport', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands/stream' => Http::response("line one\nline two\n"),
        ]);
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (): RemoteShellResult => throw new RuntimeException(
                'SSH transport should not be called by agent-push streams.',
            ),
        );
        $executor =
            remoteLocalExecutor(
                transport: $transport,
            );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();
        $chunks = [];

        $executor->streamInternal(
            node: $node,
            commandName: 'internal:process-logs',
            transportOptions: [
                'input' => '{"follow":true}',
                'timeout' => 0,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000407',
                ],
            ],
            onOutput: static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        expect(implode('', $chunks))
            ->toBe("line one\nline two\n")
            ->and($transport->calls)
            ->toBeEmpty();

        Http::assertSent(
            fn (Request $request): bool => remote_local_executor_stream_agent_push_request_matches($request),
        );
    });

    it('records streamed framed runs as failed with the real exit code', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands/stream' => Http::response(
                remote_local_executor_process_stream_body(
                    stdout: "boom\n",
                    exitCode: 17,
                ),
                200,
                ['Content-Type' => 'application/vnd.orbit.process-stream.v1'],
            ),
        ]);
        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException('SSH transport should not be called.'),
            ),
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();
        $operationId = '00000000-0000-4000-8000-000000000408';

        try {
            $executor->streamInternal(
                node: $node,
                commandName: 'internal:process-logs',
                transportOptions: [
                    'metadata' => ['ORBIT_OPERATION_ID' => $operationId],
                ],
                onOutput: static function (string $chunk): void {},
            );
            throw new RuntimeException('Expected streamed non-zero exit to fail.');
        } catch (RemoteShellFailed $exception) {
            expect($exception->result->exitCode)->toBe(17);
        }

        $row = remote_local_executor_operation_run($operationId);

        expect($row->status)
            ->toBe('failed')
            ->and((int) $row->exit_code)
            ->toBe(17);
    });

    it('records streamed framed agent errors as failed without treating them as stdout', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands/stream' => Http::response(
                remote_local_executor_process_stream_body(
                    stdout: 'visible',
                    agentError: [
                        'code' => 'process_timeout',
                        'message' => 'binary execution timed out after 1 seconds',
                        'retryable' => false,
                    ],
                    exitCode: null,
                ),
                200,
                ['Content-Type' => 'application/vnd.orbit.process-stream.v1'],
            ),
        ]);
        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException('SSH transport should not be called.'),
            ),
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();
        $operationId = '00000000-0000-4000-8000-000000000409';
        $chunks = [];

        try {
            $executor->streamInternal(
                node: $node,
                commandName: 'internal:process-logs',
                transportOptions: [
                    'metadata' => ['ORBIT_OPERATION_ID' => $operationId],
                ],
                onOutput: static function (string $chunk) use (&$chunks): void {
                    $chunks[] = $chunk;
                },
            );
            throw new RuntimeException('Expected streamed agent error to fail.');
        } catch (RemoteShellFailed) {
            // expected
        }

        expect($chunks)->toBe(['visible']);

        $row = remote_local_executor_operation_run($operationId);

        expect($row->status)->toBe('failed')->and($row->exit_code)->toBeNull();
    });

    it('records streamed framed null exit codes as failed', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands/stream' => Http::response(
                remote_local_executor_process_stream_body(
                    stdout: 'terminated',
                    exitCode: null,
                    signal: 9,
                ),
                200,
                ['Content-Type' => 'application/vnd.orbit.process-stream.v1'],
            ),
        ]);
        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException('SSH transport should not be called.'),
            ),
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();
        $operationId = '00000000-0000-4000-8000-000000000411';

        try {
            $executor->streamInternal(
                node: $node,
                commandName: 'internal:process-logs',
                transportOptions: [
                    'metadata' => ['ORBIT_OPERATION_ID' => $operationId],
                ],
                onOutput: static function (string $chunk): void {},
            );
            throw new RuntimeException('Expected streamed null exit code to fail.');
        } catch (RemoteShellFailed $exception) {
            expect($exception->result->exitCode)->toBe(1);
        }

        $row = remote_local_executor_operation_run($operationId);

        expect($row->status)->toBe('failed')->and($row->exit_code)->toBeNull();
    });

    it('records streamed framed success with exit zero in operation runs', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.70:9477/v1/commands/stream' => Http::response(
                remote_local_executor_process_stream_body(
                    stdout: "line one\nline two\n",
                    exitCode: 0,
                ),
                200,
                ['Content-Type' => 'application/vnd.orbit.process-stream.v1'],
            ),
        ]);
        $executor = remoteLocalExecutor(
            transport: new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => throw new RuntimeException('SSH transport should not be called.'),
            ),
        );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => true,
        ])->save();
        $operationId = '00000000-0000-4000-8000-000000000410';
        $chunks = [];

        $executor->streamInternal(
            node: $node,
            commandName: 'internal:process-logs',
            transportOptions: [
                'metadata' => ['ORBIT_OPERATION_ID' => $operationId],
            ],
            onOutput: static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
        );

        expect(implode('', $chunks))->toBe("line one\nline two\n");

        $row = remote_local_executor_operation_run($operationId);

        expect($row->status)
            ->toBe('succeeded')
            ->and((int) $row->exit_code)
            ->toBe(0);
    });

    it('does not fall back to ssh when default agent-push selection fails', function (): void {
        Http::preventStrayRequests();
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (): RemoteShellResult => throw new RuntimeException(
                'SSH transport should not be called by default.',
            ),
        );
        $executor =
            remoteLocalExecutor(
                transport: $transport,
            );
        $node = remoteLocalExecutorNode();
        $node->forceFill([
            'status' => 'active',
            'managed' => false,
            'wireguard_address' => null,
        ])->save();
        $operationId = '00000000-0000-4000-8000-000000000442';

        expect(fn (): RemoteShellResult => $executor->runInternal(
            node: $node,
            commandName: 'internal:executor:verify',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operationId,
                ],
            ],
        ))
            ->toThrow(RuntimeException::class, 'agent-push transport is unavailable');

        $activities = remoteLocalExecutorActivityRows();

        // Selector failure still records a dispatching/completed pair on the
        // intended agent_push lane (not a lone completed without dispatching).
        expect($transport->calls)
            ->toBeEmpty()
            ->and($activities)
            ->toHaveCount(2)
            ->and($activities[0]->event)
            ->toBe('agent_push.dispatching')
            ->and(remoteLocalExecutorActivityProperties($activities[0]))
            ->toMatchArray([
                'lane' => 'internal',
                'transport' => 'agent_push',
                'status' => 'dispatching',
                'operation_id' => $operationId,
            ])
            ->and($activities[1]->event)
            ->toBe('agent_push.completed')
            ->and(remoteLocalExecutorActivityProperties($activities[1]))
            ->toMatchArray([
                'lane' => 'internal',
                'transport' => 'agent_push',
                'status' => 'failed',
                'operation_id' => $operationId,
            ]);
    });

    it('mints a token, builds a local executor command, dispatches through transport, and returns the transport result', function (): void {
        $transportResult = new RemoteShellResult(exitCode: 0, stdout: "{\"ok\":true}\n", stderr: '', durationMs: 17);
        $transport = new RemoteLocalExecutorRecordingTransport($transportResult);
        $executor = remoteLocalExecutor($transport);
        $node = remoteLocalExecutorNode();
        $operationId = '00000000-0000-4000-8000-000000000402';

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            arguments: ['/srv/docs', 'feature-docs', 'main'],
            commandOptions: [
                'enabled' => true,
                'attempts' => 3,
            ],
            transportOptions: [
                'timeout' => 45,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operationId,
                    'ORBIT_REQUEST_ID' => 'local-req',
                ],
            ],
        );

        expect($result)
            ->toBeInstanceOf(RemoteShellResult::class)
            ->and($result->exitCode)
            ->toBe($transportResult->exitCode)
            ->and($result->stdout)
            ->toBe($transportResult->stdout)
            ->and($transport->calls)
            ->toHaveCount(1)
            ->and($transport->calls[0]['node']->is($node))
            ->toBeTrue()
            ->and($transport->calls[0]['options'])
            ->toBe([]);

        $script = $transport->calls[0]['script'];
        $compactToken = remoteLocalExecutorTokenFromScript($script);
        $token = OperationToken::parse($compactToken);
        $auditLine = new LocalExecutorCommandBuilder()->buildAuditLine(
            targetNode: $node,
            commandName: 'internal:workspace-source:create',
            arguments: ['/srv/docs', 'feature-docs', 'main'],
            options: [
                'enabled' => true,
                'attempts' => 3,
            ],
            operationToken: $compactToken,
        );

        expect($script)
            ->toContain('internal:workspace-source:create /srv/docs feature-docs main')
            ->and($script)
            ->not
            ->toContain('docker exec')
            ->and(substr_count($script, '--operation-token='))
            ->toBe(1)
            ->and(substr_count($script, $compactToken))
            ->toBe(1)
            ->and($token->id)
            ->toBe(remote_local_executor_operation_run($operationId)->id)
            ->and($token->node)
            ->toBe($node->name)
            ->and($token->command)
            ->toBe('internal:workspace-source:create')
            ->and($token->issuedAt)
            ->toBe(1_798_105_200)
            ->and($token->expiresAt)
            ->toBe(1_798_105_320)
            ->and(new OperationTokenVerifier(new OperationTokenSigner)->verify(
                secretsByKeyId: [$token->keyId => 'gateway-secret'],
                token: $token,
                expectedNode: $node->name,
                expectedCommand: 'internal:workspace-source:create',
                expectedCommandContextHash: OperationTokenCommandContext::fromTrustedDispatch(
                    argv: new LocalExecutorCommandBuilder()->buildArgv(
                        targetNode: $node,
                        commandName: 'internal:workspace-source:create',
                        arguments: ['/srv/docs', 'feature-docs', 'main'],
                        options: [
                            'enabled' => true,
                            'attempts' => 3,
                        ],
                        operationToken: OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                    ),
                    cwd: null,
                    environment: remoteLocalExecutorEnvironment(),
                    input: null,
                )->hash(),
                now: 1_798_105_200,
            ))
            ->toBeTrue();

        $activities = remoteLocalExecutorActivityRows();
        [$started, $completed] = $activities;
        $startedProperties = remoteLocalExecutorActivityProperties($started);
        $completedProperties = remoteLocalExecutorActivityProperties($completed);

        expect($activities)
            ->toHaveCount(2)
            ->and($started->event)
            ->toBe('agent_push.dispatching')
            ->and($started->description)
            ->toBe('Agent push operation dispatching')
            ->and($started->subject_type)
            ->toBe($node->getMorphClass())
            ->and((int) $started->subject_id)
            ->toBe($node->getKey())
            ->and($startedProperties)
            ->toMatchArray([
                'type' => 'write',
                'lane' => 'internal',
                'transport' => 'agent_push',
                'status' => 'dispatching',
                'operation_id' => $operationId,
                'target_node_id' => $node->getKey(),
                'target_node_name' => 'app-dev',
                'command' => 'internal:workspace-source:create',
                'arguments' => ['/srv/docs', 'feature-docs', 'main'],
                'command_options' => [
                    'enabled' => true,
                    'attempts' => 3,
                ],
                'command_line' => $auditLine,
            ])
            ->and($completed->event)
            ->toBe('agent_push.completed')
            ->and($completed->description)
            ->toBe('Agent push operation succeeded')
            ->and($completedProperties)
            ->toMatchArray([
                'type' => 'write',
                'lane' => 'internal',
                'transport' => 'agent_push',
                'status' => 'succeeded',
                'operation_id' => $operationId,
                'target_node_id' => $node->getKey(),
                'target_node_name' => 'app-dev',
                'command' => 'internal:workspace-source:create',
                'exit_code' => 0,
                'stdout_summary' => "{\"ok\":true}\n",
                'stderr_summary' => '',
            ])
            ->and(remoteLocalExecutorActivityLogBlob())
            ->not->toContain($compactToken);
    });

    it('supports command-name-only run calls for the RemoteShell interface method', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: "verified\n", stderr: '', durationMs: 3),
        );
        $executor = remoteLocalExecutor($transport);
        $node = remoteLocalExecutorNode();

        $result = $executor->run($node, 'internal:executor:verify', ['timeout' => 10]);

        expect($result->stdout)
            ->toBe("verified\n")
            ->and($transport->calls)
            ->toHaveCount(1)
            ->and($transport->calls[0]['options'])
            ->toBe([]);

        $script = $transport->calls[0]['script'];
        $compactToken = remoteLocalExecutorTokenFromScript($script);

        expect($script)
            ->toContain('internal:executor:verify')
            ->toContain("--operation-token={$compactToken}");
    });

    it('passes the operation signing key as process environment without adding it to the command line', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: "verified\n", stderr: '', durationMs: 3),
        );
        $executor = remoteLocalExecutor($transport);

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
        );

        expect($transport->calls[0]['script'])
            ->not->toContain('gateway-secret')->and(remoteLocalExecutorActivityLogBlob())
            ->not->toContain('gateway-secret');
    });

    it('rejects long-running local executor dispatch through start before minting a token', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        $executor = remoteLocalExecutor($transport, remoteLocalExecutorTokenFactory(
            clock: static fn (): int => throw new RuntimeException('Operation token mint should not run.'),
        ));

        expect(fn (): InvokedProcess => $executor->start(
            node: remoteLocalExecutorNode(),
            script: 'internal:executor:verify',
            options: [],
        ))
            ->toThrow(RuntimeException::class, remoteLocalExecutorStartUnsupportedMessage());

        expect($transport->calls)->toBeEmpty()->and(remoteLocalExecutorActivityRows())->toBeEmpty();
    });

    it('rejects long-running local executor dispatch through startInternal before minting a token', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        $executor = remoteLocalExecutor($transport, remoteLocalExecutorTokenFactory(
            clock: static fn (): int => throw new RuntimeException('Operation token mint should not run.'),
        ));

        expect(fn (): InvokedProcess => $executor->startInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        ))
            ->toThrow(RuntimeException::class, remoteLocalExecutorStartUnsupportedMessage());

        expect($transport->calls)->toBeEmpty()->and(remoteLocalExecutorActivityRows())->toBeEmpty();
    });

    it('surfaces builder failures without dispatching to transport', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        $executor = remoteLocalExecutor($transport, remoteLocalExecutorTokenFactory(
            clock: static fn (): int => throw new RuntimeException('Operation token mint should not run.'),
        ));

        expect(fn (): RemoteShellResult => $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'executor:verify',
            arguments: [],
            commandOptions: [],
        ))
            ->toThrow(LocalExecutorCommandBuilderException::class);

        expect($transport->calls)->toBeEmpty()->and(remoteLocalExecutorActivityRows())->toBeEmpty();
    });

    it('surfaces operation token factory failures without dispatching to transport', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        $executor = new RemoteLocalExecutor(
            commands: new LocalExecutorCommandBuilder,
            operationTokens: remoteLocalExecutorTokenFactory(
                clock: static fn (): int => throw new RuntimeException('Operation token signing secret is required.'),
            ),
            activityLogger: remoteLocalExecutorActivityLogger(),
            operationRuns: app(OperationRunRecorder::class),
            outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
            agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
            applicationKey: 'gateway-secret',
        );

        expect(fn (): RemoteShellResult => $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        ))
            ->toThrow(RuntimeException::class, 'Operation token signing secret is required.');

        expect($transport->calls)->toBeEmpty()->and(remoteLocalExecutorActivityRows())->toBeEmpty();
    });

    it('records failed transport results with redacted output summaries', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                return new RemoteShellResult(
                    exitCode: 13,
                    stdout: "stdout echoed --operation-token='{$token}'\n",
                    stderr: "stderr echoed --operation-token={$token}\n",
                    durationMs: 28,
                );
            },
        );
        $executor = remoteLocalExecutor($transport);

        $result = $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        );

        $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
        $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

        expect($result->exitCode)
            ->toBe(13)
            ->and($completedProperties)
            ->toMatchArray([
                'lane' => 'internal',
                'transport' => 'agent_push',
                'status' => 'failed',
                'command' => 'internal:executor:verify',
                'exit_code' => 13,
                'stdout_summary' => "stdout echoed --operation-token=<redacted>\n",
                'stderr_summary' => "stderr echoed --operation-token=<redacted>\n",
            ])
            ->and(remoteLocalExecutorActivityLogBlob())
            ->not->toContain($token);
    });

    it('records thrown transport failures and rethrows token-redacted shell failures', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                throw new RemoteShellFailed(
                    node: $node,
                    script: $script,
                    result: new RemoteShellResult(
                        exitCode: 19,
                        stdout: "stdout leak {$token}\n",
                        stderr: "stderr leak --operation-token={$token}\n",
                        durationMs: 31,
                    ),
                );
            },
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                arguments: [],
                commandOptions: [],
                transportOptions: ['throw' => true],
            );

            throw new RuntimeException('Expected the local executor transport to throw.');
        } catch (RemoteShellFailed $exception) {
            $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception->getMessage())
                ->not->toContain($token)->and($exception->script)
                ->not->toContain($token)->and($exception->result->stdout)
                ->not->toContain($token)->and($exception->result->stderr)
                ->not->toContain($token)->and($exception->getTraceAsString())
                ->not->toContain($token)->and($completedProperties)->toMatchArray([
                    'lane' => 'internal',
                    'transport' => 'agent_push',
                    'status' => 'failed',
                    'command' => 'internal:executor:verify',
                    'exit_code' => 19,
                    'stdout_summary' => "stdout leak <redacted>\n",
                    'stderr_summary' => "stderr leak --operation-token=<redacted>\n",
                ])->and(remoteLocalExecutorActivityLogBlob())
                ->not->toContain($token);
        }
    });

    it('wraps generic transport failures with token-bearing messages without chaining the original exception', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                throw new RuntimeException("transport leaked --operation-token={$token}");
            },
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                arguments: [],
                commandOptions: [],
            );

            throw new RuntimeException('Expected the local executor transport to throw.');
        } catch (RuntimeException $exception) {
            $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception)
                ->not->toBeInstanceOf(RemoteShellFailed::class)->and($exception->getPrevious())->toBeNull()->and(
                    $exception->getMessage(),
                )->toBe('Remote local executor transport failed: transport leaked --operation-token=<redacted>')->and(
                    $exception->getMessage(),
                )
                ->not->toContain($token)->and($exception->getTraceAsString())
                ->not->toContain($token)->and($completedProperties)->toMatchArray([
                    'lane' => 'internal',
                    'transport' => 'agent_push',
                    'status' => 'failed',
                    'command' => 'internal:executor:verify',
                    'exit_code' => null,
                    'exception_class' => RuntimeException::class,
                    'exception_message' => 'transport leaked --operation-token=<redacted>',
                ])->and(remoteLocalExecutorActivityLogBlob())
                ->not->toContain($token);
        }
    });

    it('wraps generic transport failures with clean messages so traces cannot retain token-bearing method arguments', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (Node $node, string $script, array $options): RemoteShellResult => throw new RuntimeException(
                'transport disconnected',
            ),
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                arguments: [],
                commandOptions: [],
            );

            throw new RuntimeException('Expected the local executor transport to throw.');
        } catch (RuntimeException $exception) {
            $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception)
                ->not->toBeInstanceOf(RemoteShellFailed::class)->and($exception->getPrevious())->toBeNull()->and(
                    $exception->getMessage(),
                )->toBe('Remote local executor transport failed: transport disconnected')->and($exception->getMessage())
                ->not->toContain($token)->and($exception->getTraceAsString())
                ->not->toContain($token)->and($completedProperties)->toMatchArray([
                    'lane' => 'internal',
                    'transport' => 'agent_push',
                    'status' => 'failed',
                    'command' => 'internal:executor:verify',
                    'exit_code' => null,
                    'exception_class' => RuntimeException::class,
                    'exception_message' => 'transport disconnected',
                ])->and(remoteLocalExecutorActivityLogBlob())
                ->not->toContain($token);
        }
    });

    it(
        'redacts operation-token output variant :dataset from any operation before storing summaries',
        /**
         * @param  Closure(string): string  $output
         */
        function (
            Closure $output,
            string $expected,
        ): void {
            $otherOperationToken = 'external-operation-token-402';
            $transport = new RemoteLocalExecutorRecordingTransport(
                static fn (Node $node, string $script, array $options): RemoteShellResult => new RemoteShellResult(
                    exitCode: 0,
                    stdout: $output($otherOperationToken),
                    stderr: '',
                    durationMs: 6,
                ),
            );
            $executor = remoteLocalExecutor($transport);

            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                arguments: [],
                commandOptions: [],
            );

            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($completedProperties['stdout_summary'])
                ->not
                ->toContain($otherOperationToken)
                ->and($completedProperties['stdout_summary'])
                ->toBe($expected);
        },
    )->with([
        'no spaces around equals' => [
            static fn (string $token): string => "--operation-token={$token}",
            '--operation-token=<redacted>',
        ],
        'space before equals' => [
            static fn (string $token): string => "--operation-token ={$token}",
            '--operation-token=<redacted>',
        ],
        'space after equals' => [
            static fn (string $token): string => "--operation-token= {$token}",
            '--operation-token=<redacted>',
        ],
        'spaces around equals' => [
            static fn (string $token): string => "--operation-token = {$token}",
            '--operation-token=<redacted>',
        ],
        'whitespace separator' => [
            static fn (string $token): string => "--operation-token {$token}",
            '--operation-token=<redacted>',
        ],
        'double quoted value' => [
            static fn (string $token): string => "--operation-token=\"{$token}\"",
            '--operation-token=<redacted>',
        ],
        'single quoted value' => [
            static fn (string $token): string => "--operation-token='{$token}'",
            '--operation-token=<redacted>',
        ],
        'at end of string' => [
            static fn (string $token): string => "ending --operation-token={$token}",
            'ending --operation-token=<redacted>',
        ],
    ]);

    it('redacts APP_KEY material from activity stdout and stderr summaries', function (): void {
        $secret = 'base64:ActivityMustNotStoreThisKey==';
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (Node $node, string $script, array $options): RemoteShellResult => new RemoteShellResult(
                exitCode: 0,
                stdout: "{\"success\":{\"data\":{\"app_key\":\"{$secret}\"}}}\n",
                stderr: "export APP_KEY={$secret}\n",
                durationMs: 4,
            ),
        );
        $executor = remoteLocalExecutor($transport);

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        );

        $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);
        $logBlob = remoteLocalExecutorActivityLogBlob();

        expect($completedProperties['stdout_summary'])
            ->not->toContain($secret)->toContain('"<redacted>"')->and($completedProperties['stderr_summary'])
            ->not->toContain($secret)->toContain('APP_KEY=<redacted>')->and($logBlob)
            ->not->toContain($secret);
    });

    it('redacts secret-shaped command options and command_line on dispatching rows without explicit redact lists', function (): void {
        $appKeyMaterial = 'base64:DispatchMustNotStoreThisKey==';
        $passwordMaterial = 'super-secret-password-value';
        $tokenMaterial = 'dispatch-token-should-not-persist';
        $marker = '<redacted>';
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: "{\"ok\":true}\n", stderr: '', durationMs: 5),
        );
        $executor = remoteLocalExecutor($transport);

        $node = remoteLocalExecutorNode(['app-dev']);
        $node->forceFill(['status' => 'active', 'managed' => true])->save();

        $executor->runInternal(
            node: $node,
            commandName: 'internal:app-source:create',
            arguments: ["APP_KEY={$appKeyMaterial}"],
            commandOptions: [
                'action' => 'upsert-peer',
                // Hyphenated keys only — command option pattern forbids underscores.
                'password' => $passwordMaterial,
                'api-token' => $tokenMaterial,
                'app-key' => $appKeyMaterial,
                'public-key' => 'peer-public-key-probe',
            ],
            transportOptions: [
                'timeout' => 30,
                // Intentionally omit redact_command_options so ActivityLogger's
                // SecretSummaryRedactor boundary must scrub dispatching activity.
            ],
        );

        $dispatchProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[0]);
        $logBlob = remoteLocalExecutorActivityLogBlob();
        $commandOptions = $dispatchProperties['command_options'] ?? [];

        expect($dispatchProperties)
            ->toMatchArray([
                'status' => 'dispatching',
            ])
            ->and($commandOptions['action'] ?? null)
            ->toBe('upsert-peer')
            ->and($commandOptions['password'] ?? null)
            ->toBe($marker)
            ->and($commandOptions['api-token'] ?? null)
            ->toBe($marker)
            ->and($commandOptions['app-key'] ?? null)
            ->toBe($marker)
            ->and($commandOptions['public-key'] ?? null)
            ->toBe('peer-public-key-probe')
            ->and($dispatchProperties['arguments'])
            ->not->toContain($appKeyMaterial)->and($dispatchProperties['command_line'])
            ->not->toContain($appKeyMaterial)
            ->not->toContain($passwordMaterial)
            ->not->toContain($tokenMaterial)->and($logBlob)
            ->not->toContain($appKeyMaterial)
            ->not->toContain($passwordMaterial)
            ->not->toContain($tokenMaterial);
    });

    it('does not write raw operation tokens to activity rows even when command output echoes them', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: "raw token {$token}\nflag --operation-token='{$token}'\n",
                    stderr: "flag --operation-token={$token}\n",
                    durationMs: 9,
                );
            },
        );
        $executor = remoteLocalExecutor($transport);

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        );

        $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
        $logBlob = remoteLocalExecutorActivityLogBlob();

        expect($logBlob)
            ->not
            ->toContain($token)
            ->and($logBlob)
            ->toContain('--operation-token=<redacted>')
            ->and($logBlob)
            ->toContain('raw token <redacted>');
    });

    it('scrubs output before truncating when the operation token crosses the summary boundary', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: str_repeat('p', 4_000).$token.str_repeat('s', 300),
                    stderr: '',
                    durationMs: 10,
                );
            },
        );
        $executor = remoteLocalExecutor($transport);

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        );

        $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
        $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

        expect($completedProperties['stdout_summary'])
            ->not
            ->toContain($token)
            ->and($completedProperties['stdout_summary'])
            ->toContain('<redacted>')
            ->and(str_ends_with(
                remote_local_executor_activity_property_string($completedProperties, key: 'stdout_summary'),
                '[truncated]',
            ))
            ->toBeTrue();
    });

    it('truncates long stdout and stderr summaries in completed records', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(
                exitCode: 0,
                stdout: str_repeat('o', 4_200),
                stderr: str_repeat('e', 4_300),
                durationMs: 11,
            ),
        );
        $executor = remoteLocalExecutor($transport);

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        );

        $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

        $stdoutSummary = remote_local_executor_activity_property_string($completedProperties, key: 'stdout_summary');
        $stderrSummary = remote_local_executor_activity_property_string($completedProperties, key: 'stderr_summary');

        expect(strlen($stdoutSummary))
            ->toBe(4_107)
            ->and(strlen($stderrSummary))
            ->toBe(4_107)
            ->and(str_ends_with($stdoutSummary, '[truncated]'))
            ->toBeTrue()
            ->and(str_ends_with($stderrSummary, '[truncated]'))
            ->toBeTrue();
    });

    it(
        'suppresses requested output summaries and strips redaction flags before dispatch :dataset',
        /**
         * @param  array{timeout: int, redact_stdout?: bool, redact_stderr?: bool}  $transportOptions
         */
        function (
            array $transportOptions,
            string $expectedStdoutSummary,
            string $expectedStderrSummary,
        ): void {
            $transport = new RemoteLocalExecutorRecordingTransport(
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: '{"api_token":"poly-token-secret"}',
                    stderr: 'stderr secret',
                    durationMs: 12,
                ),
            );
            $executor = remoteLocalExecutor($transport);

            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:workspace-source:create',
                arguments: [],
                commandOptions: [
                    'app-path' => '/srv/docs',
                ],
                transportOptions: $transportOptions,
            );

            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($transport->calls[0]['options'])
                ->not
                ->toHaveKeys(['redact_stdout', 'redact_stderr'])
                ->and($completedProperties['stdout_summary'])
                ->toBe($expectedStdoutSummary)
                ->and($completedProperties['stderr_summary'])
                ->toBe($expectedStderrSummary);
        },
    )->with([
        'no redaction flags' => [
            ['timeout' => 30],
            // Secret-shaped JSON is scrubbed by the activity persistence boundary
            // even when transport redact_* flags are off.
            '{"api_token":"<redacted>"}',
            'stderr secret',
        ],
        'stdout only' => [
            ['timeout' => 30, 'redact_stdout' => true],
            '<suppressed>',
            'stderr secret',
        ],
        'stderr only' => [
            ['timeout' => 30, 'redact_stderr' => true],
            '{"api_token":"<redacted>"}',
            '<suppressed>',
        ],
        'stdout and stderr' => [
            ['timeout' => 30, 'redact_stdout' => true, 'redact_stderr' => true],
            '<suppressed>',
            '<suppressed>',
        ],
    ]);

    it('redacts requested command options in dispatch audit rows while dispatching the real values', function (): void {
        $passwordHash = '$argon2id$v=19$m=65536,t=3,p=4$hash$hash';
        $privateKey = 'peer-private-key-probe';
        $preSharedKey = 'peer-pre-shared-key-probe';
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: "{\"ok\":true}\n", stderr: '', durationMs: 13),
        );
        $executor = remoteLocalExecutor($transport);

        $node = remoteLocalExecutorNode(['app-dev']);
        $node->forceFill(['status' => 'active', 'managed' => true])->save();

        $executor->runInternal(
            node: $node,
            commandName: 'internal:app-source:create',
            arguments: [],
            commandOptions: [
                'action' => 'upsert-peer',
                'password-hash' => $passwordHash,
                'private-key' => $privateKey,
                'public-key' => 'peer-public-key-probe',
                'pre-shared-key' => $preSharedKey,
            ],
            transportOptions: [
                'timeout' => 30,
                'redact_command_options' => ['password-hash', 'private-key', 'pre-shared-key'],
            ],
        );

        $script = $transport->calls[0]['script'];
        $dispatchProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[0]);

        expect($script)
            ->toContain("--password-hash={$passwordHash}")
            ->and($script)
            ->toContain("--private-key={$privateKey}")
            ->and($script)
            ->toContain("--pre-shared-key={$preSharedKey}")
            ->and($transport->calls[0]['options'])
            ->toBe([])
            ->and($dispatchProperties['command_options'])
            ->toMatchArray([
                'action' => 'upsert-peer',
                'password-hash' => '<redacted>',
                'private-key' => '<redacted>',
                'public-key' => 'peer-public-key-probe',
                'pre-shared-key' => '<redacted>',
            ])
            ->and($dispatchProperties['command_line'])
            ->toContain('--password-hash=<redacted>')
            ->and($dispatchProperties['command_line'])
            ->toContain('--private-key=<redacted>')
            ->and($dispatchProperties['command_line'])
            ->toContain('--pre-shared-key=<redacted>')
            ->and(json_encode($dispatchProperties, JSON_THROW_ON_ERROR))
            ->not->toContain($passwordHash)->and(json_encode($dispatchProperties, JSON_THROW_ON_ERROR))
            ->not->toContain($privateKey)->and(json_encode($dispatchProperties, JSON_THROW_ON_ERROR))
            ->not->toContain($preSharedKey);
    });

    it('suppresses generic transport exception messages when output redaction is requested', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (Node $node, string $script, array $options): RemoteShellResult => throw new RuntimeException(
                'transport leaked api_token=poly-token-secret',
            ),
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:workspace-source:create',
                arguments: [],
                commandOptions: [
                    'app-path' => '/srv/docs',
                ],
                transportOptions: ['redact_stdout' => true],
            );

            throw new RuntimeException('Expected the local executor transport to throw.');
        } catch (RuntimeException $exception) {
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception->getMessage())
                ->toBe('Remote local executor transport failed: <suppressed>')
                ->and($completedProperties['exception_message'])
                ->toBe('<suppressed>')
                ->and(remoteLocalExecutorActivityLogBlob())
                ->not->toContain('poly-token-secret');
        }
    });

    it('records an operation_runs row that transitions queued → running → succeeded around each successful internal dispatch', function (): void {
        $operationId = '00000000-0000-4000-8000-000000000901';
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: "{\"ok\":true}\n", stderr: '', durationMs: 42),
        );
        $executor = remoteLocalExecutor($transport);
        $node = remoteLocalExecutorNode();

        $executor->runInternal(
            node: $node,
            commandName: 'internal:workspace-source:create',
            transportOptions: ['metadata' => ['ORBIT_OPERATION_ID' => $operationId]],
        );

        $rows = DB::table('operation_runs')->where('operation_id', $operationId)->get();

        expect($rows)->toHaveCount(1);

        $row = remote_local_executor_operation_run($operationId);
        expect($row->status)
            ->toBe('succeeded')
            ->and($row->internal_command)
            ->toBe('internal:workspace-source:create')
            ->and($row->lane)
            ->toBe('local')
            ->and((int) $row->target_node_id)
            ->toBe($node->id)
            ->and($row->started_at)
            ->not->toBeNull()->and($row->finished_at)
            ->not->toBeNull()->and((int) $row->exit_code)->toBe(0)->and($row->id)
            ->not->toBe($operationId);
    });

    it('records an operation_runs row as failed with the redacted exit code on RemoteShellFailed', function (): void {
        $operationId = '00000000-0000-4000-8000-000000000902';
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                throw new RemoteShellFailed(
                    node: $node,
                    script: $script,
                    result: new RemoteShellResult(
                        exitCode: 13,
                        stdout: "leak --operation-token={$token}",
                        stderr: '',
                        durationMs: 11,
                    ),
                );
            },
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                transportOptions: ['metadata' => ['ORBIT_OPERATION_ID' => $operationId]],
            );
            throw new RuntimeException('Expected RemoteShellFailed.');
        } catch (RemoteShellFailed) {
            // expected
        }

        $row = remote_local_executor_operation_run($operationId);

        expect($row->status)
            ->toBe('failed')
            ->and((int) $row->exit_code)
            ->toBe(13)
            ->and($row->stdout_summary)
            ->toBe('leak --operation-token=<redacted>')
            ->and($row->stdout_summary)
            ->not->toContain('operation-token=eyJ');

        $error = remote_local_executor_json_object($row->error);
        expect($error['code'])->toBe('remote_shell_failed');
    });

    it('records an operation_runs row as failed when the transport throws a generic exception', function (): void {
        $operationId = '00000000-0000-4000-8000-000000000903';
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                throw new RuntimeException('transport boom');
            },
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                transportOptions: ['metadata' => ['ORBIT_OPERATION_ID' => $operationId]],
            );
            throw new RuntimeException('Expected transport exception.');
        } catch (RemoteLocalExecutorTransportFailed) {
            // expected
        }

        $row = remote_local_executor_operation_run($operationId);

        expect($row->status)
            ->toBe('failed')
            ->and($row->exit_code)
            ->toBeNull();

        $error = remote_local_executor_json_object($row->error);
        expect($error['code'])->toBe('transport_failed')->and($error['message'])->toContain('transport boom');
    });

    it('re-mints the same operation_id across two attempts and writes two operation_runs rows with distinct ids', function (): void {
        $operationId = '00000000-0000-4000-8000-000000000904';
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '{}', stderr: '', durationMs: 1),
        );
        $executor = remoteLocalExecutor($transport);
        $node = remoteLocalExecutorNode();

        $executor->runInternal(
            node: $node,
            commandName: 'internal:executor:verify',
            transportOptions: ['metadata' => ['ORBIT_OPERATION_ID' => $operationId]],
        );
        $executor->runInternal(
            node: $node,
            commandName: 'internal:executor:verify',
            transportOptions: ['metadata' => ['ORBIT_OPERATION_ID' => $operationId]],
        );

        $rows = DB::table('operation_runs')->where('operation_id', $operationId)->orderBy('created_at')->get();

        $tokenAttemptIds = array_map(
            static fn (array $call): string => OperationToken::parse(
                remoteLocalExecutorTokenFromScript($call['script']),
            )->id,
            $transport->calls,
        );

        expect($rows)
            ->toHaveCount(2)
            ->and($rows[0]->id)
            ->not
            ->toBe($rows[1]->id)
            ->and($rows[0]->status)
            ->toBe('succeeded')
            ->and($rows[1]->status)
            ->toBe('succeeded')
            ->and($tokenAttemptIds)
            ->toBe([$rows[0]->id, $rows[1]->id]);
    });

    it('keeps activity_log properties JSON free of raw operation_token substrings even after recording operation_runs', function (): void {
        $operationId = '00000000-0000-4000-8000-000000000905';
        $transport = new RemoteLocalExecutorRecordingTransport(
            static function (Node $node, string $script, array $options): RemoteShellResult {
                $token = remoteLocalExecutorTokenFromScript($script);

                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: "stdout --operation-token={$token}",
                    stderr: '',
                    durationMs: 5,
                );
            },
        );
        $executor = remoteLocalExecutor($transport);

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            transportOptions: ['metadata' => ['ORBIT_OPERATION_ID' => $operationId]],
        );

        $blob = remoteLocalExecutorActivityLogBlob();

        // No raw `--operation-token=<jwt-shaped>` substring (jwt header bytes "eyJ") should leak into activity rows.
        expect($blob)
            ->not
            ->toContain('--operation-token=eyJ')
            ->and($blob)
            ->toContain('--operation-token=<redacted>');
    });

    it('keeps default executor bindings while making the local executor explicitly resolvable', function (): void {
        config()->set('app.key', 'gateway-app-key');
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(RemoteLocalExecutor::class);
        app()->forgetInstance(OperationTokenFactory::class);

        expect(app(RemoteLocalExecutor::class))->toBeInstanceOf(RemoteLocalExecutor::class);
    });

    it('surfaces missing operation token configuration during explicit resolution', function (): void {
        config()->set('app.key', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(RemoteLocalExecutor::class);
        app()->forgetInstance(OperationTokenFactory::class);

        try {
            expect(fn (): RemoteLocalExecutor => app(RemoteLocalExecutor::class))
                ->toThrow(RuntimeException::class, 'Application key is not configured for operation token signing.');
        } finally {
            config()->set('app.key', 'gateway-app-key');
        }
    });

    it('strips force_remote_host for non-gateway Agent-push targets so tokens stay valid', function (): void {
        $previousExposureMode = getenv('ORBIT_GATEWAY_EXPOSURE_MODE');
        Http::preventStrayRequests();
        putenv('ORBIT_GATEWAY_EXPOSURE_MODE=router-colocated');

        try {
            $transport = new RemoteLocalExecutorRecordingTransport(
                static fn (): RemoteShellResult => new RemoteShellResult(
                    exitCode: 0,
                    stdout: "agent-push-ok\n",
                    stderr: '',
                    durationMs: 1,
                ),
            );
            $executor = remoteLocalExecutor(transport: $transport);
            $node = remoteLocalExecutorNode(['ingress']);

            $result = $executor->runInternal(
                node: $node,
                commandName: 'internal:executor:verify',
                transportOptions: [
                    'force_remote_host' => true,
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => '00000000-0000-4000-8000-000000000431',
                    ],
                ],
            );

            expect($result->stdout)
                ->toBe("agent-push-ok\n")
                ->and($transport->calls)
                ->not->toBeEmpty();

            $script = (string) ($transport->calls[0]['script'] ?? '');

            expect($script)
                ->toContain('internal:executor:verify')
                ->toContain('--operation-token=')
                // Host-boundary normalization must not force a gateway-home cwd
                // for Agent-push targets.
                ->not->toContain("cd '/home/orbit'");
        } finally {
            if ($previousExposureMode === false) {
                putenv('ORBIT_GATEWAY_EXPOSURE_MODE');
            } else {
                putenv("ORBIT_GATEWAY_EXPOSURE_MODE={$previousExposureMode}");
            }
        }
    });
});

function remoteLocalExecutor(
    RemoteLocalExecutorRecordingTransport $transport,
    ?OperationTokenFactory $operationTokens = null,
    ?LocalExecutorCommandComposer $commands = null,
    bool $stubAgent = true,
): RemoteLocalExecutor {
    app()->instance(
        OperationTokenIntrospector::class,
        new OperationTokenIntrospector(
            verifier: new OperationTokenVerifier(new OperationTokenSigner),
            secretsByKeyId: ['current' => 'gateway-secret'],
            clock: static fn (): int => 1_798_105_200,
        ),
    );

    if ($stubAgent) {
        Http::stubUrl('http://10.44.0.70:9477/v1/commands', function (Request $request) use ($transport) {
            $node = Node::query()->where('wireguard_address', '10.44.0.70')->firstOrFail();
            $payload = remote_local_executor_json_object($request->body());
            $arguments = is_array($payload['argv'] ?? null) ? $payload['argv'] : [];
            $result = $transport->run($node, implode(' ', $arguments));

            return Http::response([
                'transport' => 'agent-push',
                'operation_id' => $payload['operation_id'] ?? 'unknown',
                'binary' => 'orbit',
                'status' => $result->successful() ? 'succeeded' : 'failed',
                'exit_code' => $result->exitCode,
                'frames' => [
                    ['type' => 'stdout', 'message' => $result->stdout],
                    ['type' => 'stderr', 'message' => $result->stderr],
                    ['type' => 'exit', 'message' => (string) $result->exitCode],
                ],
            ]);
        });
    }

    return new RemoteLocalExecutor(
        commands: $commands ?? new LocalExecutorCommandBuilder,
        operationTokens: $operationTokens ?? remoteLocalExecutorTokenFactory(),
        activityLogger: remoteLocalExecutorActivityLogger(),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        applicationKey: 'gateway-secret',
    );
}

function remoteLocalExecutorActivityLogger(): ActivityLogger
{
    return new ActivityLogger(new ActivityLogCorrelation);
}

/**
 * @return array<string, string>
 */
function remoteLocalExecutorEnvironment(): array
{
    return [
        'HOME' => '/home/orbit',
        'ORBIT_CONFIG_PATH' => '/home/orbit/.config/orbit/config.json',
        'ORBIT_BIN_PATH' => '/home/orbit/.local/bin/orbit',
        'APP_KEY' => 'gateway-secret',
    ];
}

function remoteLocalExecutorStartUnsupportedMessage(): string
{
    return 'RemoteLocalExecutor::startInternal() is not supported. Long-running local-executor processes are not currently audited; use runInternal() for completion-based dispatch. See apps/docs/content/execution-lanes.md.';
}

/**
 * @param  (Closure(): int)|null  $clock
 */
function remoteLocalExecutorTokenFactory(?Closure $clock = null): OperationTokenFactory
{
    $signingMaterial = 'gateway-secret';

    return new OperationTokenFactory(
        signer: new OperationTokenSigner,
        secret: $signingMaterial,
        ttlSeconds: 120,
        clock: $clock ?? static fn (): int => 1_798_105_200,
    );
}

/**
 * @param  list<string>  $roles
 */
function remoteLocalExecutorNode(array $roles = ['app-dev']): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev',
        'host' => 'app-dev.example.com',
        'wireguard_address' => '10.44.0.70',
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIRemoteLocalExecutorPinnedKey',
        'host_key_fingerprint' => 'SHA256:remote-local-executor',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Expected node factory to create a node model.');
    }

    foreach ($roles as $role) {
        NodeRoleAssignment::factory()->create([
            'node_id' => remote_local_executor_node_id($node),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    return $node;
}

function remoteLocalExecutorTokenFromScript(string $script): string
{
    $matches = [];

    preg_match("/--operation-token=(?:'([^']+)'|(\\S+))/", $script, $matches);

    return ($matches[1] ?? '') !== '' ? $matches[1] : $matches[2] ?? '';
}

function remoteLocalExecutorTokenFromNestedSshCommand(string $command): string
{
    $matches = [];

    // Nested ssh/bash -lc quoting wraps --operation-token=... so extract the
    // compact 8-segment token by shape rather than simple shell word boundaries.
    preg_match('/([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){7})/', $command, $matches);

    $token = $matches[1] ?? '';

    if ($token === '') {
        throw new RuntimeException('Unable to extract operation token from nested SSH command.');
    }

    return $token;
}

/**
 * @return list<stdClass>
 */
function remoteLocalExecutorActivityRows(): array
{
    $rows = DB::table('activity_log')
        ->where('log_name', 'api')
        ->whereIn('event', [
            'agent_push.dispatching',
            'agent_push.completed',
            'gateway_local.dispatching',
            'gateway_local.completed',
            'force_remote_host.dispatching',
            'force_remote_host.completed',
        ])
        ->orderBy('id')
        ->get()
        ->all();

    return array_values($rows);
}

/**
 * @return array<array-key, mixed>
 */
function remoteLocalExecutorActivityProperties(stdClass $activity): array
{
    return remote_local_executor_json_object($activity->properties ?? null);
}

/**
 * @param  array<array-key, mixed>  $properties
 */
function remote_local_executor_activity_property_string(array $properties, string $key): string
{
    if (! array_key_exists($key, $properties) || ! is_string($properties[$key])) {
        throw new RuntimeException("Expected activity property [{$key}] to be a string.");
    }

    return $properties[$key];
}

function remoteLocalExecutorActivityLogBlob(): string
{
    return DB::table('activity_log')
        ->orderBy('id')
        ->get()
        ->map(fn (stdClass $activity): string => json_encode(get_object_vars($activity), JSON_THROW_ON_ERROR))
        ->implode("\n");
}

function remote_local_executor_default_agent_push_request_matches(Request $request): bool
{
    $body = $request->body();
    $payload = remote_local_executor_json_object($body);
    $operationToken = remote_local_executor_agent_push_operation_token($payload);

    return (
        $request->url() === 'http://10.44.0.70:9477/v1/commands'
        && $operationToken !== null
        && ($payload['operation_id'] ?? null) === OperationToken::parse($operationToken)->id
        && ($payload['environment'] ?? null) == remoteLocalExecutorEnvironment()
        && ! array_key_exists(
            'ORBIT_REQUEST_MARKER',
            is_array($payload['environment'] ?? null) ? $payload['environment'] : [],
        )
        && remote_local_executor_request_body_contains_all($body, [
            '"command_id":"orbit.agent.binary"',
            '"binary":"orbit"',
            '"argv":["internal:workspace-source:create","\/srv\/docs","feature-docs","main","--operation-token=',
            '--json"',
            '"input":"{\\"probe\\":true}"',
            '"timeout_seconds":45',
        ])
    );
}

function remote_local_executor_stream_agent_push_request_matches(Request $request): bool
{
    $body = $request->body();
    $payload = remote_local_executor_json_object($body);
    $operationToken = remote_local_executor_agent_push_operation_token($payload);

    return (
        $request->url() === 'http://10.44.0.70:9477/v1/commands/stream'
        && $operationToken !== null
        && ($payload['operation_id'] ?? null) === OperationToken::parse($operationToken)->id
        && remote_local_executor_request_body_contains_all($body, [
            '"command_id":"orbit.agent.binary"',
            '"binary":"orbit"',
            '"argv":["internal:process-logs","--operation-token=',
            '--json"',
            '"input":"{\\"follow\\":true}"',
            '"stream":true',
        ])
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function remote_local_executor_agent_push_operation_token(array $payload): ?string
{
    $argv = $payload['argv'] ?? null;

    if (! is_array($argv)) {
        return null;
    }

    foreach ($argv as $argument) {
        if (is_string($argument) && str_starts_with($argument, '--operation-token=')) {
            return substr($argument, strlen('--operation-token='));
        }
    }

    return null;
}

function remote_local_executor_node_id(Node $node): int
{
    return $node->id;
}

/**
 * @param  list<string>  $needles
 */
function remote_local_executor_request_body_contains_all(string $body, array $needles): bool
{
    foreach ($needles as $needle) {
        if (! str_contains($body, $needle)) {
            return false;
        }
    }

    return true;
}

function remote_local_executor_operation_run(string $operationId): stdClass
{
    $row = DB::table('operation_runs')->where('operation_id', $operationId)->first();

    if (! $row instanceof stdClass) {
        throw new RuntimeException("Expected operation run [{$operationId}] to exist.");
    }

    return $row;
}

/**
 * @return array<array-key, mixed>
 *
 * @mago-expect lint:inline-variable-return
 */
function remote_local_executor_json_object(mixed $json): array
{
    if (! is_string($json)) {
        throw new RuntimeException('Expected JSON value to be a string.');
    }

    /** @var array<array-key, mixed> $decoded */
    $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * @param  array{code: string, message: string, retryable: bool}|null  $agentError
 */
function remote_local_executor_process_stream_body(
    string $stdout = '',
    string $stderr = '',
    ?array $agentError = null,
    ?int $exitCode = 0,
    ?int $signal = null,
): string {
    $body = '';

    if ($stdout !== '') {
        $body .= remote_local_executor_process_stream_frame(1, $stdout);
    }

    if ($stderr !== '') {
        $body .= remote_local_executor_process_stream_frame(2, $stderr);
    }

    if ($agentError !== null) {
        $body .= remote_local_executor_process_stream_frame(
            3,
            json_encode($agentError, JSON_THROW_ON_ERROR),
        );
    }

    $body .= remote_local_executor_process_stream_frame(
        4,
        json_encode([
            'exit_code' => $exitCode,
            'signal' => $signal,
            'duration_ms' => 0,
        ], JSON_THROW_ON_ERROR),
    );

    return $body;
}

function remote_local_executor_process_stream_frame(int $type, string $payload): string
{
    return pack('CCnN', $type, 0, 0, strlen($payload)).$payload;
}

final class RemoteLocalExecutorRecordingTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    /**
     * @param  RemoteShellResult|Closure(Node, string, array<string, mixed>): RemoteShellResult  $result
     */
    public function __construct(
        private readonly RemoteShellResult|Closure $result,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        if ($this->result instanceof Closure) {
            return ($this->result)($node, $script, $options);
        }

        return $this->result;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('The recording transport does not start processes.');
    }
}
