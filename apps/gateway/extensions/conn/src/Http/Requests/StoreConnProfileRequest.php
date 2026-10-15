<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Orbit\Extensions\Conn\Data\CreateConnProfileData;

final class StoreConnProfileRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('conn_profiles', 'name')],
        ];
    }

    public function payload(): CreateConnProfileData
    {
        return new CreateConnProfileData(name: $this->string('name')->toString());
    }
}
