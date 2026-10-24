<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;

/** Supplies PHP build intent responses on the same fake agent runner as recipe commands. */
final class PhpBuildAnswers
{
    /** @var list<array<string, mixed>> */
    public array $inputs = [];

    /** @param array<string, array{0: string, 1: array<string, mixed>}> $answers */
    public function __construct(public array $answers = []) {}

    public function register(FakeAgentCommandRunner $runner): self
    {
        foreach (['plan' => 'php.inspect-build', 'apply' => 'php.install-build'] as $mode => $command) {
            $runner->answer($command, function (array $input) use ($mode, $command): array {
                $this->inputs[] = ['mode' => $mode, ...$input];
                [$outcome, $data] = $this->answers[$mode] ?? ($mode === 'plan'
                    ? ['planned', ['pending' => [], 'repair' => [], 'extras' => [], 'unowned' => []]]
                    : ['current', ['changed' => [], 'purged' => [], 'restarted' => [], 'deferred' => [], 'stale_masters' => []]]);
                if (! in_array($outcome, ['planned', 'current', 'applied'], true)) {
                    throw new AgentCommandRefused($command, 'php.build_'.$outcome, 'PHP build refused.', $data);
                }

                return $mode === 'plan' ? $data : ['outcome' => $outcome, 'data' => $data];
            });
        }

        return $this;
    }
}
