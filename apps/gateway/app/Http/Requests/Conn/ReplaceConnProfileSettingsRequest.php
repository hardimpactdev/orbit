<?php

declare(strict_types=1);

namespace App\Http\Requests\Conn;

use App\Data\Conn\ReplaceConnProfileSettingsData;
use App\Domain\Conn\ConnProfileSections;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The sections of a settings document to replace: any of `workspaces`, `appearance`, and `devices`, at
 * least one. Sections the request leaves out stay as they are. `devices` replaces every device type.
 * `version` is the version the client last read; a stale one is refused. The rules of each section are
 * in {@see ConnProfileSettingsRules}.
 */
final class ReplaceConnProfileSettingsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = [
            'version' => ['required', 'integer', 'min:1'],
            'settings' => ['required', 'array:workspaces,appearance,devices'],
        ];
        $settings = $this->settings();

        if (array_key_exists('workspaces', $settings)) {
            $rules += ConnProfileSettingsRules::workspaces('settings.workspaces');
        }

        if (array_key_exists('appearance', $settings)) {
            $rules += ConnProfileSettingsRules::forSection(ConnProfileSections::Appearance, 'settings.appearance', $settings['appearance'], nullable: false);
        }

        if (array_key_exists('devices', $settings)) {
            $rules += ConnProfileSettingsRules::devices('settings.devices');

            foreach (is_array($settings['devices']) ? array_keys($settings['devices']) : [] as $type) {
                if (is_string($type) && preg_match(ConnProfileSections::DeviceTypePattern, $type) === 1) {
                    $rules += ConnProfileSettingsRules::deviceSection("settings.devices.{$type}");
                }
            }
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $settings = $this->settings();

            if (is_array($settings['appearance'] ?? null)) {
                ConnProfileSettingsRules::checkSize($validator, ConnProfileSections::Appearance, 'settings.appearance', $settings['appearance']);
            }

            foreach (is_array($settings['devices'] ?? null) ? $settings['devices'] : [] as $type => $section) {
                ConnProfileSettingsRules::checkSize($validator, ConnProfileSections::device((string) $type), "settings.devices.{$type}", $section);
            }
        });
    }

    public function payload(): ReplaceConnProfileSettingsData
    {
        $validated = $this->validated();
        $settings = is_array($validated['settings'] ?? null) ? $validated['settings'] : [];

        return new ReplaceConnProfileSettingsData(
            version: $this->integer('version'),
            settings: $settings,
        );
    }

    /** @return array<mixed> */
    private function settings(): array
    {
        $settings = $this->input('settings');

        return is_array($settings) ? $settings : [];
    }
}
