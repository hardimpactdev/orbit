<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Models\Node;
use Closure;
use LogicException;
use Symfony\Component\Process\Process;

/** Supplies checkout facts from the local repository fixtures used by Gateway mirror tests. */
final class LocalGitRefAgent implements WorkspaceAgentCommandRunner
{
    /** @var list<array{command: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $refs = null;

    public function runInWorkspace(Node $node, Closure $execute, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        if ($command !== 'task-vms.read-git-refs') {
            throw new LogicException('Unexpected workspace command: '.$command);
        }
        $this->calls[] = ['command' => $command, 'input' => $input];
        if ($this->refs !== null) {
            return $this->refs;
        }
        $checkout = $input['checkout'];
        $git = static function (array $arguments) use ($checkout): ?string {
            $process = new Process(['git', '-C', $checkout, ...$arguments]);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        };

        return [
            'haves' => array_values(array_filter(explode("\n", $git(['for-each-ref', '--format=%(objectname)']) ?? ''))),
            'baseline' => $git(['rev-parse', '--verify', '--quiet', 'refs/worktree/orbit/ci-baseline']),
            'branch' => $git(['symbolic-ref', '--quiet', '--short', 'HEAD']),
            'head' => $git(['rev-parse', '--verify', '--quiet', 'HEAD']),
        ];
    }
}
