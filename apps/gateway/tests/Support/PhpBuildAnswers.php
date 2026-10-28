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

    /**
     * A whole `php.install-build` result, as the agent returns it.
     *
     * @param  'current'|'applied'  $outcome
     * @param  array<string, mixed>  $data
     * @return array{outcome: string, data: array<string, mixed>}
     */
    public static function result(string $outcome = 'current', array $data = []): array
    {
        return ['outcome' => $outcome, 'data' => [
            'changed' => [], 'repaired' => [], 'purged' => [], 'disabled' => [], 'restarted' => [], 'reloaded' => [],
            'deferred' => [], 'stale_masters' => [], 'fpm_disabled' => false, ...$data,
        ]];
    }

    public function register(FakeAgentCommandRunner $runner): self
    {
        $runner->answer('php.configure-runtime', ['changed' => false, 'reloaded' => false]);
        foreach (['plan' => 'php.inspect-build', 'apply' => 'php.install-build'] as $mode => $command) {
            $runner->answer($command, function (array $input) use ($mode, $command): array {
                $this->inputs[] = ['mode' => $mode, ...$input];
                [$outcome, $data] = $this->answers[$mode] ?? ($mode === 'plan'
                    ? ['planned', ['pending' => [], 'repair' => [], 'extras' => [], 'unowned' => []]]
                    : ['current', []]);
                if (! in_array($outcome, ['planned', 'current', 'applied'], true)) {
                    throw new AgentCommandRefused($command, 'php.build_'.$outcome, 'PHP build refused.', $data);
                }

                return $mode === 'plan' ? $data : self::result($outcome, $data);
            });
        }

        return $this;
    }
}
