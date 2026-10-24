<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use Throwable;

final readonly class NativeInstanceStateInspector implements InstanceStateInspector
{
    public function __construct(
        private CommandDeadline $deadline,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private ProductionInstanceInspectionExpectationFactory $productionExpectations,
        private AgentCommandRunner $agents,
    ) {}

    public function inspect(Instance $instance): InstanceInspectionData
    {
        $instance->loadMissing(['project', 'node']);

        if ($instance->placedOnAppProd()) {
            return $this->inspectProduction($instance);
        }

        try {
            $account = $this->accounts->resolve($instance->node);
            $root = $this->removal->instanceRoot($instance, $account);
            $repository = GitRepositoryOrigin::validate($instance->project->repository_url);
            $values = $this->agents->run($instance->node, 'doctor.inspect-development-source', [
                'repository' => $repository,
                'checkout' => $instance->checkout_path,
                'root' => $root->value,
                'user' => $account->user,
                'group' => $account->group,
                'layout' => $instance->source_layout,
                'starting_commit' => $instance->starting_commit ?? '',
            ], $this->deadline->cap(30.0));
            foreach (['checkout', 'layout', 'origin', 'history'] as $field) {
                if (! is_bool($values[$field] ?? null)) {
                    throw new DoctorInspectionException;
                }
            }
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        return new InstanceInspectionData(
            checkoutExists: $values['checkout'],
            repositoryLayoutMatches: $values['layout'],
            originMatches: $values['origin'],
            sourceIdentityMatches: $values['history'],
        );
    }

    private function inspectProduction(Instance $instance): InstanceInspectionData
    {
        try {
            $expectation = $this->productionExpectations->make($instance);
            $runtime = $expectation->runtime;
            $configuration = $expectation->runtimeConfiguration;
            $initial = $expectation->initialRuntimeConfiguration;
            $values = $this->agents->run($instance->node, 'doctor.inspect-production-instance', [
                'user' => $expectation->user,
                'home' => $expectation->home,
                'root' => $expectation->root,
                'application_suffix' => ProductionApplicationPaths::suffix($expectation->root),
                'environment' => $expectation->environment(),
                'caddy_sites' => $expectation->caddySites,
                'association' => $expectation->associationMatches,
                'runtime' => $runtime !== null && $configuration !== null && $initial !== null ? [
                    'version' => $runtime->version,
                    'marker' => $runtime->marker(),
                    'application' => $runtime->applicationDirectory(),
                    'initial_application' => $runtime->applicationDirectory(initialRelease: true),
                    'configuration' => [
                        'main' => $configuration->main,
                        'pool' => $configuration->pool,
                        'initial_pool' => $initial->pool,
                        'local_defaults' => $configuration->localDefaults,
                        'master_ini' => $configuration->masterIni,
                        'unit' => $configuration->unit,
                    ],
                ] : null,
            ], $this->deadline->cap(30.0));
            foreach (['home', 'selection', 'root', 'environment', 'php', 'caddy'] as $field) {
                if (! array_key_exists($field, $values) || ($values[$field] !== null && ! is_bool($values[$field]))) {
                    throw new DoctorInspectionException;
                }
            }
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        return new InstanceInspectionData(
            checkoutExists: true,
            repositoryLayoutMatches: true,
            originMatches: true,
            sourceIdentityMatches: true,
            productionHomeMatches: $values['home'],
            releaseSelectionMatches: $values['selection'],
            selectedReleaseRootMatches: $values['root'],
            environmentProjectionMatches: $values['environment'],
            phpFpmProjectionMatches: $values['php'],
            caddyProjectionMatches: $values['caddy'],
        );
    }
}
