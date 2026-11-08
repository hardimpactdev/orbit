<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;
use UnexpectedValueException;

/** One closed object: a new name, a platform, the apps folder, or the task VM host settings; at least one. */
final class UpdateNodeRequest extends FormRequest
{
    private const array MEMBERS = ['name', 'platform', 'apps', 'task_vms'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $node = $this->route('node');

        return [
            'name' => ['sometimes', 'string', 'alpha_dash:ascii', 'max:63', Rule::unique('nodes', 'name')->ignore($node instanceof Node ? $node->id : null)],
            'platform' => ['sometimes', 'string', Rule::in(['linux', 'macos', 'ipados'])],
            'apps' => ['sometimes', 'nullable', 'array:path'],
            'apps.path' => ['sometimes', 'nullable', 'string'],
            'task_vms' => ['sometimes', 'nullable', 'array:cidr,max_vms,project,network,pool,pool_size,image_alias,zfs_dataset,cpus,memory,disk,operator_owned,slots,ci_only,task_max_vms'],
            'task_vms.task_max_vms' => ['sometimes', 'nullable', 'integer:strict', 'between:0,64'],
            'task_vms.ci_only' => ['sometimes', 'boolean:strict'],
            'task_vms.cidr' => ['required_with:task_vms', 'string'],
            'task_vms.max_vms' => ['required_with:task_vms', 'integer:strict', 'between:1,64'],
            'task_vms.project' => ['sometimes', 'string'],
            'task_vms.network' => ['sometimes', 'string'],
            'task_vms.pool' => ['sometimes', 'string'],
            'task_vms.pool_size' => ['sometimes', 'string'],
            'task_vms.image_alias' => ['sometimes', 'string'],
            'task_vms.zfs_dataset' => ['sometimes', 'nullable', 'string'],
            'task_vms.cpus' => ['sometimes', 'integer:strict', 'between:1,64'],
            'task_vms.memory' => ['sometimes', 'string'],
            'task_vms.disk' => ['sometimes', 'string'],
            'task_vms.operator_owned' => ['sometimes', 'list'],
            'task_vms.operator_owned.*' => ['string'],
            'task_vms.slots' => ['sometimes', 'nullable', 'array:count,project_id,size'],
            'task_vms.slots.count' => ['required_with:task_vms.slots', 'integer:strict', 'between:1,16'],
            'task_vms.slots.project_id' => ['required_with:task_vms.slots', 'integer:strict', 'min:1'],
            'task_vms.slots.size' => ['required_with:task_vms.slots', 'string', 'in:normal,medium,large'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $body = $this->body();
        $patch = $this->settings();

        return [
            ...array_intersect_key($body, array_flip(['name', 'platform'])),
            ...($patch->hasApps ? ['apps' => $patch->apps === null ? null : ['path' => $patch->apps->path]] : []),
            ...($patch->hasTaskVms ? ['task_vms' => $patch->taskVms?->toArray()] : []),
        ];
    }

    public function name(): ?string
    {
        $name = $this->validated()['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    public function platform(): ?string
    {
        $platform = $this->validated()['platform'] ?? null;

        return is_string($platform) ? $platform : null;
    }

    /** The apps and task_vms members; the settings parser owns their shape and the `node.settings_*` codes. */
    public function settings(): NodeSettingsPatch
    {
        $decoded = json_decode($this->getContent(), associative: false);
        $settings = new stdClass;
        foreach (['apps', 'task_vms'] as $member) {
            if ($decoded instanceof stdClass && property_exists($decoded, $member)) {
                $settings->{$member} = $decoded->{$member};
            }
        }

        return get_object_vars($settings) === [] ? new NodeSettingsPatch(false, null) : new NodeSettingsParser()->parsePatch($settings);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        try {
            $body = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), self::MEMBERS);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
        if ($body === []) {
            throw ValidationException::withMessages(['body' => ['The request body must contain at least one of name, platform, apps, or task_vms.']]);
        }

        return $body;
    }
}
