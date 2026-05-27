<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe(RemoteLocalExecutor::class, function (): void {
    it('mints a token, builds a local executor command, dispatches through transport, and returns the transport result', function (): void {
        $transportResult = new RemoteShellResult(exitCode: 0, stdout: "{\"ok\":true}\n", stderr: '', durationMs: 17);
        $transport = new RemoteLocalExecutorRecordingTransport($transportResult);
        $executor = remoteLocalExecutor($transport);
        $node = remoteLocalExecutorNode();
        $operationId = '00000000-0000-4000-8000-000000000402';

        $result = $executor->runInternal(
            node: $node,
            commandName: 'internal:workspace-adapter',
            arguments: ['lookup', 'polyscope'],
            commandOptions: [
                'state-path' => "/home/orbit/.polyscope/state's.db",
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

        expect($result)->toBe($transportResult)
            ->and($transport->calls)->toHaveCount(1)
            ->and($transport->calls[0]['node']->is($node))->toBeTrue()
            ->and($transport->calls[0]['options'])->toBe([
                'timeout' => 45,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $operationId,
                    'ORBIT_REQUEST_ID' => 'local-req',
                ],
            ]);

        $script = $transport->calls[0]['script'];
        $compactToken = remoteLocalExecutorTokenFromScript($script);
        $token = OperationToken::parse($compactToken);
        $auditLine = (new LocalExecutorCommandBuilder)->buildAuditLine(
            commandName: 'internal:workspace-adapter',
            arguments: ['lookup', 'polyscope'],
            options: [
                'state-path' => "/home/orbit/.polyscope/state's.db",
                'enabled' => true,
                'attempts' => 3,
            ],
            operationToken: $compactToken,
        );

        expect($script)->toBe((new LocalExecutorCommandBuilder)->build(
            commandName: 'internal:workspace-adapter',
            arguments: ['lookup', 'polyscope'],
            options: [
                'state-path' => "/home/orbit/.polyscope/state's.db",
                'enabled' => true,
                'attempts' => 3,
            ],
            operationToken: $compactToken,
        ))
            ->and($script)->not->toContain('docker exec')
            ->and(substr_count($script, '--operation-token='))->toBe(1)
            ->and(substr_count($script, $compactToken))->toBe(1)
            ->and($token->id)->toBe($operationId)
            ->and($token->node)->toBe($node->name)
            ->and($token->command)->toBe('internal:workspace-adapter')
            ->and($token->issued_at)->toBe(1_798_105_200)
            ->and($token->expires_at)->toBe(1_798_105_320)
            ->and((new OperationTokenVerifier)->verify(
                secret: 'gateway-secret',
                token: $token,
                expectedNode: $node->name,
                expectedCommand: 'internal:workspace-adapter',
                now: 1_798_105_200,
            ))->toBeTrue();

        $activities = remoteLocalExecutorActivityRows();
        [$started, $completed] = $activities;
        $startedProperties = remoteLocalExecutorActivityProperties($started);
        $completedProperties = remoteLocalExecutorActivityProperties($completed);

        expect($activities)->toHaveCount(2)
            ->and($started->event)->toBe('local_executor.dispatching')
            ->and($started->description)->toBe('Local executor operation dispatching')
            ->and($started->subject_type)->toBe($node->getMorphClass())
            ->and((int) $started->subject_id)->toBe($node->getKey())
            ->and($startedProperties)->toMatchArray([
                'type' => 'write',
                'lane' => 'local-executor',
                'status' => 'dispatching',
                'operation_id' => $operationId,
                'target_node_id' => $node->getKey(),
                'target_node_name' => 'app-dev',
                'command' => 'internal:workspace-adapter',
                'arguments' => ['lookup', 'polyscope'],
                'command_options' => [
                    'state-path' => "/home/orbit/.polyscope/state's.db",
                    'enabled' => true,
                    'attempts' => 3,
                ],
                'command_line' => $auditLine,
            ])
            ->and($completed->event)->toBe('local_executor.completed')
            ->and($completed->description)->toBe('Local executor operation succeeded')
            ->and($completedProperties)->toMatchArray([
                'type' => 'write',
                'lane' => 'local-executor',
                'status' => 'succeeded',
                'operation_id' => $operationId,
                'target_node_id' => $node->getKey(),
                'target_node_name' => 'app-dev',
                'command' => 'internal:workspace-adapter',
                'exit_code' => 0,
                'stdout_summary' => "{\"ok\":true}\n",
                'stderr_summary' => '',
            ])
            ->and(remoteLocalExecutorActivityLogBlob())->not->toContain($compactToken);
    });

    it('supports command-name-only run calls for the RemoteShell interface method', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: "verified\n", stderr: '', durationMs: 3),
        );
        $executor = remoteLocalExecutor($transport);
        $node = remoteLocalExecutorNode();

        $result = $executor->run($node, 'internal:executor:verify', ['timeout' => 10]);

        expect($result->stdout)->toBe("verified\n")
            ->and($transport->calls)->toHaveCount(1)
            ->and($transport->calls[0]['options'])->toBe(['timeout' => 10]);

        $script = $transport->calls[0]['script'];
        $compactToken = remoteLocalExecutorTokenFromScript($script);

        expect($script)->toBe((new LocalExecutorCommandBuilder)->build(
            commandName: 'internal:executor:verify',
            arguments: [],
            options: [],
            operationToken: $compactToken,
        ));
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
        ))->toThrow(RuntimeException::class, remoteLocalExecutorStartUnsupportedMessage());

        expect($transport->calls)->toBeEmpty()
            ->and(remoteLocalExecutorActivityRows())->toBeEmpty();
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
        ))->toThrow(RuntimeException::class, remoteLocalExecutorStartUnsupportedMessage());

        expect($transport->calls)->toBeEmpty()
            ->and(remoteLocalExecutorActivityRows())->toBeEmpty();
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
        ))->toThrow(LocalExecutorCommandBuilderException::class);

        expect($transport->calls)->toBeEmpty()
            ->and(remoteLocalExecutorActivityRows())->toBeEmpty();
    });

    it('surfaces operation token factory failures without dispatching to transport', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        $executor = new RemoteLocalExecutor(
            transport: $transport,
            commands: new LocalExecutorCommandBuilder,
            operationTokens: remoteLocalExecutorTokenFactory(
                clock: static fn (): int => throw new RuntimeException('Operation token signing secret is required.'),
            ),
            activityLogger: remoteLocalExecutorActivityLogger(),
        );

        expect(fn (): RemoteShellResult => $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        ))->toThrow(RuntimeException::class, 'Operation token signing secret is required.');

        expect($transport->calls)->toBeEmpty()
            ->and(remoteLocalExecutorActivityRows())->toBeEmpty();
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

        expect($result->exitCode)->toBe(13)
            ->and($completedProperties)->toMatchArray([
                'lane' => 'local-executor',
                'status' => 'failed',
                'command' => 'internal:executor:verify',
                'exit_code' => 13,
                'stdout_summary' => "stdout echoed --operation-token=<redacted>\n",
                'stderr_summary' => "stderr echoed --operation-token=<redacted>\n",
            ])
            ->and(remoteLocalExecutorActivityLogBlob())->not->toContain($token);
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

            $this->fail('Expected the local executor transport to throw.');
        } catch (RemoteShellFailed $exception) {
            $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception->getMessage())->not->toContain($token)
                ->and($exception->script)->not->toContain($token)
                ->and($exception->result->stdout)->not->toContain($token)
                ->and($exception->result->stderr)->not->toContain($token)
                ->and($exception->getTraceAsString())->not->toContain($token)
                ->and($completedProperties)->toMatchArray([
                    'lane' => 'local-executor',
                    'status' => 'failed',
                    'command' => 'internal:executor:verify',
                    'exit_code' => 19,
                    'stdout_summary' => "stdout leak <redacted>\n",
                    'stderr_summary' => "stderr leak --operation-token=<redacted>\n",
                ])
                ->and(remoteLocalExecutorActivityLogBlob())->not->toContain($token);
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

            $this->fail('Expected the local executor transport to throw.');
        } catch (RuntimeException $exception) {
            $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception)->not->toBeInstanceOf(RemoteShellFailed::class)
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->toBe('Remote local executor transport failed: transport leaked --operation-token=<redacted>')
                ->and($exception->getMessage())->not->toContain($token)
                ->and($exception->getTraceAsString())->not->toContain($token)
                ->and($completedProperties)->toMatchArray([
                    'lane' => 'local-executor',
                    'status' => 'failed',
                    'command' => 'internal:executor:verify',
                    'exit_code' => null,
                    'exception_class' => RuntimeException::class,
                    'exception_message' => 'transport leaked --operation-token=<redacted>',
                ])
                ->and(remoteLocalExecutorActivityLogBlob())->not->toContain($token);
        }
    });

    it('wraps generic transport failures with clean messages so traces cannot retain token-bearing method arguments', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (Node $node, string $script, array $options): RemoteShellResult => throw new RuntimeException('transport disconnected'),
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:executor:verify',
                arguments: [],
                commandOptions: [],
            );

            $this->fail('Expected the local executor transport to throw.');
        } catch (RuntimeException $exception) {
            $token = remoteLocalExecutorTokenFromScript($transport->calls[0]['script']);
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception)->not->toBeInstanceOf(RemoteShellFailed::class)
                ->and($exception->getPrevious())->toBeNull()
                ->and($exception->getMessage())->toBe('Remote local executor transport failed: transport disconnected')
                ->and($exception->getMessage())->not->toContain($token)
                ->and($exception->getTraceAsString())->not->toContain($token)
                ->and($completedProperties)->toMatchArray([
                    'lane' => 'local-executor',
                    'status' => 'failed',
                    'command' => 'internal:executor:verify',
                    'exit_code' => null,
                    'exception_class' => RuntimeException::class,
                    'exception_message' => 'transport disconnected',
                ])
                ->and(remoteLocalExecutorActivityLogBlob())->not->toContain($token);
        }
    });

    it('redacts operation-token output variant :dataset from any operation before storing summaries', function (Closure $output, string $expected): void {
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

        expect($completedProperties['stdout_summary'])->not->toContain($otherOperationToken)
            ->and($completedProperties['stdout_summary'])->toBe($expected);
    })->with([
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

        expect($logBlob)->not->toContain($token)
            ->and($logBlob)->toContain('--operation-token=<redacted>')
            ->and($logBlob)->toContain('raw token <redacted>');
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

        expect($completedProperties['stdout_summary'])->not->toContain($token)
            ->and($completedProperties['stdout_summary'])->toContain('<redacted>')
            ->and(str_ends_with($completedProperties['stdout_summary'], '[truncated]'))->toBeTrue();
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

        expect(strlen($completedProperties['stdout_summary']))->toBe(4_107)
            ->and(strlen($completedProperties['stderr_summary']))->toBe(4_107)
            ->and(str_ends_with($completedProperties['stdout_summary'], '[truncated]'))->toBeTrue()
            ->and(str_ends_with($completedProperties['stderr_summary'], '[truncated]'))->toBeTrue();
    });

    it('suppresses requested output summaries and strips redaction flags before dispatch :dataset', function (array $transportOptions, string $expectedStdoutSummary, string $expectedStderrSummary): void {
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
            commandName: 'internal:workspace-adapter:lookup',
            arguments: [],
            commandOptions: [
                'adapter' => 'polyscope',
                'lookup' => 'config',
                'app-path' => '/srv/docs',
            ],
            transportOptions: $transportOptions,
        );

        $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

        expect($transport->calls[0]['options'])->not->toHaveKeys(['redact_stdout', 'redact_stderr'])
            ->and($completedProperties['stdout_summary'])->toBe($expectedStdoutSummary)
            ->and($completedProperties['stderr_summary'])->toBe($expectedStderrSummary);
    })->with([
        'no redaction flags' => [
            ['timeout' => 30],
            '{"api_token":"poly-token-secret"}',
            'stderr secret',
        ],
        'stdout only' => [
            ['timeout' => 30, 'redact_stdout' => true],
            '<suppressed>',
            'stderr secret',
        ],
        'stderr only' => [
            ['timeout' => 30, 'redact_stderr' => true],
            '{"api_token":"poly-token-secret"}',
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

        $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:wg-easy:state',
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

        expect($script)->toContain("--password-hash='{$passwordHash}'")
            ->and($script)->toContain("--private-key='{$privateKey}'")
            ->and($script)->toContain("--pre-shared-key='{$preSharedKey}'")
            ->and($transport->calls[0]['options'])->toBe(['timeout' => 30])
            ->and($dispatchProperties['command_options'])->toMatchArray([
                'action' => 'upsert-peer',
                'password-hash' => '<redacted>',
                'private-key' => '<redacted>',
                'public-key' => 'peer-public-key-probe',
                'pre-shared-key' => '<redacted>',
            ])
            ->and($dispatchProperties['command_line'])->toContain('--password-hash=<redacted>')
            ->and($dispatchProperties['command_line'])->toContain('--private-key=<redacted>')
            ->and($dispatchProperties['command_line'])->toContain('--pre-shared-key=<redacted>')
            ->and(json_encode($dispatchProperties, JSON_THROW_ON_ERROR))->not->toContain($passwordHash)
            ->and(json_encode($dispatchProperties, JSON_THROW_ON_ERROR))->not->toContain($privateKey)
            ->and(json_encode($dispatchProperties, JSON_THROW_ON_ERROR))->not->toContain($preSharedKey);
    });

    it('suppresses generic transport exception messages when output redaction is requested', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            static fn (Node $node, string $script, array $options): RemoteShellResult => throw new RuntimeException('transport leaked api_token=poly-token-secret'),
        );
        $executor = remoteLocalExecutor($transport);

        try {
            $executor->runInternal(
                node: remoteLocalExecutorNode(),
                commandName: 'internal:workspace-adapter:lookup',
                arguments: [],
                commandOptions: [
                    'adapter' => 'polyscope',
                    'lookup' => 'config',
                    'app-path' => '/srv/docs',
                ],
                transportOptions: ['redact_stdout' => true],
            );

            $this->fail('Expected the local executor transport to throw.');
        } catch (RuntimeException $exception) {
            $completedProperties = remoteLocalExecutorActivityProperties(remoteLocalExecutorActivityRows()[1]);

            expect($exception->getMessage())->toBe('Remote local executor transport failed: <suppressed>')
                ->and($completedProperties['exception_message'])->toBe('<suppressed>')
                ->and(remoteLocalExecutorActivityLogBlob())->not->toContain('poly-token-secret');
        }
    });

    it('keeps default executor bindings while making the local executor explicitly resolvable', function (): void {
        config()->set('orbit.operation_token_secret', 'gateway-secret');
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(RemoteLocalExecutor::class);
        app()->forgetInstance(OperationTokenFactory::class);

        expect(app(RemoteLocalExecutor::class))->toBeInstanceOf(RemoteLocalExecutor::class);
    });

    it('surfaces missing operation token configuration during explicit resolution', function (): void {
        config()->set('orbit.operation_token_secret', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(RemoteLocalExecutor::class);
        app()->forgetInstance(OperationTokenFactory::class);

        try {
            expect(fn (): RemoteLocalExecutor => app(RemoteLocalExecutor::class))
                ->toThrow(RuntimeException::class, 'Operation token signing secret is not configured.');
        } finally {
            config()->set('orbit.operation_token_secret', 'gateway-secret');
        }
    });
});

