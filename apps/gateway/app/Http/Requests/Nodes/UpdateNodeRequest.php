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

/** One closed object: a new name, a platform, the LAN address, reboot hold, the apps folder, nested-fleet hosting, the Instance host opt-in, or a CI Node's CI places; at least one. */
final class UpdateNodeRequest extends FormRequest
{
    private const array MEMBERS = ['name', 'platform', 'lan_ip', 'reboot_hold', 'apps', 'nested_fleet', 'instance_host', 'ci'];

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
            'nested_fleet' => ['sometimes', 'boolean:strict'],
            'instance_host' => ['sometimes', 'boolean:strict'],
            'apps' => ['sometimes', 'nullable', 'array:path'],
            'apps.path' => ['sometimes', 'nullable', 'string'],
            'ci' => ['sometimes', 'nullable', 'array:places,proof_only'],
            'ci.places' => ['sometimes', 'integer:strict', 'between:0,64'],
            'ci.proof_only' => ['sometimes', 'boolean:strict'],
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
            ...($patch->hasNestedFleet ? ['nested_fleet' => $patch->nestedFleet] : []),
            ...($patch->hasInstanceHost ? ['instance_host' => $patch->instanceHost] : []),
            ...($patch->hasCi ? ['ci' => $patch->ci?->toArray()] : []),
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

    /** The apps, nested_fleet, instance_host and ci members; the settings parser owns their shape and the `node.settings_*` codes. */
    public function settings(): NodeSettingsPatch
    {
        $decoded = json_decode($this->getContent(), associative: false);
        $settings = new stdClass;
        foreach (['apps', 'nested_fleet', 'instance_host', 'ci'] as $member) {
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
            throw ValidationException::withMessages(['body' => ['The request body must contain at least one of name, platform, lan_ip, reboot_hold, apps, nested_fleet, instance_host, or ci.']]);
        }

        return $body;
    }
}
