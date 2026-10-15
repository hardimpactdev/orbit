<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Orbit\Extensions\Conn\Models\ConnProfile;

final class BindConnProfileRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'profile_id' => ['required', 'integer', Rule::exists('conn_profiles', 'id')],
        ];
    }

    public function profile(): ConnProfile
    {
        return ConnProfile::query()->findOrFail($this->integer('profile_id'));
    }
}
