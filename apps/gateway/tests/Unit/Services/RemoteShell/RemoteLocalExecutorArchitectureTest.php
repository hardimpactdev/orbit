<?php

declare(strict_types=1);

use App\Services\NodeCommandTransport\NodeAgentPushDispatcher;
use App\Services\RemoteShell\GatewayLocalCommandDispatcher;
use App\Services\RemoteShell\LocalExecutorTransportOptions;
use App\Services\RemoteShell\RemoteExecutorOutputRedactor;
use App\Services\RemoteShell\RemoteLocalExecutor;

it('keeps transport options and output redaction outside the executor coordinator', function (): void {
    $executor = new ReflectionClass(RemoteLocalExecutor::class);
    $constructor = $executor->getConstructor();

    expect($constructor)->not->toBeNull();

    $dependencies = array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $constructor->getParameters(),
    );

    expect($dependencies)
        ->toContain(GatewayLocalCommandDispatcher::class)
        ->toContain(NodeAgentPushDispatcher::class)
        ->toContain(RemoteExecutorOutputRedactor::class)
        ->and($executor->hasMethod('sanitizedResult'))
        ->toBeFalse()
        ->and($executor->hasMethod('outputSummary'))
        ->toBeFalse()
        ->and($executor->hasMethod('transportExceptionMetadata'))
        ->toBeFalse()
        ->and($executor->hasMethod('redactedCommandOptionNames'))
        ->toBeFalse()
        ->and($executor->hasMethod('runAgentPush'))
        ->toBeFalse()
        ->and($executor->hasMethod('streamAgentPush'))
        ->toBeFalse()
        ->and($executor->hasMethod('agentPushResult'))
        ->toBeFalse()
        ->and($executor->hasMethod('runGatewayLocal'))
        ->toBeFalse();
});

it('keeps the extracted contracts final and readonly', function (string $class): void {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
})->with([
    LocalExecutorTransportOptions::class,
    GatewayLocalCommandDispatcher::class,
    NodeAgentPushDispatcher::class,
    RemoteExecutorOutputRedactor::class,
]);
