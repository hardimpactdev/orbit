<?php

declare(strict_types=1);

use App\Domain\Operations\EffectReceiptMetadata;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentTransport;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Models\Node;
use Tests\Support\ProcessInput;

function nodeInstallerRunner(array $answers): array
{
    $transport = new class($answers) implements AgentTransport
    {
        public array $calls = [];

        public int $stages = 0;

        public function __construct(private array $answers) {}

        public function platform(): string
        {
            return 'linux';
        }

        public function binaryPath(): string
        {
            return '/usr/local/bin/orbit-agent';
        }

        public function stage(string $localFile, float $timeoutSeconds): string
        {
            $this->stages++;

            return '/var/tmp/private-agent-candidate';
        }

        public function run(array $arguments, ProtectedInput $envelope, float $timeout, ?Closure $output = null): CommandResult
        {
            $header = explode("\n", ProcessInput::contents($envelope), 2)[0];
            $this->calls[] = ['arguments' => $arguments, 'envelope' => json_decode($header, true, flags: JSON_THROW_ON_ERROR)];
            $answer = array_shift($this->answers) ?? throw new LogicException('Unexpected remote invocation.');

            return new CommandResult(0, json_encode(['ok' => true, 'data' => $answer], JSON_THROW_ON_ERROR)."\n", '', 1, false);
        }
    };
    $transports = Mockery::mock(AgentTransports::class);
    $transports->shouldReceive('for')->andReturn($transport);
    $runner = new NativeAgentCommandRunner(app(AgentContract::class), app(AgentDelivery::class), $transports);
    $node = Node::query()->create(['name' => 'installer-proof', 'public_ssh_host' => '192.0.2.10', 'platform' => 'linux', 'architecture' => 'x86_64']);

    return [$runner, $node, $transport, app(AgentDelivery::class)->pin($node)];
}

it('sends installer effect identity and original masks through protected stdin', function (): void {
    [$runner, $node, $transport, $pin] = nodeInstallerRunner([['replaced' => true]]);

    $changed = $runner->installEffect($node, $pin, ['effect_key' => 'op-123.install', 'effect_metadata' => new EffectReceiptMetadata(['existing-connection-private'], ['nonce' => '8d66ea16-8f73-44b1-a501-211a3e41cd78', 'created_at' => 1793232000])]);

    expect($changed)->toBeTrue();
    expect($transport->calls[0]['envelope']['input'])->toMatchArray(['sha256' => $pin, 'effect_key' => 'op-123.install']);
    expect($transport->calls[0]['envelope']['effect_receipt'])->toBe(['redactions' => ['existing-connection-private'], 'nonce' => '8d66ea16-8f73-44b1-a501-211a3e41cd78', 'created_at' => 1793232000]);
});

it('reads the original installer receipt with a staged binary without reinstalling or clearing failure state', function (): void {
    [$runner, $node, $transport, $pin] = nodeInstallerRunner([['effect_key' => 'op-123.install', 'state' => 'succeeded',
        'result' => ['ok' => true, 'data' => ['replaced' => true]], 'sanitized' => true,
        'exit_code' => 0, 'finished_at' => '2026-10-29T00:00:00Z']]);
    $node->forceFill(['agent_failed_pin' => $pin])->save();

    $receipt = $runner->effectReceipt($node, $pin, 'agent.install', 'op-123.install');

    expect($receipt['result'])->toBe(['replaced' => true]);
    expect($transport->calls)->toHaveCount(1);
    expect($transport->calls[0]['arguments'])->toContain('/var/tmp/private-agent-candidate', 'agent.effect-receipt');
    expect($transport->calls[0]['envelope']['input'])->toBe(['command' => 'agent.install', 'effect_key' => 'op-123.install', 'owner' => null]);
    expect($transport->calls[0]['envelope'])->not->toHaveKey('effect_receipt');
    expect($node->fresh()->agent_failed_pin)->toBe($pin);
});

it('refuses a different admitted pin before any staging or invocation', function (string $method): void {
    [$runner, $node, $transport] = nodeInstallerRunner([]);

    expect(fn () => $method === 'install'
        ? $runner->installEffect($node, str_repeat('f', 64), [])
        : $runner->effectReceipt($node, str_repeat('f', 64), 'agent.install', 'op-123.install'))
        ->toThrow(ResourceOperationException::class);

    expect($transport->stages)->toBe(0);
    expect($transport->calls)->toBe([]);
})->with(['install', 'receipt']);

it('refuses a settled native receipt without original sanitization proof', function (): void {
    [$runner, $node, , $pin] = nodeInstallerRunner([['effect_key' => 'op-123.install', 'state' => 'succeeded',
        'result' => ['ok' => true, 'data' => ['replaced' => true]], 'sanitized' => false]]);

    expect(fn () => $runner->effectReceipt($node, $pin, 'agent.install', 'op-123.install'))
        ->toThrow(ResourceOperationException::class, 'sanitization');
});
