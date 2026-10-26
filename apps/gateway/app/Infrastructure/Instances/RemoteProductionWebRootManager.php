<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Models\Instance;
use App\Models\Route;

/**
 * The web roots that a production Instance's Routes serve, in its selected release, through the Node agent's
 * `instance.prepare-served-web-roots` and `instance.check-served-web-roots` (docs/reference/deployments.md).
 */
final readonly class RemoteProductionWebRootManager implements ProductionWebRootManager
{
    public function __construct(private AgentCommandRunner $agents) {}

    public function prepare(Instance $instance, ?Route $activating = null): void
    {
        $instance->loadMissing(['project', 'node']);
        $applications = RouteWebRoot::servedApplications($instance, $activating);

        if ($applications === []) {
            return;
        }

        $this->run($instance, 'instance.prepare-served-web-roots', $applications, 'app-prod-web-roots');
    }

    public function assertServable(Instance $instance, string $webRoot): void
    {
        $instance->loadMissing(['project', 'node']);

        try {
            $this->run($instance, 'instance.check-served-web-roots', [['web_root' => $webRoot, 'directory' => RouteWebRoot::relativeDirectory($webRoot)]], 'app-prod-web-root-check');
        } catch (RuntimeConvergenceException $exception) {
            if (AgentStep::refusal($exception)?->errorCode !== 'instance.release_invalid') {
                throw $exception;
            }

            throw new ResourceOperationException(
                errorCode: 'app-prod.web_root_invalid',
                message: "The selected release does not hold web root [{$webRoot}] as a directory without links, with its application directory.",
                status: 409,
                previous: $exception,
            );
        }
    }

    /** @param  list<array{web_root: string, directory: string}>  $served */
    private function run(Instance $instance, string $command, array $served, string $step): void
    {
        $user = $instance->production_user;
        $home = $instance->production_home;
        $root = $instance->root ?? $instance->project->root;

        if (! $instance->placedOnAppProd() || ! is_string($user) || ! is_string($home) || $home !== "/home/{$user}" || ! is_string($root)) {
            throw new ResourceOperationException('app-prod.web_root_invalid', 'The production Instance identity is incomplete.', 409);
        }

        try {
            AgentStep::run($this->agents, $instance->node, $command, [
                'repository' => $instance->project->repository_url,
                'user' => $user,
                'home' => $home,
                'instance' => $instance->id,
                'relative_root' => $root,
                'application_suffix' => ProductionApplicationPaths::suffix($root),
                'served' => array_map(static fn (array $application): array => ['web_root' => $application['web_root'], 'directory' => $application['directory']], $served),
            ], $step, 'app-prod.web_root_invalid', 'App production');
        } catch (RuntimeConvergenceException $exception) {
            if (AgentStep::refusal($exception)?->errorCode === 'instance.release_missing') {
                throw new ResourceOperationException(
                    errorCode: 'route.web_root_release_missing',
                    message: 'Deploy the production Instance before a Route with a web root serves it.',
                    status: 409,
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }
}
