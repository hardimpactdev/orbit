<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Foundation\Http\FormRequest;
use JsonException;

final class UpdateNodeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'apps' => ['sometimes', 'nullable', 'array:path'],
            'apps.path' => ['sometimes', 'nullable', 'string'],
            'task_vms' => ['sometimes', 'nullable', 'array:cidr,max_vms,project,network,pool,image_alias,zfs_dataset,cpus,memory,disk,operator_owned'],
            'task_vms.cidr' => ['required_with:task_vms', 'string'],
            'task_vms.max_vms' => ['required_with:task_vms', 'integer:strict', 'between:1,64'],
            'task_vms.project' => ['sometimes', 'string'],
            'task_vms.network' => ['sometimes', 'string'],
            'task_vms.pool' => ['sometimes', 'string'],
            'task_vms.image_alias' => ['sometimes', 'string'],
            'task_vms.zfs_dataset' => ['sometimes', 'nullable', 'string'],
            'task_vms.cpus' => ['sometimes', 'integer:strict', 'between:1,64'],
            'task_vms.memory' => ['sometimes', 'string'],
            'task_vms.disk' => ['sometimes', 'string'],
            'task_vms.operator_owned' => ['sometimes', 'list'],
            'task_vms.operator_owned.*' => ['string'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $patch = $this->payload();

        return [
            ...($patch->hasApps ? ['apps' => $patch->apps === null ? null : ['path' => $patch->apps->path]] : []),
            ...($patch->hasTaskVms ? ['task_vms' => $patch->taskVms?->toArray()] : []),
        ];
    }

    public function payload(): NodeSettingsPatch
    {
        try {
            $decoded = json_decode($this->getContent(), associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ResourceOperationException(
                errorCode: 'node.settings_invalid',
                message: 'The settings object is invalid.',
            );
        }

        return new NodeSettingsParser()->parsePatch($decoded);
    }
}
