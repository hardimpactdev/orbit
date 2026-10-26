<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\WorkspaceAgentCommandRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use Closure;
use LogicException;
use Symfony\Component\Process\Process;

/** Supplies agent output from local repository fixtures for Gateway mirror and quarantine tests. */
final class LocalTaskGitAgent implements WorkspaceAgentCommandRunner
{
    /** @var list<array{command: string, input: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $refs = null;

    public ?string $pack = null;

    /** Answers may break their output schema, to test the Gateway's own guard against a malformed result. */
    public bool $malformed = false;

    public ?int $reportedBytes = null;

    /** @var (Closure(array<string, mixed>): void)|null */
    public ?Closure $beforeImport = null;

    public function runInWorkspace(Node $node, Closure $execute, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        FakeAgentCommandRunner::admit($node, $command, $input, $output, $binaryInput);
        $result = $this->respond($node, $execute, $command, $input, $deadlineSeconds, $output, $binaryInput);
        if (! $this->malformed) {
            AgentSchemas::assertOutput($command, $result);
        }

        return $result;
    }

    private function respond(Node $node, Closure $execute, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        $this->calls[] = ['command' => $command, 'input' => $input];
        if ($command === 'task-vms.import-git-pack') {
            if ($this->beforeImport !== null) {
                ($this->beforeImport)($input);
            }
            if ($binaryInput === null) {
                throw new LogicException('Import requires a binary source.');
            }
            // Materialize the fake's reply for later Gateway mirror/publication fixture steps.
            $git = ['git', '-C', $input['checkout']];
            $execute(new RemoteCommand([...$git, 'index-pack', '--stdin', '--fix-thin'], protectedInput: $binaryInput->envelope('')));
            foreach ($input['updates'] as $update) {
                new Process([...$git, 'update-ref', ...($update['commit'] === null ? ['-d', $update['reference']] : [$update['reference'], $update['commit']])])->mustRun();
            }
            if ($input['default_branch'] !== null) {
                $ref = 'refs/remotes/origin/'.$input['default_branch'];
                if (new Process([...$git, 'rev-parse', '--verify', '--quiet', $ref])->run() === 0) {
                    new Process([...$git, 'symbolic-ref', 'refs/remotes/origin/HEAD', $ref])->mustRun();
                }
            }

            return ['imported' => true];
        }
        if ($command === 'task-vms.export-git-pack') {
            if ($output === null) {
                throw new LogicException('Export requires a binary sink.');
            }
            $pack = $this->pack;
            if ($pack === null) {
                $revisions = $input['commit']."\n";
                foreach ($input['haves'] as $have) {
                    $probe = new Process(['git', '-C', $input['checkout'], 'cat-file', '-e', $have.'^{commit}']);
                    if ($probe->run() === 0) {
                        $revisions .= '^'.$have."\n";
                    }
                }
                $pack = new Process(['git', '-C', $input['checkout'], 'pack-objects', '--revs', '--thin', '--stdout', '--delta-base-offset', '--quiet'], input: $revisions)->mustRun()->getOutput();
            }
            if (strlen($pack) > $output->maxBytes) {
                throw new AgentRunnerFailure($command, AgentRunnerFailure::OutputLimit, 'The pack exceeds its limit.');
            }
            if (! $output->write($pack)) {
                throw new LogicException('Test sink could not be written.');
            }

            return ['bytes' => $this->reportedBytes ?? strlen($pack)];
        }
        if ($command !== 'task-vms.read-git-refs') {
            throw new LogicException('Unexpected workspace command: '.$command);
        }
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
