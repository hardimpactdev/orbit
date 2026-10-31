<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ProjectRoot;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Records registration intent. Source inspection and adoption run on the admitted Node. */
final readonly class RegisterInstanceAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private ManagedCheckoutOverlap $overlap,
    ) {}

    public function execute(Node $caller, RegisterInstanceData $data, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.register')) !== null) {
            return $replay;
        }
        if ($caller->status !== LifecycleStatus::Active || $caller->platform !== 'linux'
            || ! $caller->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('instance.caller_not_app_dev', 'Registration requires an active app-dev caller Node.', 422);
        }
        if ($data->projectId === null || $data->includeWorktrees || $data->runSetup || $data->domain !== null) {
            throw $this->unavailable();
        }
        $project = Project::query()->findOrFail($data->projectId);
        if ($project->type->isWebServing()) {
            throw $this->unavailable();
        }
        $name = DefaultInstanceName::resolve($project, $data->instanceName ?? basename($data->sourcePath));
        if (strlen($name) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1) {
            throw new ResourceOperationException('instance.name_invalid', 'The Instance name must be a lowercase DNS label.', 422);
        }
        $root = $data->root ?? $project->root;
        if (! is_string($root) || ! ProjectRoot::isValid($root, $project->type)) {
            throw new ResourceOperationException('project.root_invalid', 'The Project root is invalid.', 422);
        }
        $apps = app(NodeSettingsNormalizer::class)->fromStored($caller->settings)?->appsPath();
        if (! is_string($apps) || StoragePath::tryParse($apps) === null
            || $data->sourcePath !== $apps.'/'.$project->slug.'/'.$name) {
            throw $this->unavailable();
        }
        if ($caller->user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $caller->user) !== 1) {
            throw new ResourceOperationException('instance.source_owner_invalid', 'Registration requires the configured Node account.', 409);
        }
        app(DevelopmentNodeExclusion::class)->assertAvailable($project, $caller);

        return DB::transaction(function () use ($caller, $project, $name, $root, $data, $requestId): Operation {
            $instance = Instance::query()->where('node_id', $caller->id)->where('checkout_path', $data->sourcePath)->first();
            if ($instance !== null && ($instance->project_id !== $project->id || $instance->name !== $name
                || $instance->registration_request_id === null || $instance->routes()->exists()
                || ($instance->root ?? $project->root) !== $root
                || ! in_array($instance->status, [InstanceState::Reserved, InstanceState::Active], true))) {
                throw new ResourceOperationException('instance.registration_conflict', 'The checkout already belongs to another Instance intent.', 409);
            }
            $this->overlap->assertAvailable($caller->id, StoragePath::parse($data->sourcePath), 'instance.source_conflict', ignoreInstanceId: $instance?->id);
            $instance ??= Instance::query()->create([
                'project_id' => $project->id, 'node_id' => $caller->id, 'name' => $name,
                'source_layout' => 'checkout', 'checkout_path' => $data->sourcePath,
                'root' => $root !== $project->root ? $root : null, 'status' => InstanceState::Reserved,
                'registration_request_id' => (string) Str::uuid(), 'registration_primary' => true,
                'registration_include_worktrees' => false, 'registration_original_path' => $data->sourcePath,
                'registration_authoritative_path' => $data->sourcePath,
            ]);

            return $this->operations->execute('instance.register', 'instance', $instance->id, $caller->id, [
                'instance_id' => $instance->id, 'project_id' => $project->id, 'node_id' => $caller->id,
                'repository' => $project->repository_url, 'repository_identity' => $project->repository_identity,
                'checkout' => $instance->checkout_path, 'application' => $instance->applicationDirectory(),
                'user' => $caller->user, 'project_type' => $project->type->value,
                'php_versions' => app(InstancePhpVersionCatalog::class)->versions(),
                'registration_id' => $instance->registration_request_id,
                'source_digest' => $instance->registration_source_digest,
            ], 'operator', $requestId);
        });
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('operation.kind_unavailable', 'Native registration currently requires a named Project, an in-place checkout under its configured apps path, no Route, no worktrees and no setup.', 503);
    }
}
