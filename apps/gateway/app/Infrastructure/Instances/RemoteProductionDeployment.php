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
use App\Domain\Logs\LogRedactor;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;
use Closure;

final readonly class RemoteProductionDeployment implements ProductionDeployment
{
    /** @var Closure(): string */
    private Closure $releaseName;

    /** @param (Closure(): string)|null $releaseName */
    public function __construct(
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
            if (AgentStep::refusal($exception)?->errorCode === 'instances.commit_not_on_branch') {
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
        $instance->loadMissing('environmentValues');
        $stepName = "deployment-step-{$step->name}";
        // The Node publishes each line live on the operation's channel; the result keeps the output for the record.
        $result = AgentStep::run($this->agents, $instance->node, 'instances.run-deploy-step', [
            'user' => $user,
            'home' => $home,
            'release' => $release->name,
            'script' => $this->environments->command($instance, $release->path, $step->name, $step->command),
            'operation' => $request->operation,
            'step' => $step->name,
            'secrets' => app(LogRedactor::class)->valuesFor($instance),
        ], $stepName, 'deployment.step_failed', 'App production', deadlineSeconds: $step->timeoutSeconds);
        $streams = ['stdout' => '', 'stderr' => ''];

        foreach (is_array($result['output'] ?? null) ? $result['output'] : [] as $chunk) {
            $stream = is_array($chunk) ? ($chunk['stream'] ?? null) : null;
            $value = is_array($chunk) ? ($chunk['value'] ?? null) : null;
            if (! is_string($value) || ! in_array($stream, ['stdout', 'stderr'], true)) {
                throw $this->invalidReceipt();
            }
            $streams[$stream] .= $value;
            $request->emit(new DeploymentEvent(
                step: $step->name,
                stream: $stream === 'stdout' ? DeploymentOutputStream::Stdout : DeploymentOutputStream::Stderr,
                value: $value,
            ));
        }

        $exitCode = is_int($result['exit_code'] ?? null) ? $result['exit_code'] : throw $this->invalidReceipt();
        $output = new CommandResult($exitCode, $streams['stdout'], $streams['stderr'], 0, ($result['truncated'] ?? false) === true);

        if ($exitCode !== 0) {
            throw AgentStep::failed($instance->node, $stepName, 'deployment.step_failed', 'App production', $output);
        }

        return $output;
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input, string $step, string $errorCode): array
    {
        return AgentStep::run($this->agents, $instance->node, $command, $input, $step, $errorCode, 'App production');
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
