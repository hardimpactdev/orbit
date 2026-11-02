<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentInvocation;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The full public template intent at the run start and one terminal projection from Swamp's run data; no UpCloud or guest calls. */
final readonly class TaskVmUpCloudImageProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** The end check: after a successful build, the listed promoted template is the one templatize made. */
    public const string EndCheck = 'Check the template';

    /** Parent steps whose failure is an UpCloud request that failed or did not answer. */
    private const array ProviderSteps = ['Make the template', 'Promote the template', 'List servers, templates'];

    /** @return array<string,array{job:string,step:string,field:string}> */
    public static function captures(): array
    {
        return ['template_uuid' => ['job' => 'build', 'step' => 'Read the template', 'field' => 'template_uuid']];
    }

    /** Public intent only; the run start adds the vault copies of the token and the host key. */
    public function input(Operation $operation): array
    {
        $build = $this->build($operation);
        $stored = Arr::except($operation->input, ['caller_node_id']);
        $pin = ValidatedData::string($stored['agent_sha256']);
        $identity = ValidatedData::string($stored['identity']);
        $stamp = ValidatedData::string($stored['stamp']);
        $recipe = ValidatedData::object(json_decode(ValidatedData::string($stored['recipe']), true, flags: JSON_THROW_ON_ERROR));
        $ssh = app(SwampSshExecutionInput::class);
        $key = static fn (string $phase): array => ['identity' => $identity, 'profile' => 'base', 'phase' => $phase, 'execution' => 'upcloud-'.$build->id];
        $candidate = '/var/tmp/orbit-agent-candidate-'.$pin;
        $envelope = static fn (int $seconds, array $input): string => AgentInvocation::envelope($pin, $seconds, $input);
        $gateway = Node::query()->findOrFail($operation->node_id);
        $intent = [...Arr::except($stored, ['recipe', 'host_key']),
            'recipe' => $recipe,
            'host_key' => $this->bootstrap($build)['public'],
            'ssh' => Arr::only($ssh->identity(), ['identity_file', 'public_key']),
            // The Pi build lives in the Gateway home; 209 fetches it with the same pinned SSH as every other step.
            'pi_source' => $ssh->forNode($gateway),
            'pinned_templates' => TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)
                ->whereNull('provider_id')->whereNotNull('image_fingerprint')->pluck('image_fingerprint')->all(),
            'candidate' => $candidate,
            'envelopes' => [
                'install' => $envelope(180, ['candidate' => $candidate, 'sha256' => $pin]),
                'cloud_init' => $envelope(45, []),
                'owner' => $envelope(120, []),
                'inspect_boot' => $envelope(30, ['key' => $key(TaskVmImageProjection::RecipePhases[0]), 'boot_id' => null]),
                'marker' => $envelope(120, ['identity' => $identity, 'profile' => 'base']),
                'verify' => $envelope(120, $recipe),
                'boot' => $envelope(120, ['lean' => false]),
                'firewall' => $envelope(120, []),
                'clean' => $envelope(120, []),
                'check' => $envelope(120, ['lean_boot' => false, 'firewall' => true]),
            ],
            'phase_keys' => array_combine(TaskVmImageProjection::RecipePhases, array_map($key, TaskVmImageProjection::RecipePhases)),
            // The agent names each phase's retained unit after the first 32 hex of its key's SHA-256.
            'units' => array_combine(TaskVmImageProjection::RecipePhases, array_map(static fn (string $phase): string => 'orbit-recipe-image-'
                .substr(hash('sha256', implode(':', $key($phase))), 0, 32).'.service', TaskVmImageProjection::RecipePhases)),
            'template_data' => json_encode(['storage' => ['title' => 'orbit-task-base-'.$stamp.'-unverified']], JSON_THROW_ON_ERROR),
            'promote_data' => json_encode(['storage' => ['title' => 'orbit-task-base-'.$stamp, 'labels' => [
                ['key' => 'orbit.recipe', 'value' => $identity], ['key' => 'orbit-task-image', 'value' => $stamp]]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];

        return ['intent' => $intent, 'execution_secrets' => []];
    }

    /** The run start refuses a superseded attempt before any vault copy or UpCloud request. */
    public function workflowInput(Operation $operation): array
    {
        $build = $this->build($operation);
        if (! $this->current($operation, $build)) {
            throw new ResourceOperationException('task_vm.image_build_superseded', 'The desired image recipe or serving delivery changed.', 409);
        }
        $steps = app(SwampCommandStep::class);

        return [...$this->input($operation), 'execution_secrets' => [
            'upcloud' => $steps->executionCopy('orbit-control-inputs', 'task-vm-upcloud-token', app(UpCloudApi::class)->token()),
            'bootstrap' => $steps->executionCopy('orbit-control-inputs', 'task-vm-image-bootstrap-'.$build->id, $this->bootstrap($build)['private']),
        ]];
    }

    private function build(Operation $operation): UpCloudTaskVmImageBuild
    {
        $build = UpCloudTaskVmImageBuild::query()->findOrFail($operation->target_id);
        if ($operation->kind !== 'task_vm.upcloud_image_build' || $operation->target_type !== 'task_vm_upcloud_image'
            || $build->intent === null || ($build->intent['build_id'] ?? null) !== $build->id
            || ($build->intent['node_id'] ?? null) !== $operation->node_id
            || $build->recipe_identity !== ($build->intent['identity'] ?? null)
            || Arr::except($operation->input, ['caller_node_id']) !== $build->intent) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted UpCloud attempt no longer owns this build.', 422);
        }

        return $build;
    }

    /** @return array{private:string,public:string} */
    private function bootstrap(UpCloudTaskVmImageBuild $build): array
    {
        $pair = $build->bootstrap;
        if ($pair === null || $pair['public'] !== ($build->intent['host_key'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The admitted UpCloud attempt has no matching host key.', 422);
        }

        return $pair;
    }

    private function current(Operation $operation, UpCloudTaskVmImageBuild $build): bool
    {
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();

        return $desired->identity === $build->recipe_identity && (int) $desired->generation === $operation->input['generation']
            && app(AgentDelivery::class)->pin(Node::query()->findOrFail($operation->node_id)) === $operation->input['agent_sha256'];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $this->build($operation);
            $status = OperationStatus::from(ValidatedData::string($data['status']));
            if (! $status->terminal()) {
                throw new ResourceOperationException('operation.result_invalid', 'The image result needs a terminal native outcome.', 422);
            }
            $native = ValidatedData::object($data['result'] ?? []);
            $template = $native['template_uuid'] ?? null;
            $template = is_string($template) && Str::isUuid($template) ? $template : null;
            if ($status === OperationStatus::Succeeded && ($template === null || NativeRunResult::stepStatus($native, self::EndCheck) !== 'succeeded')) {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded image run needs its checked, promoted template.', 422);
            }
            $code = in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true) ? $this->errorCode($data['failure'] ?? null) : null;
            $result = match (true) {
                $code !== null => ['error_code' => $code],
                $status === OperationStatus::Succeeded => ['ready' => true, 'template_uuid' => $template],
                default => [],
            };
            $projected = app(DirectOperation::class)->project($operation, [...$data, 'error_code' => $code], $result);
            if ($projected->status->terminal()) {
                $this->finish($projected, $projected->status === OperationStatus::Succeeded ? $template : null, $code);
            }

            return $projected;
        });
    }

    /** A refused agent reply keeps its own public code; other failures take their step's code. */
    private function errorCode(mixed $failure): string
    {
        $step = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step'] : '';
        $detail = is_array($failure) && is_string($failure['detail'] ?? null) ? trim($failure['detail']) : '';
        if (preg_match('/\A[a-z0-9_]+\.[a-z0-9_.-]{1,80}\z/D', $detail) === 1) {
            return $detail;
        }

        return in_array($step, self::ProviderSteps, true) ? 'task_vm.upcloud_failed' : 'task_vm.image_build_failed';
    }

    /** Records the attempt's outcome once; a later correction to success replaces a failure. */
    private function finish(Operation $operation, ?string $template, ?string $errorCode): void
    {
        $succeeded = $operation->status === OperationStatus::Succeeded;
        UpCloudTaskVmImageBuild::query()->whereKey($operation->target_id)
            ->whereIn('state', $succeeded ? ['running', 'failed'] : ['running'])->update([
                'state' => $succeeded ? 'succeeded' : 'failed', 'finished_at' => now(), 'template_uuid' => $template,
                'failure' => $succeeded ? null : json_encode(['phase' => 'operation', 'exit_code' => null, 'stderr' => $errorCode ?? 'operation.cancelled'], JSON_THROW_ON_ERROR),
            ]);
    }
}
