<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeAgentData;
use App\Domain\ConnServer\ConnServerToolMembership;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AdmittedToolPackage;
use App\Domain\Tools\ReservesRolePackages;
use App\Domain\Tools\ToolAdoption;
use App\Domain\Tools\ToolAdoptionBlocked;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolDefinition;
use App\Domain\Tools\ToolDefinitionResolver;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolPackageProtection;
use App\Domain\Tools\ToolPolicy;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Closure;
use Illuminate\Support\Facades\DB;

/** Validates and records intent. Performs no Node work; adoption reads only the Node agent's report. */
final readonly class StartToolOperationAction
{
    public function __construct(
        private StartOperationAction $operations,
        private ToolManagerRegistry $managers,
        private ToolNodeEligibility $eligibility,
        private ToolDefinitionResolver $definitions,
        private ToolPackageProtection $protection,
        private ToolAdoption $adoption,
    ) {}

    /** @param array<string, mixed>|null $repository */
    public function execute(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected = null, ?array $repository = null, ?string $version = null): Operation
    {
        if (($existing = app(OperationIdempotency::class)->replay('tool.'.$verb)) !== null) {
            return $existing;
        }

        return $this->admit($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository, null, $version, function (array $admitted) use ($verb, $nodeId, $managerName, $package): Operation {
            ['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'protected' => $protected,
                'repository' => $repository, 'repository_record' => $repositoryRecord, 'repository_packages' => $repositoryPackages, 'definition' => $definition] = $admitted;
            if ($manager->name() === ToolManagerName::ConnServer) {
                return app(ConnServerToolMembership::class)->record($verb, $nodeId, $tool, $definition, request()->attributes->getString('orbit.request_id'));
            }
            $removeRepository = $verb === 'remove' && $repository !== null && $repositoryPackages === [];

            $input = [
                ...array_diff_key(new AdmittedToolPackage($node, $manager->name(), $package, $definition, $tool, $record, $protected)->toArray(), ['id' => true, 'protected' => true]),
                'repository' => $repository, 'repository_id' => $repositoryRecord?->id, 'remove_repository' => $removeRepository,
                'repository_packages' => $repositoryPackages, 'repository_given' => $repository !== null && $verb !== 'remove'
                    && ! ToolDefinition::sameRepository($repositoryRecord?->definition, $repository),
                'tool_id' => $tool?->id,
                'failed_operation' => $tool?->failed_operation?->value, 'error_code' => $tool?->error_code,
                'protected_packages' => $protected,
                'manager_ready' => $record?->status === LifecycleStatus::Active,
                'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
                'setup' => $verb === 'install' ? app(ToolManagerIntent::class)->nativeInput($node, $manager->name()) : null,
            ];
            $requestId = request()->attributes->getString('orbit.request_id');
            if ($verb === 'install' && $tool === null) {
                // The reviewed install chart records the Tool as installing; a failed manager setup drops it again.
                $record ??= ToolManagerRecord::query()->create(['node_id' => $nodeId, 'name' => $managerName, 'status' => LifecycleStatus::Provisioning]);
                $tool = Tool::query()->create(['node_id' => $nodeId, 'tool_manager_id' => $record->id, 'package' => $package,
                    'version_constraint' => $definition->constraint, 'desired_version' => $definition->version,
                    'desired_repository' => $definition->repository, 'status' => ToolStatus::Installing]);
                $input['tool_id'] = $tool->id;
                $input['manager_id'] = $record->id;
            }
            if ($relinquish) {
                $operation = $this->operations->record('tool.remove', 'node_tools', $nodeId, $nodeId, $input,
                    ['manager_version' => null, 'installed_version' => null, 'outcome' => 'applied', 'execution' => 'membership_only'],
                    'operator', $requestId, $manager->name()->scope()->operationLockKey($nodeId));
                $tool?->delete();

                return $operation;
            }

            $operation = $this->operations->execute('tool.'.$verb, 'node_tools', $nodeId, $nodeId, $input, 'operator', $requestId, lockKey: $manager->name()->scope()->operationLockKey($nodeId));
            if ($verb !== 'remove' && $operation->wasRecentlyCreated) {
                $tool?->update(['desired_version' => $definition->version, 'version_constraint' => $definition->constraint, 'desired_repository' => $definition->repository]);
            }

            return $operation;
        });
    }

    /**
     * Records ownership of an installed package from the Node agent's latest report, after the same admission checks
     * (R1: no task, no run). Refusals carry the agent's liveness.
     *
     * @param  array<string, mixed>|null  $repository
     * @return array{Tool, ToolOutcome}
     */
    public function adopt(int $nodeId, string $managerName, string $package, ?string $constraint, ?array $repository, ?NodeAgentData $agent): array
    {
        return $this->admit('adopt', $nodeId, $managerName, $package, $constraint, null, $repository, $agent, null,
            fn (array $admitted): array => $this->adoption->record($admitted['node'], $admitted['manager'], $admitted['record'], $admitted['tool'], $package, $admitted['definition'], $admitted['repository']));
    }

    /**
     * The shared admission checks, in one transaction with `$then`.
     *
     * @template T
     *
     * @param  array<string, mixed>|null  $repository
     * @param  Closure(array{node: Node, manager: ToolManager, record: ?ToolManagerRecord, tool: ?Tool, relinquish: bool, protected: list<string>, repository: ?array<string, mixed>, repository_record: ?AptRepository, repository_packages: list<string>, definition: ToolDefinition}): T  $then
     * @return T
     */
    private function admit(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected, ?array $repository, ?NodeAgentData $agent, ?string $version, Closure $then): mixed
    {
        try {
            return DB::transaction(function () use ($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository, $version, $then): mixed {
                $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
                app(ToolConvergeIntent::class)->assertIdle($nodeId);
                $manager = $this->manager($node, $managerName);
                if (! $manager->validatePackage($package)) {
                    $this->refuse('tool.package_invalid', 422);
                }
                if ($verb === 'adopt' && $manager->name() === ToolManagerName::ConnServer) {
                    $this->refuse('tool.adoption_unsupported');
                }
                $roleOwned = $manager instanceof ReservesRolePackages && $manager->roleOwns($package);
                if ($roleOwned && $verb !== 'remove') {
                    $this->refuse(ReservesRolePackages::ERROR_CODE);
                }
                $record = ToolManagerRecord::query()->where('node_id', $nodeId)->where('name', $managerName)->lockForUpdate()->first();
                $tool = $record === null ? null : Tool::query()->where('node_id', $nodeId)
                    ->where('tool_manager_id', $record->id)->where('package', $package)->lockForUpdate()->first();
                if ($selected !== null && ($tool?->id !== $selected->id || $selected->manager->node_id !== $nodeId)) {
                    $this->refuse('tool.state_invalid');
                }
                if ($tool !== null) {
                    $retryable = $tool->status === ToolStatus::Installed
                        || ($tool->status === ToolStatus::Failed && ($verb === 'adopt' || $verb === 'remove' || $tool->failed_operation?->value === $verb));
                    if (! $retryable) {
                        $this->refuse('tool.state_invalid');
                    }
                }
                $protected = $this->protection->forNode($node, $manager->name());
                $hostOwned = in_array($package, $protected, true);
                $relinquish = $verb === 'remove' && ($roleOwned || $hostOwned || ($tool?->status === ToolStatus::Failed
                    && $tool->installed_version === null && $tool->failed_operation === ToolOperation::Install
                    && ($record?->status === LifecycleStatus::Failed
                        || ($tool->error_code === 'tool.version_probe_failed' && $tool->desired_repository === null && $tool->aptRepository === null))));
                if ($hostOwned && ! $relinquish) {
                    $this->refuse($verb === 'adopt' ? 'tool.adoption_unsupported' : 'tool.package_protected');
                }
                if (! $relinquish && $verb !== 'install' && $record !== null && $record->status !== LifecycleStatus::Active) {
                    $this->refuse('tool.manager_unavailable');
                }

                $active = app(ToolPolicy::class)->active();
                $definition = $this->definitions->resolve($verb, $manager, $package, $tool, $version, $constraint, $repository, $active);
                $currentRepository = $tool?->aptRepository;
                $repository = $relinquish ? null : ($verb === 'remove' ? ($currentRepository->definition ?? $tool?->desired_repository) : $definition->repository);
                $repositoryRecord = $currentRepository;
                $repositoryPackages = [];
                if ($repository !== null) {
                    $repositoryName = $repository['name'];
                    $repositoryRecord ??= AptRepository::query()->where('node_id', $nodeId)->where('name', $repositoryName)->first();
                    $repositoryPackages = $verb === 'remove' ? [] : [$package];
                    $others = Tool::query()->with('aptRepository')->where('node_id', $nodeId)->where('tool_manager_id', $record?->id)
                        ->when($tool !== null, static fn ($query) => $query->whereKeyNot($tool?->id))->get();
                    foreach ($others as $other) {
                        $desired = $active->mode === 'file' ? $active->definition($managerName, $other->package)?->repository : $other->desired_repository;
                        $observed = $other->aptRepository?->definition;
                        if (($desired['name'] ?? null) !== $repositoryName && ($observed['name'] ?? null) !== $repositoryName) {
                            continue;
                        }
                        if ($verb !== 'remove' && ! ToolDefinition::sameRepository($desired ?? $observed, $repository)) {
                            $this->refuse('tool.repository_conflict');
                        }
                        $repositoryPackages[] = $other->package;
                    }
                    $repositoryPackages = array_values(array_unique($repositoryPackages));
                    sort($repositoryPackages, SORT_STRING);
                }

                return $then(['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'protected' => $protected,
                    'repository' => $repository, 'repository_record' => $repositoryRecord, 'repository_packages' => $repositoryPackages, 'definition' => $definition]);
            });
        } catch (ResourceOperationException $error) {
            if (! str_starts_with($error->errorCode, 'tool.') && $error->errorCode !== 'conn_server.pin_unavailable') {
                throw $error;
            }
            $toolId = $selected->id ?? Tool::query()->where('node_id', $nodeId)->where('package', $package)
                ->whereHas('manager', static fn ($query) => $query->where('name', $managerName))->value('id');
            throw new ToolOperationException(
                step: $verb, errorCode: $error->errorCode,
                outcome: $error->errorCode === 'tool.constraint_invalid' ? ToolOutcome::ConstraintInvalid : ToolOutcome::ManagerFailed,
                status: $error->status, nodeId: $nodeId, manager: $managerName, package: $package,
                versionConstraint: $constraint, message: $error->getMessage(), toolId: is_int($toolId) ? $toolId : null,
                adoptionBlock: $error instanceof ToolAdoptionBlocked ? $error->block : ($error->errorCode === 'tool.adoption_unsupported' ? 'protected' : null),
                agent: $agent,
            );
        }
    }

    public function manager(Node $node, string $name): ToolManager
    {
        if ($node->status !== LifecycleStatus::Active) {
            $this->refuse('tool.node_inactive');
        }
        if (! $this->eligibility->allows($node)) {
            $this->refuse('tool.node_unmanaged');
        }
        $manager = $this->managers->find($name);
        if ($manager === null || ! $manager->supportsNode($node)) {
            $this->refuse('tool.manager_unsupported', 422);
        }

        return $manager;
    }

    private function refuse(string $code, int $status = 409): never
    {
        throw new ResourceOperationException($code, 'The Tool request cannot be admitted.', $status);
    }
}