function remoteLocalExecutor(RemoteLocalExecutorRecordingTransport $transport, ?OperationTokenFactory $operationTokens = null): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: $operationTokens ?? remoteLocalExecutorTokenFactory(),
        activityLogger: remoteLocalExecutorActivityLogger(),
    );
}

function remoteLocalExecutorActivityLogger(): ActivityLogger
{
    return new ActivityLogger(new ActivityLogCorrelation);
}

function remoteLocalExecutorStartUnsupportedMessage(): string
{
    return 'RemoteLocalExecutor::startInternal() is not supported. Long-running local-executor processes are not currently audited; use runInternal() for completion-based dispatch. See docs/execution-lanes.md.';
}

function remoteLocalExecutorTokenFactory(?Closure $clock = null): OperationTokenFactory
{
    return new OperationTokenFactory(
        signer: new OperationTokenSigner,
        secret: 'gateway-secret',
        ttlSeconds: 120,
        clock: $clock ?? static fn (): int => 1_798_105_200,
    );
}

function remoteLocalExecutorNode(): Node
{
    return Node::factory()->create([
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
}

function remoteLocalExecutorTokenFromScript(string $script): string
{
    preg_match("/--operation-token='([^']+)'/", $script, $matches);

    return $matches[1] ?? '';
}

/**
 * @return list<object>
 */
function remoteLocalExecutorActivityRows(): array
{
    return DB::table('activity_log')
        ->where('log_name', 'local_executor')
        ->orderBy('id')
        ->get()
        ->all();
}

/**
 * @return array<string, mixed>
 */
function remoteLocalExecutorActivityProperties(object $activity): array
{
    return json_decode((string) $activity->properties, true, flags: JSON_THROW_ON_ERROR);
}

function remoteLocalExecutorActivityLogBlob(): string
{
    return DB::table('activity_log')
        ->orderBy('id')
        ->get()
        ->map(fn (object $activity): string => json_encode((array) $activity, JSON_THROW_ON_ERROR))
        ->implode("\n");
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
