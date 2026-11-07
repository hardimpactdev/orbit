<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-vm-image-refresh` run once. When the run promoted an image, that image becomes
 * the host's latest promoted build; its seed needs no record, because its name follows the fingerprint. A failed run records no
 * image, so the host keeps the one it had.
 */
final readonly class TaskVmImageRefreshProjection implements DirectOperationProjection
{
    /** The last step: the promotion, whose child reads the host's aliases after it. */
    public const string EndCheck = 'Promote';

    /** @return array<string,array{job:string,step:string}> */
    public static function captures(): array
    {
        return ['promote' => ['job' => 'refresh', 'step' => self::EndCheck]];
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return ['intent' => ValidatedData::object($input['intent']), 'execution_secrets' => ValidatedData::object($input['execution_secrets'])];
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
            $code = in_array($status, ['failed', 'interrupted'], true) ? 'task_vm.image_refresh_failed' : null;
            $result = ['error_code' => $code];
            if ($status === 'succeeded') {
                $result = $this->record($operation, $this->fingerprint($operation, $native));
            }
            $data['result'] = $result;
            $data['error_code'] = $code;

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    /**
     * The image the host's alias names after the promotion, from the alias list the promotion printed.
     *
     * @param  array<string,mixed>  $native
     */
    public function fingerprint(Operation $operation, array $native): string
    {
        $alias = ValidatedData::string(ValidatedData::object(ValidatedData::object(ValidatedData::object($operation->input)['intent'])['host'])['alias']);
        $read = ValidatedData::object(ValidatedData::object($native['promote'] ?? [])['Read the aliases'] ?? []);
        $aliases = json_decode(is_string($read['stdout'] ?? null) ? $read['stdout'] : '', true);
        foreach (is_array($aliases) ? $aliases : [] as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $alias && is_string($entry['target'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $entry['target']) === 1) {
                return $entry['target'];
            }
        }

        throw new ResourceOperationException('operation.result_invalid', 'A succeeded refresh needs the fingerprint of the image it promoted.', 422);
    }

    /**
     * The promoted image as the host's latest build. Its recipe identity is the one of the release the run converged to, so a claim finds
     * it as the current image.
     *
     * @return array<string,mixed>
     */
    private function record(Operation $operation, string $fingerprint): array
    {
        $input = ValidatedData::object($operation->input);
        $host = ValidatedData::object(ValidatedData::object($input['intent'])['host']);
        $build = new TaskVmImageBuild;
        $build->forceFill(['host_node_id' => ValidatedData::integer($host['node_id']), 'recipe_identity' => ValidatedData::string($input['recipe_identity']),
            'images' => ['base' => ['project' => ValidatedData::string($host['project']), 'alias' => ValidatedData::string($host['alias']), 'fingerprint' => $fingerprint, 'type' => 'virtual-machine']],
            'image_fingerprint' => $fingerprint, 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(), 'steps' => []])->save();

        return ['refreshed' => true, 'host_node_id' => $build->host_node_id, 'image_fingerprint' => $fingerprint,
            'previous_fingerprint' => ValidatedData::string($host['current']), 'seed' => TaskVmVolumes::seed($fingerprint), 'build_id' => $build->id];
    }
}
