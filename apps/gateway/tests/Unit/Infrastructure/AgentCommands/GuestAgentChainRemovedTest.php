<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskDeliverableVerifier;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\AgentCommands\SshAgentTransport;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Models\Node;

/** Nothing built an agent guest, so its runner, transports and errors are gone (docs/reference/old-paths.md#the-guest-agent-chain). */
it('has no guest agent class or interface', function (string $name): void {
    expect(class_exists($name) || interface_exists($name))->toBeFalse();
})->with([
    'App\Infrastructure\AgentCommands\AgentGuest',
    'App\Infrastructure\AgentCommands\GuestAgentCommandRunner',
    'App\Infrastructure\AgentCommands\IncusAgentTransport',
]);

it('runs agent commands for a Node only', function (): void {
    expect(class_implements(NativeAgentCommandRunner::class))->toEqualCanonicalizing([
        AdmissionAgentRunner::class => AdmissionAgentRunner::class,
        AgentCommandRunner::class => AgentCommandRunner::class,
        WorkspaceAgentCommandRunner::class => WorkspaceAgentCommandRunner::class,
    ])
        ->and(new ReflectionClass(NativeAgentCommandRunner::class)->hasMethod('runGuest'))->toBeFalse()
        ->and(new ReflectionClass(NativeAgentCommandRunner::class)->hasMethod('installGuest'))->toBeFalse()
        ->and(new ReflectionClass(AgentTransports::class)->hasMethod('guest'))->toBeFalse()
        ->and(app(AgentCommandRunner::class))->toBeInstanceOf(NativeAgentCommandRunner::class);

    $node = static fn (string $class, string $method, int $position): string => (string) new ReflectionMethod($class, $method)->getParameters()[$position]->getType();
    expect($node(AgentDelivery::class, 'key', 0))->toBe(Node::class)
        ->and($node(AgentDelivery::class, 'pin', 0))->toBe(Node::class)
        ->and($node(AgentDelivery::class, 'localFile', 0))->toBe(Node::class)
        ->and($node(SshAgentTransport::class, '__construct', 0))->toBe(Node::class);
});

/** The deliverable evidence verifier had no caller since checks moved to Swamp. */
it('keeps only the receipt check and the path match of the deliverable verifier', function (): void {
    expect(class_exists('App\Domain\Tasks\TaskDeliverableEvidence'))->toBeFalse()
        ->and(new ReflectionClass(TaskDeliverableVerifier::class)->hasMethod('failures'))->toBeFalse()
        ->and(TaskDeliverableVerifier::matches('src/**/*.{php,js}', 'src/a/b.php'))->toBeTrue()
        ->and(TaskDeliverableVerifier::unconfirmed([], [], TaskThreadRole::Implementer))->toBe([]);
});
