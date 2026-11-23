<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Reads one registered push device for the calling Node by its device_id query parameter. */
final class ShowConnPushDeviceRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'uuid'],
        ];
    }

    public function deviceId(): string
    {
        return strtolower($this->string('device_id')->toString());
    }
}
