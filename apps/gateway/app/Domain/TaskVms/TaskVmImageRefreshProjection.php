<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskPrepResults;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-vm-image-refresh` run once: the refresh VM and its workspace as provision
 * records them, and, when the run promoted a new image, that image as the host's latest promoted build. The seed needs no
 * record: its name follows the fingerprint of the image. The group of the run is cancelled after the commit, which destroys
 * the refresh VM whatever the result was. A failed run records no image, so the host keeps the image and seed it had.
 */
final readonly class TaskVmImageRefreshProjection implements DirectOperationProjection
{
    /** The end check: a native assert over the clean, the publication, the boot check and the promotion. */
    public const string EndCheck = 'Check the refresh';

    /** The step that prints the full fingerprint of the published image. */
    public const string FingerprintStep = 'Read the fingerprint';

    /** @return array<string,array{job:string,step:string}> */
    public static function captures(): array
    {
        return ['provision' => ['job' => 'refresh', 'step' => 'Create the VM'], 'fingerprint' => ['job' => 'refresh', 'step' => self::FingerprintStep]];
    }

    public function __construct(private TaskProvisionProjection $provision) {}

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $provision = $this->provision->input(self::provisionView($operation));
        $intent = ValidatedData::object($operation->input);

        // The converge intent of the provision is an input of its own, as `tasks.provision` passes it.
        return ['intent' => ['provision' => $provision['intent'], 'refresh' => $intent['refresh']], ...(is_array($provision['converge'] ?? null) ? ['converge' => $provision['converge']] : []),
            'execution_secrets' => $provision['execution_secrets']];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $native = ValidatedData::object($data['result'] ?? []);
            $status = ValidatedData::string($data['status']);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $receipt = TaskPrepResults::receipt($native['provision'] ?? null);
            $failed = in_array($status, ['failed', 'interrupted'], true);
            $code = $failed ? 'task_vm.image_refresh_failed' : null;
            $fingerprint = $status === 'succeeded' ? $this->fingerprint($native) : null;
            if ($status === 'succeeded' && ($receipt === null || NativeRunResult::stepStatus($native, self::EndCheck) !== 'succeeded')) {
                throw new ResourceOperationException('operation.result_invalid', 'A succeeded refresh needs its ready VM and its passed end check.', 422);
            }
            // The VM and the workspace of the group, from the same receipt provision records. A run that never got a VM ready records the failure.
            $this->provision->record(self::provisionView($operation), ['status' => $receipt !== null ? 'succeeded' : ($status === 'cancelled' ? 'cancelled' : 'failed'),
                'error_code' => $receipt !== null ? null : 'tasks.provision_failed'], $receipt ?? ['failed_step' => 'provision'], static fn (): Operation => $operation);

            $result = ['error_code' => $code];
            if ($fingerprint !== null) {
                $result = $this->record($operation, $fingerprint);
                // Ready slots of the older image are replaced one at a time (docs/cli/tasks/vm-slots.mmd).
                RefillTaskVmSlots::dispatchFor(ValidatedData::integer($result['host_node_id']));
            }
            $data['result'] = $result;
            $data['error_code'] = $code;
            DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id, 'image-refresh'));

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    /** @param array<string,mixed> $native */
    private function fingerprint(array $native): string
    {
        $step = ValidatedData::object($native['fingerprint'] ?? []);
        $line = trim(explode("\n", ValidatedData::string($step['stdout'] ?? ''))[0]);
        if (preg_match('/\A[a-f0-9]{64}\z/D', $line) !== 1) {
            throw new ResourceOperationException('operation.result_invalid', 'A succeeded refresh needs the fingerprint of the image it promoted.', 422);
        }

        return $line;
    }

    /**
     * The promoted image as the host's latest build. Its recipe identity is the one of the release the run converged to,
     * so a claim finds it as the current image.
     *
     * @return array<string,mixed>
     */
    private function record(Operation $operation, string $fingerprint): array
    {
        $refresh = ValidatedData::object(ValidatedData::object($operation->input)['refresh']);
        $names = ValidatedData::object($refresh['names']);
        $vm = ValidatedData::object($refresh['vm']);
        $build = new TaskVmImageBuild;
        $build->forceFill(['host_node_id' => ValidatedData::integer($refresh['host_node_id']), 'recipe_identity' => ValidatedData::string($refresh['recipe_identity']),
            'images' => ['base' => ['project' => ValidatedData::string($vm['project']), 'alias' => ValidatedData::string($names['alias']), 'fingerprint' => $fingerprint, 'type' => 'virtual-machine']],
            'image_fingerprint' => $fingerprint, 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(), 'steps' => []])->save();

        return ['refreshed' => true, 'host_node_id' => $build->host_node_id, 'image_fingerprint' => $fingerprint,
            'previous_fingerprint' => ValidatedData::string(ValidatedData::object($refresh['image'])['fingerprint']),
            'seed' => TaskVmVolumes::seed($fingerprint), 'build_id' => $build->id];
    }

    /** The provision of one refresh run as the Operation of its own kind: the provision intent as input, so the provision records apply to it unchanged. */
    private static function provisionView(Operation $operation): Operation
    {
        $view = $operation->replicate();
        $view->id = $operation->id;
        $view->kind = 'tasks.provision';
        $intent = ValidatedData::object($operation->input);
        $view->input = [...ValidatedData::object($intent['provision']), 'execution_secrets' => $intent['execution_secrets']];

        return $view;
    }
}
