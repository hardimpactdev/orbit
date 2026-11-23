<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;

/** Registers or updates an iOS device for Conn push notifications. */
final class UpsertConnPushDeviceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $strictBool = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail("The {$attribute} field must be true or false.");
            }
        };

        return [
            'device_id' => ['required', 'string', 'uuid'],
            'apns_token' => ['required', 'string', 'min:32', 'max:512', 'regex:/\A[0-9a-fA-F]+\z/'],
            'apns_environment' => ['required', 'string', Rule::in(ConnPush::Environments)],
            'bundle_id' => ['required', 'string', 'max:255'],
            'app_version' => ['required', 'string', 'max:64'],
            'ios_version' => ['required', 'string', 'max:64'],
            'locale' => ['required', 'string', 'max:32'],
            'preferences' => ['required', 'array:approval,input,turn_finished,turn_failed,hide_titles'],
            'preferences.approval' => ['required', 'boolean', $strictBool],
            'preferences.input' => ['required', 'boolean', $strictBool],
            'preferences.turn_finished' => ['required', 'boolean', $strictBool],
            'preferences.turn_failed' => ['required', 'boolean', $strictBool],
            'preferences.hide_titles' => ['required', 'boolean', $strictBool],
        ];
    }

    /**
     * @return array{
     *     device_id: string,
     *     apns_token: string,
     *     apns_environment: string,
     *     bundle_id: string,
     *     app_version: string,
     *     ios_version: string,
     *     locale: string,
     *     preferences: array{approval: bool, input: bool, turn_finished: bool, turn_failed: bool, hide_titles: bool}
     * }
     */
    public function payload(): array
    {
        return [
            'device_id' => strtolower($this->string('device_id')->toString()),
            'apns_token' => strtolower($this->string('apns_token')->toString()),
            'apns_environment' => $this->string('apns_environment')->toString(),
            'bundle_id' => $this->string('bundle_id')->toString(),
            'app_version' => $this->string('app_version')->toString(),
            'ios_version' => $this->string('ios_version')->toString(),
            'locale' => $this->string('locale')->toString(),
            'preferences' => [
                'approval' => $this->boolean('preferences.approval'),
                'input' => $this->boolean('preferences.input'),
                'turn_finished' => $this->boolean('preferences.turn_finished'),
                'turn_failed' => $this->boolean('preferences.turn_failed'),
                'hide_titles' => $this->boolean('preferences.hide_titles'),
            ],
        ];
    }
}
