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

/** One closed object: a new name, a platform, the LAN address, reboot hold, the apps folder, or the task VM host settings; at least one. */
final class UpdateNodeRequest extends FormRequest
{
    private const array MEMBERS = ['name', 'platform', 'lan_ip', 'reboot_hold', 'apps', 'task_vms'];

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
            'lan_ip' => ['sometimes', 'nullable', 'ipv4'],
            'reboot_hold' => ['sometimes', 'boolean:strict'],
            'apps' => ['sometimes', 'nullable', 'array:path'],
            'apps.path' => ['sometimes', 'nullable', 'string'],
            'task_vms' => ['sometimes', 'nullable', 'array:cidr,task_places,project,network,pool,pool_size,image_alias,zfs_dataset,cpus,memory,disk,operator_owned,slots,ci_places,containers'],
            'task_vms.ci_places' => ['required_with:task_vms', 'integer:strict', 'between:0,64'],
            'task_vms.cidr' => ['required_with:task_vms', 'string'],
            'task_vms.task_places' => ['required_with:task_vms', 'integer:strict', 'between:0,64'],
            'task_vms.project' => ['sometimes', 'string'],
            'task_vms.network' => ['sometimes', 'string'],
            'task_vms.pool' => ['sometimes', 'string'],
            'task_vms.pool_size' => ['sometimes', 'string'],
            'task_vms.image_alias' => ['sometimes', 'string'],
            'task_vms.zfs_dataset' => ['sometimes', 'nullable', 'string'],
            'task_vms.cpus' => ['sometimes', 'integer:strict', 'between:0,64'],
            'task_vms.memory' => ['sometimes', 'string'],
            'task_vms.disk' => ['sometimes', 'string'],
            'task_vms.operator_owned' => ['sometimes', 'list'],
            'task_vms.operator_owned.*' => ['string'],
            'task_vms.slots' => ['sometimes', 'nullable', 'array:count,project_id,size'],
            'task_vms.slots.count' => ['required_with:task_vms.slots', 'integer:strict', 'between:1,16'],
            'task_vms.slots.project_id' => ['required_with:task_vms.slots', 'integer:strict', 'min:1'],
            'task_vms.slots.size' => ['required_with:task_vms.slots', 'string', 'in:normal,medium,large'],
            'task_vms.containers' => ['sometimes', 'array:cidr,task_places,ci_places,project,network'],
            'task_vms.containers.cidr' => ['required_with:task_vms.containers', 'string'],
            'task_vms.containers.task_places' => ['required_with:task_vms.containers', 'integer:strict', 'between:0,64'],
            'task_vms.containers.ci_places' => ['required_with:task_vms.containers', 'integer:strict', 'between:0,64'],
            'task_vms.containers.project' => ['sometimes', 'string'],
            'task_vms.containers.network' => ['sometimes', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $body = $this->body();
        $patch = $this->settings();

        return [
            ...array_intersect_key($body, array_flip(['name', 'platform', 'lan_ip', 'reboot_hold'])),
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

    /** Whether the body names `lan_ip`; null clears the address. */
    public function hasLanIp(): bool
    {
        return array_key_exists('lan_ip', $this->validated());
    }

    public function lanIp(): ?string
    {
        $address = $this->validated()['lan_ip'] ?? null;

        return is_string($address) ? $address : null;
    }

    public function rebootHold(): ?bool
    {
        $hold = $this->validated()['reboot_hold'] ?? null;

        return is_bool($hold) ? $hold : null;
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
            throw ValidationException::withMessages(['body' => ['The request body must contain at least one of name, platform, lan_ip, reboot_hold, apps, or task_vms.']]);
        }

        return $body;
    }
}
