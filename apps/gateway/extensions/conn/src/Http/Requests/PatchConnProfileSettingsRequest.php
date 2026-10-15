<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Orbit\Extensions\Conn\Data\PatchConnProfileSettingsData;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;

/**
 * Changes some sections of a settings document, each with the version the client last read:
 *
 *     {"sections": {"appearance": {"version": 3, "value": {...}}, "devices.iphone": {"version": 0, "value": null}}}
 *
 * A section is `workspaces`, `appearance`, or `devices.<type>`. A null value removes `appearance` or a
 * device section; an empty string is refused, so a client bug cannot clear a section. A section with
 * no recorded version, including one written before sections had versions, is at version 0. The rules
 * of each section are in {@see ConnProfileSettingsRules}.
 *
 * Laravel reads a dot as nesting, so `devices.iphone` cannot be a rule key. {@see self::validationData()}
 * nests it under `devices`, and an error names the field as `sections.devices.iphone.value.values`.
 */
final class PatchConnProfileSettingsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = ['sections' => ['bail', $this->knownSections(...), 'required', 'array', 'min:1']];

        $strings = $this->sentStringValues();

        foreach ($this->sections() as $section => $change) {
            $path = "sections.{$section}";
            $value = in_array($section, $strings, true)
                ? ["{$path}.value" => [self::refuseEmptyString(...)]]
                : ConnProfileSettingsRules::forSection($section, "{$path}.value", $change['value'] ?? null, nullable: true);
            array_unshift($value["{$path}.value"], 'present');
            $rules += [
                $path => ['array:version,value'],
                "{$path}.version" => ['required', 'integer:strict', 'min:0'],
                ...$value,
            ];
        }

        return $rules;
    }

    /**
     * The request with each `devices.<type>` section nested under `devices`. A name that is not a section
     * is dropped here, after {@see self::knownSections()} refused it.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function validationData(): array
    {
        $data = parent::validationData();
        $sections = $data['sections'] ?? null;

        if (! is_array($sections)) {
            return $data;
        }

        $nested = [];

        foreach ($sections as $name => $change) {
            $type = is_string($name) ? ConnProfileSections::deviceType($name) : null;

            if ($type !== null) {
                $nested['devices'][$type] = $change;
            } elseif ($name === ConnProfileSections::Workspaces || $name === ConnProfileSections::Appearance) {
                $nested[$name] = $change;
            }
        }

        return [...$data, 'sections' => $nested];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->sections() as $section => $change) {
                ConnProfileSettingsRules::checkSize($validator, $section, 'sections.'.$section.'.value', $change['value'] ?? null);
            }

            $devices = array_filter(array_keys($this->sections()), static fn (string $section): bool => ConnProfileSections::deviceType($section) !== null);

            if (count($devices) > ConnProfileSections::MaxDeviceTypes) {
                $validator->errors()->add('sections', 'A request changes at most '.ConnProfileSections::MaxDeviceTypes.' device types.');
            }
        });
    }

    public function payload(): PatchConnProfileSettingsData
    {
        $versions = [];
        $values = [];

        foreach ($this->sections() as $section => $change) {
            $versions[$section] = is_int($change['version'] ?? null) ? $change['version'] : 0;
            $values[$section] = $change['value'] ?? null;
        }

        return new PatchConnProfileSettingsData(versions: $versions, values: $values);
    }

    /**
     * The sections of the request by flat name, as `workspaces`, `appearance`, or `devices.<type>`, with
     * the validated change of each. Names that are not sections are left out.
     *
     * @return array<string, array<mixed>>
     */
    private function sections(): array
    {
        $nested = $this->validationData()['sections'] ?? null;
        $flat = [];

        if (! is_array($nested)) {
            return $flat;
        }

        foreach ($nested as $name => $change) {
            if ($name === 'devices' && is_array($change)) {
                foreach ($change as $type => $deviceChange) {
                    $flat[ConnProfileSections::device((string) $type)] = is_array($deviceChange) ? $deviceChange : [];
                }
            } elseif (in_array($name, [ConnProfileSections::Workspaces, ConnProfileSections::Appearance], true)) {
                $flat[(string) $name] = is_array($change) ? $change : [];
            }
        }

        return $flat;
    }

    /**
     * The sections whose sent `value` is an empty or blank string. The request stack turns such a string
     * into null, which would remove the section, so the raw body is read here.
     *
     * @return list<string>
     */
    private function sentStringValues(): array
    {
        $body = json_decode($this->getContent(), true);
        $sections = is_array($body) ? ($body['sections'] ?? null) : null;

        if (! is_array($sections)) {
            return [];
        }

        $strings = [];

        foreach ($sections as $name => $change) {
            if (is_array($change) && is_string($change['value'] ?? null) && trim($change['value']) === '') {
                $strings[] = (string) $name;
            }
        }

        return $strings;
    }

    private static function refuseEmptyString(string $attribute, mixed $value, Closure $fail): void
    {
        $fail("The {$attribute} field must not be an empty string. Send null to remove the section.");
    }

    /** Refuses a section name that Conn does not know, such as `theme`, `devices`, or `devices.Phone`. */
    private function knownSections(string $attribute, mixed $value, Closure $fail): void
    {
        $sent = $this->input('sections');

        if (! is_array($sent)) {
            return;
        }

        $unknown = [];

        foreach (array_keys($sent) as $name) {
            if (! ConnProfileSections::isKnown((string) $name)) {
                $unknown[] = (string) $name;
            }
        }

        if ($unknown !== []) {
            $fail('Unknown section: '.implode(', ', $unknown).'. A section is workspaces, appearance, or devices.<type>.');
        }
    }
}
