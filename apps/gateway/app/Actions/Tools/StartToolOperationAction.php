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
use App\Domain\Tools\AptSourceTrust;
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
                'repository' => $repository, 'repository_record' => $repositoryRecord, 'repository_packages' => $repositoryPackages, 'repository_members' => $repositoryMembers,
                'owned' => $owned, 'definition' => $definition] = $admitted;
            if ($manager->name() === ToolManagerName::ConnServer) {
                return app(ConnServerToolMembership::class)->record($verb, $nodeId, $tool, $definition, request()->attributes->getString('orbit.request_id'));
            }
            // Ownership decides which packages apt prefers; surviving membership decides whether the source may go.
            $removeRepository = $verb === 'remove' && $repository !== null && $repositoryMembers === [];
            // The trust the run is admitted with: a receipt that wrote nothing may keep it, never create it.
            $verified = $repository !== null && AptSourceTrust::verified($repositoryRecord, $repository);

            $input = [
                ...array_diff_key(new AdmittedToolPackage($node, $manager->name(), $package, $definition, $tool, $record, $protected)->toArray(), ['id' => true, 'protected' => true]),
                'repository' => $repository, 'repository_id' => $repositoryRecord?->id, 'remove_repository' => $removeRepository,
                'repository_packages' => $repositoryPackages, 'repository_origins' => $repository === null ? [] : AptSourceTrust::origins($repositoryRecord, $repository),
                'repository_given' => $repository !== null && $verb !== 'remove' && ! $verified,
                'package_owned' => $owned,
                'tool_id' => $tool?->id,
                'failed_operation' => $tool?->failed_operation?->value, 'error_code' => $tool?->error_code,
                'protected_packages' => $protected,
                'manager_ready' => $record?->status === LifecycleStatus::Active,
            ];
            if (! $relinquish) {
                // Only physical work needs the native SSH target and manager setup.
                $input['ssh'] = app(SwampSshExecutionInput::class)->forNode($node);
                $input['setup'] = $verb === 'install' ? app(ToolManagerIntent::class)->nativeInput($node, $manager->name()) : null;
            }
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

            if ($repository !== null) {
                // The record is the cleanup handle and trust state of a source this run may publish, remove or repin.
                $input['repository_id'] = AptSourceTrust::handle($nodeId, $repository)->id;
            }
            $operation = $this->operations->execute('tool.'.$verb, 'node_tools', $nodeId, $nodeId, $input, 'operator', $requestId, lockKey: $manager->name()->scope()->operationLockKey($nodeId));
            if ($repository !== null && $operation->wasRecentlyCreated) {
                AptSourceTrust::claim($nodeId, [$repository], $operation->id);
            }
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
     * @param  Closure(array{node: Node, manager: ToolManager, record: ?ToolManagerRecord, tool: ?Tool, relinquish: bool, protected: list<string>, repository: ?array<string, mixed>, repository_record: ?AptRepository, repository_packages: list<string>, repository_members: list<string>, owned: bool, definition: ToolDefinition}): T  $then
     * @return T
     */
    private function admit(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected, ?array $repository, ?NodeAgentData $agent, ?string $version, Closure $then): mixed
    {
        try {
            return DB::transaction(function () use ($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository, $version, $then): mixed {
                $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
                $manager = $this->manager($node, $managerName);
                app(ToolConvergeIntent::class)->assertIdle($nodeId, [$manager->name()->scope()->value]);
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
                    // An idle installing record is a first install whose run ended without a projection: cancelled, unreadable or
                    // timed out. The manager lock keeps it from overlapping a run that could still write it.
                    // A withdrawn Tool only lets its removal finish: adopting it would take back the package its removal released.
                    $retryable = $tool->status === ToolStatus::Installed
                        || ($tool->withdrawn() ? $verb === 'remove' : ($tool->status === ToolStatus::Failed && ($verb === 'adopt' || $verb === 'remove' || $tool->failed_operation?->value === $verb)))
                        || ($tool->status === ToolStatus::Installing && $verb !== 'update');
                    // A host Conn membership records a service, not a package Orbit installed, so ownership does not apply to it.
                    if (! $retryable || ($verb === 'update' && ! $tool->owned && $manager->name() !== ToolManagerName::ConnServer)) {
                        $this->refuse('tool.state_invalid');
                    }
                }
                $protected = $this->protection->forNode($node, $manager->name());
                $hostOwned = in_array($package, $protected, true);
                // Uninstall authority is the Tool's recorded ownership alone: Orbit installed, adopted or converged its package. No
                // failure, retry, status or sibling changes it. Without it, removal clears only the record, or, when the Tool's apt
                // source may be on disk, cleans that source and its pins without touching the package or needing its manager.
                $owned = $tool !== null && $tool->owned;
                $sourceName = $tool?->aptRepository->name ?? $tool?->desired_repository['name'] ?? null;
                $relinquish = $verb === 'remove' && ($roleOwned || $hostOwned || (! $owned && $sourceName === null));
                if ($hostOwned && ! $relinquish) {
                    $this->refuse($verb === 'adopt' ? 'tool.adoption_unsupported' : 'tool.package_protected');
                }
                $sourceOnly = $verb === 'remove' && ! $owned;
                if (! $relinquish && ! $sourceOnly && $verb !== 'install' && $record !== null && $record->status !== LifecycleStatus::Active) {
                    $this->refuse('tool.manager_unavailable');
                }

                $active = app(ToolPolicy::class)->active();
                $definition = $this->definitions->resolve($verb, $manager, $package, $tool, $version, $constraint, $repository, $active);
                // A removal cleans or repins the source as the Node's record now defines it, so surviving members keep their current origin.
                $removing = $verb === 'remove' && $sourceName !== null && ! $relinquish
                    ? AptRepository::query()->where('node_id', $nodeId)->where('name', $sourceName)->first() : null;
                $repository = $relinquish ? null : ($verb === 'remove' ? ($removing->definition ?? $tool?->desired_repository) : $definition->repository);
                $repositoryRecord = $verb === 'remove' ? $removing : $tool?->aptRepository;
                $repositoryPackages = [];
                $repositoryMembers = [];
                if ($repository !== null && $verb !== 'remove') {
                    AptSourceTrust::assertDistinct($repository, AptSourceTrust::nodeDefinitions($nodeId));
                }
                if ($repository !== null) {
                    $repositoryName = $repository['name'];
                    $repositoryRecord ??= AptRepository::query()->where('node_id', $nodeId)->where('name', $repositoryName)->first();
                    $members = Tool::query()->with(['aptRepository', 'manager'])->where('node_id', $nodeId)->where('tool_manager_id', $record?->id)->get();
                    $repositoryPackages = $this->definitions->repositoryPackages($active, $repository, $members, $package, $verb === 'remove');
                    $repositoryMembers = $this->definitions->repositoryMembers($active, $repository, $members, $package);
                    // Repinning survivors names the source's origins. Only a proven record says which origins are on disk; after an
                    // uncertain write, a write of a surviving member republishes the source first.
                    if ($verb === 'remove' && $repositoryMembers !== [] && ! AptSourceTrust::verified($repositoryRecord, $repository)) {
                        $this->refuse('tool.repository_unverified');
                    }
                }

                return $then(['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'protected' => $protected,
                    'repository' => $repository, 'repository_record' => $repositoryRecord, 'repository_packages' => $repositoryPackages, 'repository_members' => $repositoryMembers,
                    'owned' => $owned, 'definition' => $definition]);
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
