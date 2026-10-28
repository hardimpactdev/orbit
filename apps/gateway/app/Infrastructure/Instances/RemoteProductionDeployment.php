<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;

/** Read-only release and selection queries remain in the Gateway. */
final readonly class RemoteProductionDeployment implements ProductionDeployment
{
    public function __construct(private AgentCommandRunner $agents, private ?ProductionRepositoryBinding $repositories = null) {}

    public function selected(Instance $instance): ?DeploymentRelease
    {
        [$input, $home] = $this->releaseHome($instance);
        $release = $this->runBound($instance, 'instance.inspect-release', $input, 'deployment-selected-release', 'deployment.selection_invalid')['release'] ?? null;

        return $release === null ? null : $this->releaseFrom($release, $home);
    }

    public function retained(Instance $instance, string $name): DeploymentRelease
    {
        $this->assertReleaseName($name);
        [$input, $home] = $this->releaseHome($instance);
        $release = $this->runBound($instance, 'instance.inspect-release', [...$input, 'name' => $name], 'rollback-retained-release', 'rollback.release_invalid')['release'] ?? null;

        return $this->releaseFrom($release, $home);
    }

    public function releases(Instance $instance): DeploymentReleaseState
    {
        [$input] = $this->releaseHome($instance);
        $result = $this->runBound($instance, 'instance.list-releases', $input, 'deployment-release-list', 'deployment.release_inspection_failed');
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
