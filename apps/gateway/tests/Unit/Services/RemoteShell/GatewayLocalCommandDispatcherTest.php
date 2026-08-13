<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Operations\GatewayLocalOperationTokenAuthorizer;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Operations\OperationTokenIntrospector;
use App\Services\RemoteShell\GatewayLocalCommandDispatcher;
use App\Services\RemoteShell\RemoteOrbitGatewayExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('authorizes and runs a gateway-local command with trusted environment', function (): void {
    Process::preventStrayProcesses();
    Process::fake([
        '*' => Process::result(output: "gateway-local-ok\n"),
    ]);
    $secret = Str::random(32);
    $clock = static fn (): int => 1_798_105_200;
    app()->instance(OperationTokenIntrospector::class, new OperationTokenIntrospector(
        verifier: new OperationTokenVerifier(new OperationTokenSigner),
        secretsByKeyId: ['current' => $secret],
        clock: $clock,
    ));
    $node = Node::factory()->gateway()->create(['name' => 'gateway']);
    $run = app(OperationRunRecorder::class)->queued(
        operationId: (string) Str::uuid(),
        lane: 'local',
        internalCommand: 'internal:executor:verify',
        targetNodeId: $node->id,
    );
    $commandContext = OperationTokenCommandContext::fromTrustedDispatch(
        argv: [
            'internal:executor:verify',
            '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
        ],
        cwd: null,
        environment: [],
        input: null,
    );
    $operationToken = new OperationTokenFactory(
        signer: new OperationTokenSigner,
        secret: $secret,
        ttlSeconds: 120,
        clock: $clock,
    )
        ->mint(
            operationId: $run->id,
            targetNode: $node->name,
            command: 'internal:executor:verify',
            commandContext: $commandContext,
        )
        ->toString();
    $subject = new GatewayLocalCommandDispatcher(
        authorizer: app(GatewayLocalOperationTokenAuthorizer::class),
        executor: app(RemoteOrbitGatewayExecutor::class),
    );

    $result = $subject->run(
        node: $node,
        commandName: 'internal:executor:verify',
        script: "orbit internal:executor:verify --operation-token={$operationToken}",
        dispatch: [
            'operationId' => $run->operation_id,
            'operationToken' => $operationToken,
            'auditLine' => 'orbit internal:executor:verify --operation-token=<redacted>',
            'argv' => ['internal:executor:verify', "--operation-token={$operationToken}"],
            'commandContext' => $commandContext,
        ],
        dispatchOptions: [
            'environment' => [
                'ORBIT_TRUSTED_EXECUTION_LANE' => 'attacker-controlled',
            ],
        ],
    );

    expect($result->stdout)
        ->toBe("gateway-local-ok\n")
        ->and($run->refresh()->operation_token_consumed_at)
        ->not->toBeNull();

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (string) $process->command;

        return (
            str_contains($command, 'ORBIT_TRUSTED_EXECUTION_LANE=gateway-local')
            && ! str_contains($command, 'attacker-controlled')
        );
    });
});
