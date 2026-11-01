<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ReservesRolePackages;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolPackageProtection;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Http\Requests\Tools\AptRepositoryRules;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AptRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Facades\DB;

/** Validates and records intent. Performs no Node work. */
final readonly class StartToolOperationAction
{
    public function __construct(
        private StartOperationAction $operations,
        private ToolManagerRegistry $managers,
        private ToolNodeEligibility $eligibility,
        private VersionConstraint $constraints,
        private ToolPackageProtection $protection,
    ) {}

    /** @param array<string, mixed>|null $repository */
    public function execute(string $verb, int $nodeId, string $managerName, string $package, ?string $constraint, ?Tool $selected = null, ?array $repository = null): Operation
    {
        try {
            return DB::transaction(function () use ($verb, $nodeId, $managerName, $package, $constraint, $selected, $repository): Operation {
                $node = Node::query()->lockForUpdate()->findOrFail($nodeId);
                $manager = $this->manager($node, $managerName);
                if (! $manager->validatePackage($package)) {
                    $this->refuse('tool.package_invalid', 422);
                }
                if (! $this->constraints->isValid($constraint)) {
                    $this->refuse('tool.constraint_invalid', 422);
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
                    if ($tool->version_constraint !== $constraint) {
                        $this->refuse('tool.constraint_conflict');
                    }
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

                new AptRepositoryRules()->validate($repository, $managerName);
                $currentRepository = $tool?->aptRepository;
                if ($currentRepository !== null && $repository !== null && $repository['name'] !== $currentRepository->name) {
                    $this->refuse('tool.repository_conflict');
                }
                $repositoryRecord = $currentRepository;
                if ($repository !== null) {
                    $repositoryRecord ??= AptRepository::query()->where('node_id', $nodeId)->where('name', $repository['name'])->first();
                    if ($repositoryRecord !== null && $repositoryRecord->definition != $repository && $verb !== 'update'
                        && Tool::query()->where('apt_repository_id', $repositoryRecord->id)->exists()) {
                        $this->refuse('tool.repository_conflict');
                    }
                    $repositoryRecord ??= AptRepository::query()->create(['node_id' => $nodeId, 'name' => $repository['name'], 'definition' => $repository]);
                    if ($repositoryRecord->definition != $repository) {
                        $repositoryRecord->update(['definition' => $repository]);
                    }
                }
                if ($verb === 'update' && $tool !== null && $repositoryRecord !== null) {
                    $tool->update(['apt_repository_id' => $repositoryRecord->id]);
                }
                $repository = $repositoryRecord?->definition;
                $removeRepository = $verb === 'remove' && $repositoryRecord !== null
                    && ! Tool::query()->where('apt_repository_id', $repositoryRecord->id)->where('id', '!=', $tool?->id)->exists();

                $intentOnly = $roleOwned || ($verb === 'remove' && $repository === null && $tool?->status === ToolStatus::Failed
                    && $tool->error_code === 'tool.version_probe_failed' && ! $tool->installed_version);

                return $this->operations->execute('tool.'.$verb, 'node_tools', $nodeId, $nodeId, [
                    'node_id' => $nodeId, 'manager' => $managerName, 'package' => $package, 'user' => $node->user,
                    'platform' => $node->platform, 'architecture' => $node->architecture,
                    'version_constraint' => $constraint,
                    'repository' => $repository, 'repository_id' => $repositoryRecord?->id, 'remove_repository' => $removeRepository,
                    'tool_id' => $tool?->id, 'manager_id' => $record?->id,
                    'tool_status' => $tool?->status->value, 'installed_version' => $tool?->installed_version,
                    'failed_operation' => $tool?->failed_operation?->value, 'error_code' => $tool?->error_code,
                    'intent_only' => $intentOnly,
                    'protected_packages' => $protected,
                    'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
                    'setup' => $verb === 'install' ? app(ToolManagerIntent::class)->nativeInput($node, $manager->name()) : null,
                ], 'operator', request()->attributes->getString('orbit.request_id'), lockKey: $manager->name()->operationLockKey($nodeId));
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
                adoptionBlock: $error->errorCode === 'tool.adoption_unsupported' ? 'protected' : null,
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
