<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceSourceProfileGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Captures development clone intent; source execution belongs to one native209 root. */
final readonly class StartInstanceCloneAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private ManagedCheckoutOverlap $overlap) {}

    public function execute(Instance $candidate, CloneInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.clone')) !== null) {
            return $replay;
        }

        return DB::transaction(function () use ($candidate, $data, $caller, $requestId): Operation {
            $candidate->refresh()->loadMissing(['project', 'node']);
            $node = Node::query()->findOrFail($data->nodeId);
            app(NativeInstanceProvisioningIntent::class)->assertCloneSupported($candidate, $node, $data->sqliteSourcePath);
            if (! $candidate->placedOnAppDev() || ! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Native clone currently accepts development placements only.', 503);
            }
            if ($candidate->status !== InstanceState::Active || $candidate->provisioning_step !== 'active'
                || $candidate->node->status !== LifecycleStatus::Active || $node->status !== LifecycleStatus::Active
                || $node->platform !== 'linux' || $candidate->node->platform !== 'linux') {
                throw $this->conflict('instance.clone_candidate_inactive', 'The candidate and destination must be active.');
            }
            if (! is_bool($candidate->source_is_laravel)) {
                new InstanceSourceProfileGuard()->refuseMissing();
            }
            $branch = $data->branch ?? $candidate->branch;
            if (! is_string($branch) || ! GitBranchName::isValid($branch)
                || ! is_string($candidate->branch) || ! GitBranchName::isValid($candidate->branch)) {
                throw $this->conflict('instance.clone_candidate_branch_invalid', 'The candidate has no configured branch to inherit.');
            }
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $data->name) !== 1) {
                throw $this->conflict('instance.name_invalid', 'The Instance name must be a lowercase DNS label.');
            }
            app(DevelopmentNodeExclusion::class)->assertAvailable($candidate->project, $node);
            $storedRoot = data_get($node->settings, 'apps.path');
            $root = is_string($storedRoot) ? StoragePath::tryParse($storedRoot) : null;
            if ($root === null) {
                throw new ResourceOperationException('operation.kind_unavailable', 'Clone requires the stored destination apps root.', 503);
            }
            $path = $root->value.'/'.$candidate->project->slug.'/'.$data->name;
            $target = Instance::query()->where('project_id', $candidate->project_id)->where('name', $data->name)->lockForUpdate()->first();
            if ($target !== null && ($target->clone_candidate_id !== $candidate->id || $target->node_id !== $node->id
                || $target->checkout_path !== $path || $target->clone_preview_name !== $data->previewName
                || $target->clone_requested_branch !== $data->branch || $target->clone_sqlite_source_path !== $data->sqliteSourcePath)) {
                throw $this->conflict('instance.clone_retry_conflict', 'The target Instance already exists with different immutable clone input.');
            }
            if ($target?->status === InstanceState::Removing) {
                throw $this->conflict('instance.removal_conflict', 'The target Instance is being removed.');
            }
            if ($target?->clone_completed_at !== null) {
                return Operation::query()->where('kind', 'instance.clone')->where('target_type', 'instance')->where('target_id', $target->id)
                    ->where('status', 'succeeded')->orderByDesc('id')->first()
                    ?? throw $this->conflict('instance.clone_retry_conflict', 'The completed clone has no successful admission receipt.');
            }
            $this->overlap->assertAvailable($node->id, StoragePath::parse($path), 'instance.source_conflict', ignoreInstanceId: $target?->id);
            $target ??= Instance::query()->create(['project_id' => $candidate->project_id, 'node_id' => $node->id,
                'name' => $data->name, 'checkout_path' => $path, 'source_layout' => 'checkout', 'root' => $candidate->root,
                'branch' => $branch, 'branch_override' => $data->branch, 'status' => InstanceState::Reserved,
                'source_is_laravel' => $candidate->source_is_laravel, 'selected_php_version' => $candidate->selected_php_version,
                'clone_candidate_id' => $candidate->id, 'clone_requested_branch' => $data->branch,
                'clone_preview_name' => $data->previewName, 'clone_preview_domain' => '',
                'source_prepare_id' => (string) Str::uuid(), 'clone_sqlite_source_path' => null, 'provisioning_step' => 'clone-reserved']);
            if ($target->clone_completed_at === null) {
                $contexts = app(InstanceEnvironmentContextResolver::class);
                app(InstanceEnvironmentStore::class)->copyForClone($contexts->resolve($candidate, true), $contexts->resolveForClone($target, true));
            }
            $input = ['instance_id' => $target->id, 'project_id' => $candidate->project_id, 'candidate_id' => $candidate->id,
                'repository' => $candidate->project->repository_url, 'repository_identity' => $candidate->project->repository_identity,
                'branch' => $branch, 'checkout' => $path, 'candidate' => ['node_id' => $candidate->node_id,
                    'checkout' => $candidate->checkout_path, 'branch' => $candidate->branch,
                    'repository_identity' => $candidate->project->repository_identity],
                'source_root' => $root->value, 'initial_completed' => $target->clone_completed_at !== null];
            $input['direct_provisioning'] = app(NativeInstanceProvisioningIntent::class)->clone($target, $input);

            return $this->operations->execute('instance.clone', 'instance', $target->id, $node->id, $input, $caller, $requestId);
        });
    }

    private function conflict(string $code, string $message): ResourceOperationException
    {
        return new ResourceOperationException($code, $message, 409);
    }
}
