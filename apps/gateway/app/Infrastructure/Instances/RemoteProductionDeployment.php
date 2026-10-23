<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentOutputStream;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\Deployment\SharedPaths;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use Closure;

final readonly class RemoteProductionDeployment implements ProductionDeployment
{
    /** @var Closure(): string */
    private Closure $releaseName;

    /** @param (Closure(): string)|null $releaseName */
    public function __construct(
        private ProductionSshExecutor $ssh,
        private AgentCommandRunner $agents,
        private RepositoryReadAccess $access,
        private PrepareVpEnvironmentAction $environments,
        ?Closure $releaseName = null,
        private ?ProductionRepositoryBinding $repositories = null,
    ) {
        $this->releaseName = $releaseName ?? static fn (): string => gmdate('YmdHis').'-'.bin2hex(random_bytes(8));
    }

    public function prepare(Instance $instance, string $branch, ?string $commit = null): DeploymentRelease
    {
        if ($commit !== null) {
            GitCommitSha::validate($commit);
        }
        [$input, $home] = $this->releaseHome($instance);
        $name = ($this->releaseName)();
        $this->assertReleaseName($name);

        try {
            $result = $this->run($instance, 'instances.prepare-release', [
                ...$input,
                'name' => $name,
                'branch' => $branch,
                'commit' => $commit,
                'shared_paths' => SharedPaths::forInstance($instance),
                'git_read' => $this->access->for($input['repository'], $instance->project->source_access),
            ], 'deployment-prepare', 'deployment.prepare_failed');
        } catch (RuntimeConvergenceException $exception) {
            if ($exception->getPrevious() instanceof AgentCommandRefused && $exception->getPrevious()->errorCode === 'instances.commit_not_on_branch') {
                throw new ResourceOperationException(
                    'deployment.commit_not_on_branch',
                    "The branch [{$branch}] does not contain the commit [{$commit}].",
                    409,
                    $exception,
                );
            }

            throw $this->bindingFailure($instance, $exception) ?? $exception;
        }

        [$reportedName, $reportedCommit] = $this->releaseIdentity($result);

        if ($reportedName !== $name || ($commit !== null && $reportedCommit !== $commit)) {
            throw $this->invalidReceipt();
        }

        return new DeploymentRelease($name, "{$home}/releases/{$name}", $reportedCommit);
    }

    public function executeStep(
        Instance $instance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult {
        [, $user, $home] = $this->identity($instance);
        $this->assertRelease($release, $home);
        $stepName = $step->name;
        $command = $this->environments->command($instance, $release->path, $stepName, $step->command);
        $protectedInput = ProtectedInput::fromString(str_replace(
            '__COMMAND__',
            base64_encode($command),
            <<<'BASH'
                user=$1
                home=$2
                release=$3
                test "$home" = "/home/$user"
                releases="$home/releases"
                case "$release" in "$releases"/*) ;; *) exit 1 ;; esac
                sudo -u "$user" -H test -d "$release"
                sudo -u "$user" -H test ! -L "$release"
                test "$(sudo -u "$user" -H realpath -e -- "$release")" = "$release"
                command_file=$(sudo -u "$user" -H mktemp "$home/.orbit-deploy.XXXXXXXX")
                supervisor=
                process_group=
                watchdog=
                expected_uid=$(id -u "$user")
                discover_process_group() {
                    for attempt in $(seq 1 100); do
                        candidate=$(pgrep -P "$supervisor" 2>/dev/null || true)
                        case "$candidate" in ''|*[!0-9]*) ;; *)
                            identity=$(ps -o ppid=,pgid=,uid= -p "$candidate" 2>/dev/null || true)
                            set -- $identity
                            if [ "$#" -eq 3 ] && [ "$1" = "$supervisor" ] && [ "$2" = "$candidate" ] && [ "$3" = "$expected_uid" ]; then
                                printf '%s' "$candidate"
                                return 0
                            fi
                        esac
                        if ! kill -0 "$supervisor" 2>/dev/null; then return 1; fi
                        sleep 0.01
                    done
                    return 1
                }
                terminate_step() {
                    if [ -z "$process_group" ]; then return; fi
                    sudo -u "$user" -H kill -TERM -- "-$process_group" 2>/dev/null || true
                    sleep 0.1
                    sudo -u "$user" -H kill -KILL -- "-$process_group" 2>/dev/null || true
                }
                cleanup() {
                    status=$?
                    terminate_step
                    if [ -n "$supervisor" ]; then
                        wait "$supervisor" 2>/dev/null || true
                    fi
                    if [ -n "$watchdog" ]; then
                        wait "$watchdog" 2>/dev/null || true
                    fi
                    sudo -u "$user" -H rm -f -- "$command_file"
                    exit "$status"
                }
                trap cleanup EXIT HUP INT TERM
                printf '%s' '__COMMAND__' | base64 --decode | sudo -u "$user" -H tee "$command_file" >/dev/null
                sudo -u "$user" -H chmod 0600 -- "$command_file"
                owner=$PPID
                sudo -u "$user" -H setsid --wait bash -eu -c 'umask 077; cd -- "$1"; exec bash -eu "$2"' bash "$release" "$command_file" &
                supervisor=$!
                (
                    watched_group=$(discover_process_group) || exit 0
                    while kill -0 "$owner" 2>/dev/null && kill -0 "$supervisor" 2>/dev/null; do sleep 0.1; done
                    if kill -0 "$owner" 2>/dev/null; then exit 0; fi
                    sudo -u "$user" -H kill -TERM -- "-$watched_group" 2>/dev/null || true
                    sleep 0.1
                    sudo -u "$user" -H kill -KILL -- "-$watched_group" 2>/dev/null || true
                ) &
                watchdog=$!
                process_group=$(discover_process_group) || true
                if wait "$supervisor"; then status=0; else status=$?; fi
                supervisor=
                process_group=
                wait "$watchdog" 2>/dev/null || true
                watchdog=
                exit "$status"
                BASH,
        ));

        return $this->execute(
            $instance,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $user, $home, $release->path],
                protectedInput: $protectedInput,
                output: static function (ProcessOutput $output) use ($request, $stepName): void {
                    $request->emit(new DeploymentEvent(
                        step: $stepName,
                        stream: $output->stream === ProcessOutputStream::Stdout
                            ? DeploymentOutputStream::Stdout
                            : DeploymentOutputStream::Stderr,
                        value: $output->value,
                    ));
                },
                timeout: $step->timeoutSeconds,
            ),
            "deployment-step-{$stepName}",
            'deployment.step_failed',
        );
    }

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease
    {
        [$input, $home] = $this->releaseHome($instance);
        $this->assertRelease($release, $home);
        $result = $this->runBound($instance, 'instances.activate-release', [...$input, 'name' => $release->name, 'commit' => $release->commit], 'deployment-activate', 'deployment.activation_failed');
        [$name, $commit] = $this->releaseIdentity($result);

        if ($name !== $release->name || $commit !== $release->commit) {
            throw $this->invalidReceipt();
        }

        return $release;
    }

    public function selected(Instance $instance): ?DeploymentRelease
    {
        [$input, $home] = $this->releaseHome($instance);
        $release = $this->runBound($instance, 'instances.inspect-release', $input, 'deployment-selected-release', 'deployment.selection_invalid')['release'] ?? null;

        return $release === null ? null : $this->releaseFrom($release, $home);
    }

    public function retained(Instance $instance, string $name): DeploymentRelease
    {
        $this->assertReleaseName($name);
        [$input, $home] = $this->releaseHome($instance);
        $release = $this->runBound($instance, 'instances.inspect-release', [...$input, 'name' => $name], 'rollback-retained-release', 'rollback.release_invalid')['release'] ?? null;

        return $this->releaseFrom($release, $home);
    }

    public function releases(Instance $instance): DeploymentReleaseState
    {
        [$input] = $this->releaseHome($instance);
        $result = $this->runBound($instance, 'instances.list-releases', $input, 'deployment-release-list', 'deployment.release_inspection_failed');
        $selected = $result['selected'] ?? null;
        $listed = $result['releases'] ?? null;
        if (! is_array($listed)) {
            throw $this->invalidReceipt();
        }
        $releases = [];

        foreach ($listed as $release) {
            [$name] = $this->releaseIdentity(is_array($release) ? $release : []);
            $releases[] = $name;
        }

        if (count($releases) !== count(array_unique($releases)) || ($selected !== null && (! is_string($selected) || ! in_array($selected, $releases, strict: true)))) {
            throw $this->invalidReceipt();
        }

        sort($releases);

        return new DeploymentReleaseState($releases, $selected);
    }

    public function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous): array
    {
        [$input, $home] = $this->releaseHome($instance);
        $this->assertRelease($selected, $home);

        if ($previous !== null) {
            $this->assertRelease($previous, $home);
        }

        $result = $this->run($instance, 'instances.prune-releases', [
            ...$input,
            'limit' => DeploymentRelease::RETAINED_PER_HOME,
            'selected' => $selected->name,
            'previous' => $previous?->name,
        ], 'deployment-prune', 'deployment.prune_failed');
        $outcome = ['removed' => [], 'in_use' => []];

        foreach (['removed', 'in_use'] as $key) {
            foreach (is_array($result[$key] ?? null) ? $result[$key] : [] as $name) {
                if (! is_string($name)) {
                    throw $this->invalidReceipt();
                }
                $this->assertReleaseName($name);
                $outcome[$key][] = $name;
            }
        }

        $this->environments->releaseRemoved($instance, array_map(
            static fn (string $name): string => "{$home}/releases/{$name}",
            $outcome['removed'],
        ));

        return $outcome;
    }

    /**
     * The home every release command checks, and its path.
     *
     * @return array{array{repository: string, user: string, home: string, instance: int, relative_root: string, application_suffix: string, served: list<array{web_root: string, directory: string}>}, string}
     */
    private function releaseHome(Instance $instance): array
    {
        [$repository, $user, $home, $root] = $this->identity($instance);
        $served = array_map(
            static fn (array $application): array => ['web_root' => $application['web_root'], 'directory' => $application['directory']],
            RouteWebRoot::servedApplications($instance),
        );

        return [[
            'repository' => $repository,
            'user' => $user,
            'home' => $home,
            'instance' => $instance->id,
            'relative_root' => $root,
            'application_suffix' => ProductionApplicationPaths::suffix($root),
            'served' => $served,
        ], $home];
    }

    /** @return array{string, string, string, string} */
    private function identity(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);
        $repository = $instance->project->repository_url;
        $user = $instance->production_user;
        $home = $instance->production_home;
        $root = $instance->root ?? $instance->project->root;

        if (
            ! $instance->placedOnAppProd()
            || $instance->status !== InstanceState::Active
            || $instance->provisioning_step !== 'active'
            || ! $instance->usesProductionReleaseLayout()
            || ! is_string($user)
            || ! is_string($home)
            || ! is_string($root)
        ) {
            throw new ResourceOperationException(
                'deployment.identity_invalid',
                'The production deployment identity is invalid.',
                409,
            );
        }

        return [$repository, $user, $home, $root];
    }

    private function execute(
        Instance $instance,
        RemoteCommand $command,
        string $step,
        string $errorCode,
    ): CommandResult {
        return $this->ssh->execute($instance->node, $command, $step, $errorCode);
    }

    /**
     * Runs a release command. A refusal, and a run without a result, fail the step with its error code; a timeout
     * keeps its own failure, because it never proves that nothing changed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input, string $step, string $errorCode): array
    {
        try {
            return $this->agents->run($instance->node, $command, $input);
        } catch (AgentCommandRefused $refused) {
            $result = new CommandResult(1, json_encode(['code' => $refused->errorCode, 'details' => (object) $refused->details], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n", $refused->getMessage(), 0, false);

            throw new RuntimeConvergenceException($step, $errorCode, "App production step [{$step}] failed on node [{$instance->node->name}].", $refused, $result);
        } catch (AgentRunnerFailure $failure) {
            if ($failure->kind === AgentRunnerFailure::Timeout) {
                throw $failure;
            }

            throw new RuntimeConvergenceException($step, $errorCode, "App production step [{$step}] failed on node [{$instance->node->name}].", $failure, $failure->result);
        }
    }

    /**
     * Runs a command that checks the repository binding. When it fails because the home records another URL, the
     * error names the repair instead of the failed check.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function runBound(Instance $instance, string $command, array $input, string $step, string $errorCode): array
    {
        try {
            return $this->run($instance, $command, $input, $step, $errorCode);
        } catch (RuntimeConvergenceException $exception) {
            throw $this->bindingFailure($instance, $exception) ?? $exception;
        }
    }

    private function bindingFailure(Instance $instance, RuntimeConvergenceException $exception): ?ResourceOperationException
    {
        $repository = $instance->project->repository_url;

        try {
            $record = ($this->repositories ?? new RemoteProductionRepositoryBinding($this->agents))->inspect($instance);
        } catch (\Throwable) {
            return null;
        }

        if ($record->boundTo($repository)) {
            return null;
        }

        if (! $record->sameRepositoryAs($repository)) {
            return new ResourceOperationException(
                'deployment.repository_mismatch',
                "Production Instance [{$instance->id}] serves another repository than its Project.",
                409,
                $exception,
            );
        }

        return new ResourceOperationException(
            'deployment.repository_rebind_required',
            "Production Instance [{$instance->id}] records another URL for its repository. Run `orbit project:update {$instance->project_id} --repository=<repository URL>` with the Project's URL to re-bind it.",
            409,
            $exception,
        );
    }

    /**
     * @param  array<array-key, mixed>  $release
     * @return array{string, string}
     */
    private function releaseIdentity(array $release): array
    {
        $name = $release['name'] ?? null;
        $commit = $release['commit'] ?? null;

        if (! is_string($name) || ! is_string($commit) || preg_match('/\A[0-9a-f]{40,64}\z/', $commit) !== 1) {
            throw $this->invalidReceipt();
        }

        $this->assertReleaseName($name);

        return [$name, $commit];
    }

    private function releaseFrom(mixed $release, string $home): DeploymentRelease
    {
        [$name, $commit] = $this->releaseIdentity(is_array($release) ? $release : []);

        return new DeploymentRelease($name, "{$home}/releases/{$name}", $commit);
    }

    private function assertRelease(DeploymentRelease $release, string $home): void
    {
        $this->assertReleaseName($release->name);

        if ($release->path !== "{$home}/releases/{$release->name}") {
            throw new ResourceOperationException(
                'deployment.release_invalid',
                'The deployment release is invalid.',
                409,
            );
        }
    }

    private function assertReleaseName(string $name): void
    {
        if (! DeploymentRelease::isValidName($name)) {
            throw new ResourceOperationException(
                'rollback.release_invalid',
                'The retained release name is invalid.',
                422,
            );
        }
    }

    private function invalidReceipt(): ResourceOperationException
    {
        return new ResourceOperationException(
            'deployment.receipt_invalid',
            'The production deployment returned an invalid receipt.',
            409,
        );
    }
}
