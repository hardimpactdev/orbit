<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeAgentData;
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
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolPackageProtection;
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
            ['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'role_owned' => $roleOwned, 'protected' => $protected,
                'repository' => $repository, 'repository_record' => $repositoryRecord, 'repository_given' => $repositoryGiven, 'definition' => $definition] = $admitted;
            // The repository's apt preference admits exactly the packages of the Tools that reference it.
            $repositoryPackages = $repositoryRecord === null ? ($repository === null || $verb === 'remove' ? [] : [$package]) : Tool::query()
                ->where('apt_repository_id', $repositoryRecord->id)
                ->when($tool !== null, static fn ($query) => $query->whereKeyNot($tool?->id))->pluck('package')
                ->when($verb !== 'remove', static fn ($packages) => $packages->push($package))
                ->unique()->sort()->values()->all();
            $removeRepository = $verb === 'remove' && $repositoryRecord !== null && $repositoryPackages === [];

            $input = [
                ...array_diff_key(new AdmittedToolPackage($node, $manager->name(), $package, $definition, $tool, $record, $protected)->toArray(), ['id' => true, 'protected' => true]),
                'repository' => $repository, 'repository_id' => $repositoryRecord?->id, 'remove_repository' => $removeRepository,
                'repository_packages' => $repositoryPackages, 'repository_given' => $repositoryGiven,
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
                    'version_constraint' => $definition->constraint, 'desired_version' => $definition->version, 'status' => ToolStatus::Installing]);
                $input['tool_id'] = $tool->id;
                $input['manager_id'] = $record->id;
            }
            // R1: a role-owned package, or a failed install that never left a version, has nothing on the Node to remove.
            if ($verb === 'remove' && ($roleOwned || ($repository === null && $tool?->status === ToolStatus::Failed
                && $tool->error_code === 'tool.version_probe_failed' && ! $tool->installed_version))) {
                $operation = $this->operations->record('tool.remove', 'node_tools', $nodeId, $nodeId, $input,
                    ['manager_version' => null, 'installed_version' => null, 'outcome' => 'applied'], 'operator', $requestId, $manager->name()->operationLockKey($nodeId));
                $tool?->delete();

                return $operation;
            }

            $operation = $this->operations->execute('tool.'.$verb, 'node_tools', $nodeId, $nodeId, $input, 'operator', $requestId, lockKey: $manager->name()->scope()->operationLockKey($nodeId));
            if ($verb !== 'remove' && $operation->wasRecentlyCreated) {
                $tool?->update(['desired_version' => $definition->version, 'version_constraint' => $definition->constraint]);
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
     * @param  Closure(array{node: Node, manager: ToolManager, record: ?ToolManagerRecord, tool: ?Tool, role_owned: bool, protected: list<string>, repository: ?array<string, mixed>, repository_record: ?AptRepository, repository_given: bool, definition: ToolDefinition}): T  $then
     * @return T
     */
    private function admit(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected, ?array $repository, ?NodeAgentData $agent, ?string $version, Closure $then): mixed
    {
        try {
            return DB::transaction(function () use ($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository, $version, $then): mixed {
                $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
                app(ToolConvergeIntent::class)->assertIdle($nodeId, direct: true);
                $manager = $this->manager($node, $managerName);
                if (! $manager->validatePackage($package)) {
                    $this->refuse('tool.package_invalid', 422);
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
                if ($verb !== 'install' && $record !== null && $record->status !== LifecycleStatus::Active) {
                    $this->refuse('tool.manager_unavailable');
                }
                $protected = $this->protection->forNode($node, $manager->name());
                if (in_array($package, $protected, true) && ! $roleOwned) {
                    $this->refuse($verb === 'adopt' ? 'tool.adoption_unsupported' : 'tool.package_protected');
                }

                $definition = $this->definitions->resolve($verb, $manager, $package, $tool, $version, $constraint, $repository);
                $repository = $definition->repository;
                $currentRepository = $tool?->aptRepository;
                if ($currentRepository !== null && $repository !== null && $repository['name'] !== $currentRepository->name) {
                    $this->refuse('tool.repository_conflict');
                }
                // R2: the Gateway stores a repository definition, and links it, only from the run's terminal result.
                $repositoryRecord = $currentRepository;
                if ($repository !== null) {
                    $repositoryRecord ??= AptRepository::query()->where('node_id', $nodeId)->where('name', $repository['name'])->first();
                    if ($repositoryRecord !== null && $repositoryRecord->definition != $repository && $verb !== 'update'
                        && Tool::query()->where('apt_repository_id', $repositoryRecord->id)->exists()) {
                        $this->refuse('tool.repository_conflict');
                    }
                }
                $repositoryGiven = $repository !== null && $repositoryRecord?->definition != $repository;
                $repository ??= $repositoryRecord?->definition;

                return $then(['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'role_owned' => $roleOwned, 'protected' => $protected,
                    'repository' => $repository, 'repository_record' => $repositoryRecord, 'repository_given' => $repositoryGiven, 'definition' => $definition]);
            });
        } catch (ResourceOperationException $error) {
            if (! str_starts_with($error->errorCode, 'tool.')) {
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
