<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Closure;
use LogicException;
use Throwable;

/**
 * Answers `databases.write-valkey-acl` and records each input with the Node it ran on. Every other agent command goes
 * to `$others`, the runner the container held before.
 */
final class FakeValkeyAclAgent implements AgentCommandRunner
{
    /** @var list<array{node: string, input: array<array-key, mixed>}> */
    public array $runs = [];

    /** `written`, or `failed` for a refusal with a docker exit code. */
    public string $outcome = 'written';

    /** A failure to throw instead, such as a runner failure without a result. */
    public ?Throwable $failure = null;

    /** @var (Closure(): void)|null Runs during the command, such as a check of what changed before it. */
    public ?Closure $during = null;

    public function __construct(private readonly ?AgentCommandRunner $others = null) {}

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        if ($command !== 'databases.write-valkey-acl') {
            return ($this->others ?? throw new LogicException("Unexpected agent command [{$command}]."))->run($node, $command, $input, $deadlineSeconds);
        }

        $this->runs[] = ['node' => $node->name, 'input' => $input];

        if ($this->during instanceof Closure) {
            ($this->during)();
        }

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        if ($this->outcome === 'failed') {
            throw new AgentCommandRefused($command, 'databases.valkey_acl_failed', 'The ACL container failed.', ['exit_code' => 125]);
        }

        return [];
    }

    public function install(Node $node): bool
    {
        return $this->others?->install($node) ?? false;
    }
}
