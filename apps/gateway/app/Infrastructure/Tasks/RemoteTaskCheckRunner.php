<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppDev\SsrEndpoint;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAnalysisRunner;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskWorkspaceSnapshot;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use JsonException;
use Throwable;

/**
 * Installs `.git/orbit/check` and drives it over SSH. Each call returns at once; the check itself runs detached.
 */
final readonly class RemoteTaskCheckRunner implements TaskAnalysisRunner, TaskCheckRunner
{
    /** Eleven 100 KiB records fit below 8 MiB even with sixfold JSON escaping; PHP redacts the raw windows before storing tails. */
    public const int OutputLimitBytes = 8 * 1024 * 1024;

    public function __construct(
        private TaskWorkspaceExecutor $ssh,
        private VpToolManager $vp,
        private GatewaySetupSteps $gatewaySteps,
        private RepositoryPullRequestAccess $github,
    ) {}

    public function start(Instance $instance, ?string $command, array $setup = [], ?array $deliverables = null, ?string $runId = null): TaskCheckProcess
    {
        $dispatched = false;
        try {
            return $this->startPrepared($instance, $command, $setup, $deliverables, $dispatched, $runId);
        } catch (TaskCheckException $exception) {
            $exception->startNotDispatched = ! $dispatched;
            throw $exception;
        }
    }

    /**
     * @param  list<array{name: string, command: string, timeout_seconds: int}>  $setup
     * @param  array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>, topology?: bool}|null  $deliverables
     */
    private function startPrepared(Instance $instance, ?string $command, array $setup, ?array $deliverables, bool &$dispatched, ?string $runId): TaskCheckProcess
    {
        $instance->refresh();
        $script = TaskUnitProgram::read('tasks/check');
        foreach ($setup as &$step) {
            if (GatewaySetupSteps::owns($step['command'])) {
                try {
                    $instance->loadMissing('project');
                    $started = microtime(true);
                    $step['command'] = $this->gatewaySteps->command($instance->project, $step['command'], $step['timeout_seconds']);
                    $step['timeout_seconds'] = max(1, (int) floor($step['timeout_seconds'] - (microtime(true) - $started)));
                } catch (ResourceOperationException $exception) {
                    throw new TaskCheckException('Setup step ['.$step['name'].']: '.$exception->getMessage());
                }
            }
        }
        unset($step);
        $steps = json_encode($setup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $verify = $deliverables === null ? null : json_encode($deliverables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $install = TaskWorkspaceMetadata::operation('check', [
            'script' => base64_encode($script), 'composer' => base64_encode(file_get_contents(resource_path('tasks/composer')) ?: ''), 'setup' => $steps, 'command' => $command ?? '', 'deliverables' => $verify,
        ]);
        $stepsArgument = $setup === [] ? '-' : '"$dir/setup.json"';
        $deliverablesArgument = $deliverables === null ? '-' : '"$dir/deliverables.json"';
        $auth = null;
        $incoming = $instance->tasks()->get()->contains(static fn (Task $group): bool => $group->reviewsIncomingPullRequest());
        if ($setup !== [] && ! $incoming && TaskVm::forNode($instance->node_id) instanceof TaskVm) {
            $repository = GitHubRepository::fromOrigin($instance->project->repository_url);
            if ($repository instanceof GitHubRepository) {
                try {
                    $token = $this->github->metadataToken($repository);
                    $auth = json_encode(['github-oauth' => ['github.com' => $token], 'bearer' => ['codeload.github.com' => $token]], JSON_THROW_ON_ERROR);
                } catch (GitHubApiException) {
                    $auth = null;
                }
            }
        }
        $data = $this->run($instance, [], $install.'check_python "$dir/check" start "$checkout" '.$stepsArgument.' '.$deliverablesArgument.' "$dir/check-command" '.escapeshellarg($runId ?? ''), withVpHome: true, allocateTemporary: true, composerAuth: $auth, beforeDispatch: static function () use (&$dispatched): void {
            $dispatched = true;
        });
        $pid = $data['pid'] ?? null;
        $started = $data['started'] ?? null;
        $head = $data['head'] ?? null;
        $tree = $data['tree'] ?? null;
        if (! is_int($pid) || $pid < 1 || ! is_string($started) || $started === '' || ! is_string($head) || ! is_string($tree)) {
            throw new TaskCheckException('The check did not start.');
        }

        return new TaskCheckProcess($pid, $started, $head, $tree, $runId);
    }

    public function initialAnalysisBase(Instance $instance): ?string
    {
        $script = TaskUnitProgram::read('tasks/check');
        $result = $this->run($instance, [$instance->starting_commit ?? ''],
            TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]).
            'check_python "$dir/check" analysis-base "$checkout" "$2"',
            'The initial analysis base could not be read.', 'The initial analysis base answer is invalid.');
        $base = $result['base'] ?? null;

        return is_string($base) && preg_match('/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D', $base) === 1 ? $base : null;
    }

    public function inspectAnalysis(Instance $instance, string $base): array
    {
        $script = TaskUnitProgram::read('tasks/check');

        return $this->run($instance, [$base], TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]).
            'check_python "$dir/check" analysis-inspect "$checkout" "$2"');
    }

    /** @param array<string, mixed> $input */
    public function startAnalysis(Instance $instance, array $input, string $runId): TaskCheckProcess
    {
        $script = TaskUnitProgram::read('tasks/check');
        $data = $this->run($instance, [$runId, json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]).
            'check_python "$dir/check" analysis-start "$checkout" "$2" "$3"');
        if (! is_int($data['pid'] ?? null) || $data['pid'] < 1 || ! is_string($data['started'] ?? null)
            || ! is_string($data['head'] ?? null) || ! is_string($data['tree'] ?? null) || ($data['run_id'] ?? null) !== $runId) {
            throw new TaskCheckException('The fixed analysis start is not authoritatively known.');
        }

        return new TaskCheckProcess($data['pid'], $data['started'], $data['head'], $data['tree'], $runId);
    }

    /** @return array<string, mixed> */
    public function readAnalysis(Instance $instance, TaskCheckProcess $process): array
    {
        return $this->run($instance, [(string) $process->pid, $process->started, $process->runId ?? ''],
            'check_python "$dir/check" status "$2" "$3" "$4" "$checkout"');
    }

    public function recoverStart(Instance $instance, string $runId): ?TaskCheckProcess
    {
        $script = TaskUnitProgram::read('tasks/check');
        $data = $this->run($instance, [$runId], TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]).
            'check_python "$dir/check" recover-start "$2"');
        if (($data['run_id'] ?? null) !== $runId) {
            throw new TaskCheckException('The check start identity does not match.');
        }
        if (($data['state'] ?? null) === 'not_started') {
            return null;
        }
        if (! in_array($data['state'] ?? null, ['started', 'finished'], true) || ! is_int($data['pid'] ?? null) || $data['pid'] < 1
            || ! is_string($data['started'] ?? null) || $data['started'] === ''
            || ! is_string($data['head'] ?? null) || ! is_string($data['tree'] ?? null)) {
            throw new TaskCheckException('The check start is not authoritatively known.');
        }

        return new TaskCheckProcess($data['pid'], $data['started'], $data['head'], $data['tree'], $runId);
    }

    public function read(Instance $instance, TaskCheckProcess $process): TaskCheckReading
    {
        $data = $this->run($instance, [(string) $process->pid, $process->started, $process->runId ?? ''], $this->statusProtocol().'check_python "$dir/check" status "$2" "$3" "$4" "$checkout"');
        $output = is_string($data['output'] ?? null) ? $data['output'] : '';

        return match ($data['state'] ?? null) {
            'running' => TaskCheckReading::running(),
            'lost' => TaskCheckReading::lost($output),
            'interrupted' => TaskCheckReading::interrupted($output),
            'finished' => $this->finished($data['result'] ?? null, $output),
            default => throw new TaskCheckException('The check state could not be read.'),
        };
    }

    public function cancel(Instance $instance, TaskCheckProcess $process): void
    {
        $this->run($instance, [(string) $process->pid, $process->started, $process->runId ?? ''], $this->statusProtocol().'check_python "$dir/check" cancel "$2" "$3" "$4"');
    }

    private function statusProtocol(): string
    {
        $script = TaskUnitProgram::read('tasks/check');

        return TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]);
    }

    public function snapshot(Instance $instance): TaskWorkspaceSnapshot
    {
        $script = TaskUnitProgram::read('tasks/check');
        $data = $this->run($instance, [], TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode($script)]).
            'check_python "$dir/check" snapshot "$checkout"', 'The task workspace could not be reached for the workspace tree.', 'The workspace tree could not be read.');
        $head = $data['head'] ?? null;
        $tree = $data['tree'] ?? null;
        if (! is_string($head) || $head === '' || ! is_string($tree) || $tree === '') {
            throw new TaskCheckException('The workspace tree could not be read.');
        }
        $parent = $data['parent'] ?? null;
        $commitTree = $data['commit_tree'] ?? null;
        $parentTree = $data['parent_tree'] ?? null;
        $secondParent = $data['second_parent'] ?? null;

        return new TaskWorkspaceSnapshot(
            $head,
            $tree,
            is_string($parent) && $parent !== '' ? $parent : null,
            is_string($commitTree) && $commitTree !== '' ? $commitTree : null,
            is_string($parentTree) && $parentTree !== '' ? $parentTree : null,
            is_string($secondParent) && $secondParent !== '' ? $secondParent : null,
        );
    }

    private function finished(mixed $result, string $output): TaskCheckReading
    {
        if (! is_array($result) || ! is_int($result['exit_code'] ?? null) || ! is_string($result['head_after'] ?? null)
            || ! is_string($result['tree_after'] ?? null) || ! is_array($result['changed_paths'] ?? null)) {
            throw new TaskCheckException('The check result could not be read.');
        }
        $paths = array_values(array_filter($result['changed_paths'], is_string(...)));

        $finishedAt = $result['finished_at'] ?? null;
        $treeBefore = $result['tree_before'] ?? null;
        $failedStep = $result['failed_step'] ?? null;
        $evidence = $result['deliverables'] ?? null;

        return TaskCheckReading::finished(
            $result['exit_code'],
            $result['head_after'],
            $result['tree_after'],
            $paths,
            $output,
            is_int($finishedAt) || is_float($finishedAt) ? (float) $finishedAt : null,
            is_string($treeBefore) ? $treeBefore : null,
            is_string($failedStep) ? $failedStep : null,
            is_array($evidence) ? $evidence : null,
            causalityUncertain: ($result['causality_uncertain'] ?? false) === true,
        );
    }

    /**
     * @param  list<string>  $arguments
     * @param  (\Closure(): void)|null  $beforeDispatch
     * @return array<string, mixed>
     */
    private function run(
        Instance $instance,
        array $arguments,
        string $command,
        string $unreachable = 'The task workspace could not be reached for the check.',
        string $invalid = 'The check answered with invalid output.',
        bool $withVpHome = false,
        bool $allocateTemporary = false,
        #[\SensitiveParameter] ?string $composerAuth = null,
        ?\Closure $beforeDispatch = null,
    ): array {
        $instance->loadMissing('node');
        if ($instance->checkout_path === '') {
            throw new TaskCheckException('The task workspace has no checkout.');
        }
        $environment = '';
        if ($composerAuth !== null) {
            $environment .= 'orbit_setup_composer_auth='.escapeshellarg($composerAuth)."\nexport -n orbit_setup_composer_auth\n";
        }
        if ($withVpHome && TaskVm::forNode($instance->node_id) instanceof TaskVm) {
            $environment .= 'export COMPOSER_CACHE_DIR="$HOME/.cache/composer"'."\n";
        }
        if ($withVpHome) {
            try {
                $environment .= 'export VP_HOME='.escapeshellarg(dirname($this->vp->existingBinary($instance->node), 2))."\n";
            } catch (Throwable $exception) {
                // Only the read-only probe ran: no check can have started, so its reservation may be released.
                throw new TaskCheckException($unreachable, previous: $exception);
            }
        }
        // Setup, the check, and deliverable commands inherit the workspace's own Inertia SSR port.
        foreach (is_int($instance->ssr_port) ? SsrEndpoint::environment($instance->ssr_port) : [] as $name => $value) {
            $environment .= "export {$name}=".escapeshellarg($value)."\n";
        }
        try {
            // The check runs as the managed user, so host-dependent tests keep its sudo, ACL and caddy access.
            // Read the seed now: converting an old default release layout moves it to the checkout, and
            // removes the releases only while no baseline that started earlier still runs.
            $seed = Instance::query()->whereKey($instance->id)->first(['seed_path', 'seed_commit']);
            $prefix = $environment."checkout=\$1\nseed_path=".escapeshellarg($seed->seed_path ?? '')."\nseed_commit=".escapeshellarg($seed->seed_commit ?? '')."\n".<<<'BASH'
                dir="$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --absolute-git-dir)/orbit"
                check_python() {
                    ORBIT_SEED_PATH="$seed_path" ORBIT_SEED_COMMIT="$seed_commit" python3 "$@" 3<<<"${orbit_setup_composer_auth:-}"
                }

                BASH;
            $input = $prefix.TaskWorkspaceMetadata::bashPreamble().
                ($allocateTemporary
                    ? 'TMPDIR="$('.TaskWorkspaceMetadata::operation('tmpdir').')"'."\nexport TMPDIR\n"
                    : '').
                $command."\n";
            $beforeDispatch?->__invoke();
            $result = $this->ssh->execute($instance, new RemoteCommand(
                arguments: ['bash', '-seu', '--', $instance->checkout_path, ...$arguments],
                input: $composerAuth === null ? $input : null,
                protectedInput: $composerAuth === null ? null : ProtectedInput::fromString($input),
                maxOutputBytes: self::OutputLimitBytes,
            ), 'task-check', 'tasks.check_failed');
        } catch (RuntimeConvergenceException $exception) {
            throw new TaskCheckException($unreachable, previous: $exception);
        }
        try {
            $data = json_decode(trim($result->stdout), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TaskCheckException($invalid, previous: $exception);
        }
        if (! is_array($data)) {
            throw new TaskCheckException($invalid);
        }

        try {
            return ValidatedData::object($data);
        } catch (\InvalidArgumentException $exception) {
            throw new TaskCheckException($invalid, previous: $exception);
        }
    }
}
