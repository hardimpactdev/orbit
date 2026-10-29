<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\ComposerSourceClassifier;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Projects\ProjectType;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;

/**
 * A production Instance's home before and around its releases, through the Node agent's `instance.*` commands
 * (docs/reference/node-agent.md): the system account, the initial clone, its branch and Composer profile, Caddy's
 * access, the check of `current`, and removal.
 */
final readonly class RemoteProductionInstanceSourceLifecycle implements ProductionInstanceSourceLifecycle, ProductionReleaseLayout
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ComposerSourceClassifier $classifier,
        private RepositoryReadAccess $access,
    ) {}

    public function prepareUser(Instance $instance): void
    {
        $instance->loadMissing('node');
        [$user, $home] = $this->identity($instance);

        $this->run($instance, 'instance.prepare-production-user', ['user' => $user, 'home' => $home], 'production-user', 'app-prod.user_conflict');
    }

    public function prepareSource(Instance $instance, bool $allowExisting): void
    {
        $instance->loadMissing(['project', 'node']);

        $this->run($instance, 'instance.prepare-production-source', [
            ...$this->home($instance),
            'allow_existing' => $allowExisting,
            'git_read' => $this->gitRead($instance),
        ], 'production-source-prepare', 'instance.clone_failed');
    }

    public function resolve(Instance $instance): DevelopmentSourceResolution
    {
        $instance->loadMissing(['project', 'node']);
        $branch = $instance->branch_override ?? $instance->branch ?? $instance->project->default_branch;

        if (! is_string($branch)) {
            throw $this->failure('production-source-resolve', 'instance.branch_resolution_failed');
        }

        $result = $this->run($instance, 'instance.resolve-production-source', [
            ...$this->releaseHome($instance),
            'branch' => $branch,
            'clone_target' => $instance->clone_candidate_id !== null,
            'git_read' => $this->gitRead($instance),
        ], 'production-source-resolve', 'instance.branch_resolution_failed');

        if (! is_string($result['branch'] ?? null) || ! is_string($result['commit'] ?? null)) {
            throw $this->failure('production-source-resolve', 'instance.source_identity_invalid');
        }

        return new DevelopmentSourceResolution($result['branch'], $result['commit']);
    }

    public function inspectProfile(Instance $instance): DevelopmentSourceProfile
    {
        $instance->loadMissing(['project', 'node']);
        [$user, $home] = $this->identity($instance);
        $root = $instance->root ?? $instance->project->root;
        $checkout = $instance->checkout_path;

        if (! is_string($root) || ! $this->withinRecordedHome($home, $checkout)) {
            throw $this->failure('production-source-classification', 'app-prod.source_metadata_unsafe');
        }

        $result = $this->run($instance, 'instance.inspect-production-profile', [
            'user' => $user,
            'relative_root' => $root,
            'checkout' => $checkout,
            'application' => ApplicationDirectory::resolve($checkout, $root),
            'entry_point' => $instance->project->type->frameworkEntryPoint(),
        ], 'production-source-classification', 'app-prod.source_classification_failed');

        return $this->profile($result, $instance->project->type);
    }

    public function prepareCaddyAccess(Instance $instance): void
    {
        $instance->loadMissing(['project', 'node']);
        [$user, $home] = $this->identity($instance);
        $root = $instance->root ?? $instance->project->root;

        if (! is_string($root)) {
            throw $this->failure('production-caddy-access', 'app-prod.source_metadata_unsafe');
        }

        $this->run($instance, 'instance.grant-production-caddy-access', [
            'user' => $user,
            'home' => $home,
            'relative_root' => $root,
            'clone_target' => $instance->clone_candidate_id !== null,
        ], 'production-caddy-access', 'app-prod.source_metadata_unsafe');
    }

    public function validateCurrent(Instance $instance): void
    {
        if ($instance->usesProductionReleaseLayout()) {
            $instance->loadMissing(['project', 'node']);
            $this->run($instance, 'instance.validate-release-layout', $this->releaseHome($instance), 'production-release-layout', 'app-prod.source_metadata_unsafe');
        }
    }

    public function clearCurrent(Instance $instance): void
    {
        if ($instance->usesProductionReleaseLayout()) {
            $instance->loadMissing(['project', 'node']);
            $this->run($instance, 'instance.clear-current-release', $this->releaseHome($instance), 'production-release-layout', 'app-prod.source_metadata_unsafe');
        }
    }

    public function remove(Instance $instance): void
    {
        $instance->loadMissing(['project', 'node']);
        [$user, $home] = $this->identity($instance);
        if ($user !== "orbit-app-{$instance->project_id}" || $home !== "/home/{$user}") {
            throw $this->failure('production-source-cleanup', 'app-prod.identity_missing');
        }

        $this->run($instance, 'instance.remove-production-home', $this->home($instance), 'production-source-cleanup', 'app-prod.source_cleanup_failed');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Instance $instance, string $command, array $input, string $step, string $errorCode): array
    {
        return AgentStep::run($this->agents, $instance->node, $command, $input, $step, $errorCode, 'App production');
    }

    /** @return array{repository: string, user: string, home: string, instance: int} */
    private function home(Instance $instance): array
    {
        [$user, $home] = $this->identity($instance);

        return ['repository' => $instance->project->repository_url, 'user' => $user, 'home' => $home, 'instance' => $instance->id];
    }

    /** @return array<string, mixed> */
    private function releaseHome(Instance $instance): array
    {
        $root = $instance->root ?? $instance->project->root;

        if (! is_string($root)) {
            throw $this->failure('production-release-layout', 'app-prod.source_metadata_unsafe');
        }

        return [...$this->home($instance), 'relative_root' => $root, 'application_suffix' => ProductionApplicationPaths::suffix($root)];
    }

    private function gitRead(Instance $instance): GitReadEnvironment
    {
        return $this->access->for($instance->project->repository_url, $instance->project->source_access);
    }

    /** @return array{string, string} */
    private function identity(Instance $instance): array
    {
        if (! is_string($instance->production_user) || ! is_string($instance->production_home)) {
            throw $this->failure('production-identity', 'app-prod.identity_missing');
        }

        return [$instance->production_user, $instance->production_home];
    }

    private function withinRecordedHome(string $home, string $checkout): bool
    {
        return $checkout === $home
            || preg_match('#\A'.preg_quote("{$home}/releases/", '#').'(?!\.\.?\z)[^/]+\z#D', $checkout) === 1;
    }

    /** @param  array<string, mixed>  $result */
    private function profile(array $result, ProjectType $projectType): DevelopmentSourceProfile
    {
        $kind = $result['kind'] ?? null;

        if ($kind === 'none') {
            return new DevelopmentSourceProfile(null, false);
        }

        if ($kind !== 'composer' || ! is_string($result['entry_point'] ?? null)) {
            throw $this->failure('production-source-classification', 'app-prod.source_metadata_unsafe');
        }

        $json = is_string($result['composer'] ?? null) ? base64_decode($result['composer'], true) : false;

        if (! is_string($json)) {
            throw $this->failure('production-source-classification', 'app-prod.php_version_unsupported');
        }

        try {
            return $this->classifier->classify($json, $projectType, $result['entry_point']);
        } catch (RuntimeConvergenceException $exception) {
            throw $this->failure('production-source-classification', str_replace('app-dev.', 'app-prod.', $exception->errorCode), $exception);
        }
    }

    private function failure(string $step, string $errorCode, ?\Throwable $previous = null): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: $step,
            errorCode: $errorCode,
            message: 'Production Instance source preparation failed.',
            previous: $previous,
        );
    }
}
