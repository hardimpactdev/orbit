<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\Exceptions\LocalExecutorCommandBuilderException;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'metadata' => ['ORBIT_REQUEST_ID' => 'local-req'],
            ],
        );

        expect($result)->toBe($transportResult)
            ->and($transport->calls)->toHaveCount(1)
            ->and($transport->calls[0]['node']->is($node))->toBeTrue()
            ->and($transport->calls[0]['options'])->toBe([
                'timeout' => 45,
                'metadata' => ['ORBIT_REQUEST_ID' => 'local-req'],
            ]);

        $script = $transport->calls[0]['script'];
        $compactToken = remoteLocalExecutorTokenFromScript($script);
        $token = OperationToken::parse($compactToken);

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

    it('surfaces builder failures without dispatching to transport', function (): void {
        $transport = new RemoteLocalExecutorRecordingTransport(
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        );
        $executor = remoteLocalExecutor($transport);

        expect(fn (): RemoteShellResult => $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'executor:verify',
            arguments: [],
            commandOptions: [],
        ))->toThrow(LocalExecutorCommandBuilderException::class);

        expect($transport->calls)->toBeEmpty();
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
        );

        expect(fn (): RemoteShellResult => $executor->runInternal(
            node: remoteLocalExecutorNode(),
            commandName: 'internal:executor:verify',
            arguments: [],
            commandOptions: [],
        ))->toThrow(RuntimeException::class, 'Operation token signing secret is required.');

        expect($transport->calls)->toBeEmpty();
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

function remoteLocalExecutor(RemoteLocalExecutorRecordingTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: remoteLocalExecutorTokenFactory(),
    );
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

final class RemoteLocalExecutorRecordingTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly RemoteShellResult $result,
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
