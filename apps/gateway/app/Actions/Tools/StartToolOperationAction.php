<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Data\Nodes\NodeAgentData;
use App\Domain\ConnServer\ConnServerToolMembership;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AdmittedToolPackage;
use App\Domain\Tools\AptRepositories;
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
use App\Support\ValidatedData;
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

    /** @param string|null $repository the name of the Node's apt repository the Tool installs from */
    public function execute(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected = null, ?string $repository = null, ?string $version = null): Operation
    {
        if (($existing = app(OperationIdempotency::class)->replay('tool.'.$verb)) !== null) {
            return $existing;
        }

        return $this->admit($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository, null, $version, function (array $admitted) use ($verb, $nodeId, $managerName, $package): Operation {
            ['manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'repository_record' => $repositoryRecord,
                'definition' => $definition] = $admitted;
            if ($manager->name() === ToolManagerName::ConnServer) {
                return app(ConnServerToolMembership::class)->record($verb, $nodeId, $tool, $definition, request()->attributes->getString('orbit.request_id'));
            }
            $input = $this->input($verb, $package, $admitted);
            $requestId = request()->attributes->getString('orbit.request_id');
            if ($verb === 'install' && $tool === null) {
                // The reviewed install chart records the Tool as installing; a failed manager setup drops it again.
                $record ??= ToolManagerRecord::query()->create(['node_id' => $nodeId, 'name' => $managerName, 'status' => LifecycleStatus::Provisioning]);
                $tool = Tool::query()->create(['node_id' => $nodeId, 'tool_manager_id' => $record->id, 'package' => $package,
                    'version_constraint' => $definition->constraint, 'desired_version' => $definition->version,
                    'apt_repository_id' => $repositoryRecord?->id, 'status' => ToolStatus::Installing]);
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
                $tool?->update(['desired_version' => $definition->version, 'version_constraint' => $definition->constraint, 'apt_repository_id' => $repositoryRecord?->id]);
            }

            return $operation;
        });
    }

    /**
     * Admits again, in place, a request the preceding release admitted and never started, so its first dispatch carries what the
     * current admission gives it: scope, protection, membership, ownership, its repository and pins (docs/reference/tools.md#upgrade-from-the-preceding-release).
     * A write that named a source names that repository, which stays not ready until its own add, so the write is refused until then.
     * A request the current admission would relinquish ends as that membership-only receipt; one it refuses throws its refusal and keeps
     * the Operation as it was.
     */
    /** @param list<int> $beside the other queued requests the upgrade admits again, which no first dispatch has ordered yet */
    public function readmit(Operation $operation, array $beside = []): void
    {
        $verb = substr($operation->kind, 5);
        $input = $operation->input;
        $selected = is_int($input['tool_id'] ?? null) ? Tool::query()->find($input['tool_id']) : null;
        $repository = $verb !== 'remove' && ($input['repository_given'] ?? false) === true && is_array($input['repository'] ?? null)
            ? ValidatedData::string($input['repository']['name'] ?? null) : null;
        $this->admit($verb, ValidatedData::integer($operation->node_id), ValidatedData::string($input['manager'] ?? null), ValidatedData::string($input['package'] ?? null),
            ValidatedData::nullableString($input['version_constraint'] ?? null), $selected, $repository, null, null, function (array $admitted) use ($operation, $verb): void {
                ['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'repository_record' => $repositoryRecord,
                    'definition' => $definition] = $admitted;
                $current = $this->input($verb, ValidatedData::string($operation->input['package']), $admitted);
                if ($relinquish) {
                    $operation->forceFill(['input' => $current, 'status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null,
                        'result' => ['manager_version' => null, 'installed_version' => null, 'outcome' => 'applied', 'execution' => 'membership_only']])->save();
                    $tool?->delete();

                    return;
                }
                if ($verb === 'install' && $tool === null) {
                    $record ??= ToolManagerRecord::query()->create(['node_id' => $node->id, 'name' => $manager->name()->value, 'status' => LifecycleStatus::Provisioning]);
                    $tool = Tool::query()->create(['node_id' => $node->id, 'tool_manager_id' => $record->id, 'package' => $operation->input['package'],
                        'version_constraint' => $definition->constraint, 'desired_version' => $definition->version,
                        'apt_repository_id' => $repositoryRecord?->id, 'status' => ToolStatus::Installing]);
                    $current = [...$current, 'tool_id' => $tool->id, 'manager_id' => $record->id];
                }
                if ($verb !== 'remove') {
                    $tool?->update(['desired_version' => $definition->version, 'version_constraint' => $definition->constraint, 'apt_repository_id' => $repositoryRecord?->id]);
                }
                $operation->forceFill(['input' => $current])->save();
            }, $operation->id, $beside);
    }

    /**
     * The input the current admission gives a request. A write pins its package and the Node's other owned members of its repository; a
     * removal repins the remaining ones. Neither publishes nor removes the repository itself.
     *
     * @param  array{node: Node, manager: ToolManager, record: ?ToolManagerRecord, tool: ?Tool, relinquish: bool, protected: list<string>, repository_record: ?AptRepository, repository_packages: list<string>, owned: bool, definition: ToolDefinition}  $admitted
     * @return array<string, mixed>
     */
    private function input(string $verb, string $package, array $admitted): array
    {
        ['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'protected' => $protected,
            'repository_record' => $repositoryRecord, 'repository_packages' => $repositoryPackages, 'owned' => $owned, 'definition' => $definition] = $admitted;
        $input = [
            ...array_diff_key(new AdmittedToolPackage($node, $manager->name(), $package, $definition, $tool, $record, $protected, $repositoryRecord)->toArray(), ['id' => true, 'protected' => true]),
            'repository_id' => $repositoryRecord?->id, 'repository_packages' => $repositoryPackages,
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

        return $input;
    }

    /**
     * The Tool's authority is still set aside by a removal whose start Swamp has not yet bound to a run or verified never ran: the
     * suspension's own receipt is still the newest change of the Tool's ownership. A newer acquisition took it back since.
     */
    private static function unsettledRemoval(Tool $tool): bool
    {
        $suspended = $tool->receipts['suspended'] ?? null;
        $removal = is_array($suspended) && is_int($suspended['operation'] ?? null) ? Operation::query()->find($suspended['operation']) : null;

        return $removal !== null && ! $tool->owned && ($tool->receipts['owner'] ?? null) === Tool::order($removal->id)
            && ! isset($removal->state['swamp_run_id']) && data_get($removal->state, 'native_timeout.no_run_verified_at') === null;
    }

    /**
     * Records ownership of an installed package from the Node agent's latest report, after the same admission checks
     * (R1: no task, no run). Refusals carry the agent's liveness.
     *
     * @return array{Tool, ToolOutcome}
     */
    public function adopt(int $nodeId, string $managerName, string $package, ?string $constraint, ?string $repository, ?NodeAgentData $agent): array
    {
        return $this->admit('adopt', $nodeId, $managerName, $package, $constraint, null, $repository, $agent, null,
            fn (array $admitted): array => $this->adoption->record($admitted['node'], $admitted['manager'], $admitted['record'], $admitted['tool'], $package, $admitted['definition'], $admitted['repository_record']));
    }

    /**
     * The shared admission checks, in one transaction with `$then`.
     *
     * @template T
     *
     * @param  Closure(array{node: Node, manager: ToolManager, record: ?ToolManagerRecord, tool: ?Tool, relinquish: bool, protected: list<string>, repository_record: ?AptRepository, repository_packages: list<string>, owned: bool, definition: ToolDefinition}): T  $then
     * @param  int|null  $owner  the Operation admitted again, which its own idleness check skips
     * @param  list<int>  $beside  the other queued requests admitted again with it
     * @return T
     */
    private function admit(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected, ?string $repository, ?NodeAgentData $agent, ?string $version, Closure $then, ?int $owner = null, array $beside = []): mixed
    {
        try {
            return DB::transaction(function () use ($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository, $version, $then, $owner, $beside): mixed {
                $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
                $manager = $this->manager($node, $managerName);
                app(ToolConvergeIntent::class)->assertIdle($nodeId, [$manager->name()->scope()->value], $owner, $beside);
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
                // A removal whose start may have reached the Node set aside the Tool's authority until Swamp answers whether that start became
                // a run (ToolIntentProjection::timedOut). Until its lookup by start key answers, a retry neither removes nor gives up the
                // package: a start never sent gets its authority back, and a run found settles by its own end.
                if ($verb === 'remove' && $tool !== null && self::unsettledRemoval($tool)) {
                    $this->refuse('tool.removal_unsettled');
                }
                $protected = $this->protection->forNode($node, $manager->name());
                $hostOwned = in_array($package, $protected, true);
                // Uninstall authority is the Tool's recorded ownership alone: Orbit installed, adopted or converged its package. No
                // failure, retry, status or sibling changes it. Without it, removal clears only the record: an unowned membership was never
                // pinned to its repository, and the repository keeps its own lifecycle.
                $owned = $tool !== null && $tool->owned;
                $relinquish = $verb === 'remove' && ($roleOwned || $hostOwned || ! $owned);
                if ($hostOwned && ! $relinquish) {
                    $this->refuse($verb === 'adopt' ? 'tool.adoption_unsupported' : ToolPackageProtection::ErrorCode);
                }
                if (! $relinquish && $verb !== 'install' && $record !== null && $record->status !== LifecycleStatus::Active) {
                    $this->refuse('tool.manager_unavailable');
                }

                $definition = $this->definitions->resolve($verb, $manager, $package, $tool, $version, $constraint, $repository, app(ToolPolicy::class)->active());
                // A write needs its repository ready; a removal repins the remaining members of the one the Tool references, whatever its state.
                $repositoryRecord = $relinquish || $definition->repository === null ? null
                    : ($verb === 'remove' ? $tool?->aptRepository : AptRepositories::usable($nodeId, $definition->repository));
                $repositoryPackages = $repositoryRecord === null ? [] : $this->definitions->repositoryPackages($repositoryRecord->id,
                    Tool::query()->with('manager')->where('node_id', $nodeId)->where('apt_repository_id', $repositoryRecord->id)->get(), $package, $verb === 'remove');

                return $then(['node' => $node, 'manager' => $manager, 'record' => $record, 'tool' => $tool, 'relinquish' => $relinquish, 'protected' => $protected,
                    'repository_record' => $repositoryRecord, 'repository_packages' => $repositoryPackages, 'owned' => $owned, 'definition' => $definition]);
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
