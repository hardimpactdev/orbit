<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Gateway state projection only. Swamp owns every effect and its ordering. */
final readonly class InstanceLifecycleState
{
    public function __construct(
        private ComposerSourceClassifier $classifier,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    public function instance(Operation $operation): Instance
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $nodeId = $operation->node_id;
        $checkout = $operation->input['checkout'] ?? null;
        if (in_array($operation->kind, ['instance.transfer', 'instance.transfer_destination'], true)) {
            $transfer = app(InstanceTransferState::class)->transfer($operation);
            $nodeId = $transfer->cutover_at === null ? $transfer->source_node_id : $transfer->destination_node_id;
            $checkout = $transfer->cutover_at === null ? $transfer->source_path : $transfer->destination_path;
        }
        if ($instance->node_id !== $nodeId
            || $checkout !== null && $instance->checkout_path !== $checkout
            || isset($operation->input['project_id']) && $instance->project_id !== $operation->input['project_id']
            || isset($operation->input['repository_identity']) && $instance->project->repository_identity !== $operation->input['repository_identity']
            || $operation->kind === 'instance.clone' && ($instance->clone_candidate_id !== $operation->input['candidate_id']
                || $instance->production_user !== $operation->input['production_user']
                || $instance->production_home !== $operation->input['production_home'])) {
            throw new ResourceOperationException('instance.lifecycle_conflict', 'The admitted Instance placement or repository changed.', 409);
        }

        return $instance;
    }

    /** @param array<string, mixed> $result */
    public function prepared(Operation $operation, array $result): void
    {
        if (! is_bool($result['created'] ?? null)) {
            throw $this->invalidSource();
        }
        $instance = $this->instance($operation);
        if ($instance->status === InstanceState::Reserved) {
            $instance->update(['status' => InstanceState::CheckoutPrepared,
                'source_layout' => ($operation->input['seed_commit'] ?? null) !== null ? InstanceSourceLayout::Worktree : $instance->source_layout]);
        }
    }

    /** @param array<string, mixed> $result */
    public function resolved(Operation $operation, array $result): void
    {
        $instance = $this->instance($operation);
        $expected = $this->expectedBranch($operation, $instance);
        $branch = $result['branch'] ?? null;
        $commit = $result['commit'] ?? null;
        if ($branch !== $expected || ! is_string($commit) || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1) {
            throw $this->invalidSource();
        }
        if ($instance->status !== InstanceState::CheckoutPrepared) {
            if ($instance->branch !== $branch || $instance->starting_commit !== $commit) {
                throw new ResourceOperationException('instance.source_identity_changed', 'Instance source identity changed.', 409);
            }

            return;
        }
        $instance->update(['branch' => $branch, 'starting_commit' => $commit, 'status' => InstanceState::SourceResolved]);
    }

    /** @return array{source_verified: true, branch: string} */
    public function retainedSource(Operation $operation): array
    {
        $instance = $this->instance($operation);
        if ($instance->status !== InstanceState::Active || $instance->branch !== $this->expectedBranch($operation, $instance)
            || ! is_string($instance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $instance->starting_commit) !== 1) {
            throw new ResourceOperationException('instance.source_identity_changed', 'Instance source identity changed.', 409);
        }

        return ['source_verified' => true, 'branch' => $instance->branch];
    }

    private function expectedBranch(Operation $operation, Instance $instance): string
    {
        if ($operation->kind === 'instance.clone') {
            return ValidatedData::string($operation->input['branch']);
        }
        $name = $operation->input['name'] ?? $instance->name;

        return ValidatedData::string($operation->input['branch_override'] ?? ($name === 'default' ? $operation->input['default_branch'] : $name));
    }

    /**
     * Composer bytes are consumed here. Only classified scalar evidence can leave the Gateway.
     *
     * @param  array<string, mixed>  $result
     * @return array{php_version: string|null, laravel: bool}
     */
    public function classified(Operation $operation, array $result): array
    {
        $instance = $this->instance($operation);
        if (($result['kind'] ?? null) === 'none') {
            $profile = new DevelopmentSourceProfile(null, false);
        } else {
            $json = is_string($result['composer'] ?? null) ? base64_decode($result['composer'], true) : false;
            if (($result['kind'] ?? null) !== 'composer' || ! is_string($json) || ! is_string($result['entry_point'] ?? null)) {
                throw new ResourceOperationException('app-dev.source_metadata_unsafe', 'The development source metadata is invalid.', 409);
            }
            $profile = $this->classifier->classify($json, $instance->project->type, $result['entry_point']);
        }
        if ($instance->provisioning_step !== null && $instance->provisioning_step !== 'clone-source-resolved' && ($instance->source_is_laravel === null
            || $instance->selected_php_version !== $profile->phpVersion || $instance->source_is_laravel !== $profile->laravel)) {
            throw new ResourceOperationException('app-dev.source_evidence_changed', 'The development source classification changed after provisioning began.', 409);
        }
        if ($instance->provisioning_step === null || $instance->provisioning_step === 'clone-source-resolved') {
            $runtime = $operation->kind === 'instance.clone' && $profile->phpVersion !== null && $instance->servesPhp()
                ? ProductionPhpRuntimeIdentity::forProvisioning($instance, $profile->phpVersion)->attributes() : [];
            $instance->update(['selected_php_version' => $profile->phpVersion, 'source_is_laravel' => $profile->laravel,
                ...$runtime, 'provisioning_step' => $operation->kind === 'instance.clone' ? 'clone-source-classified' : 'php-selected', 'failed_step' => null, 'error_code' => null]);
        }

        return ['php_version' => $profile->phpVersion, 'laravel' => $profile->laravel];
    }

    public function activated(Operation $operation, bool $setupPending): void
    {
        $instance = $this->instance($operation);
        DB::transaction(static function () use ($instance, $setupPending): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $locked->update(['status' => InstanceState::Active, 'provisioning_step' => 'active',
                'failed_step' => $setupPending ? 'setup' : null, 'error_code' => null]);
        });
    }

    /** @param array<string, mixed> $source */
    public function renamed(Operation $operation, array $source): void
    {
        $instance = $this->instance($operation);
        if (($source['source_verified'] ?? false) !== true) {
            throw $this->invalidSource();
        }
        $branch = $operation->input['branch'] ?? null;
        if ($branch !== null && ($source['branch'] ?? null) !== $branch) {
            throw new ResourceOperationException('instance.branch_not_checked_out', 'HEAD is not on the requested branch.', 409);
        }
        if (($domain = $operation->input['domain'] ?? null) !== null) {
            $route = $instance->routes()->where('domain', $domain)->where('status', RouteStatus::Active)->first();
            if ($route === null) {
                throw new ResourceOperationException('operation.completion_unproven', 'The requested Route change is not complete.', 409);
            }
            if ($instance->source_is_laravel === true) {
                InstanceEnvironmentValue::query()->updateOrCreate(['instance_id' => $instance->id, 'env_key' => 'APP_URL'], ['env_value' => 'https://'.$route->domain]);
            }
        }
        if ($branch !== null && $instance->branch !== $branch) {
            $instance->update(['branch' => $branch, 'branch_override' => $branch]);
        }
        $this->broadcaster->broadcast(RecordEventType::InstanceUpdated, $instance->id,
            InstanceData::fromModel($instance->refresh()->load(['project', 'node', 'routes.targets']))->toArray());
    }

    private function invalidSource(): ResourceOperationException
    {
        return new ResourceOperationException('instance.source_identity_invalid', 'The checkout does not match the admitted source identity.', 409);
    }
}
