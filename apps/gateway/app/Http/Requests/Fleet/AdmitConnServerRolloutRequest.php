<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Domain\ConnServer\ConnServerRollout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdmitConnServerRolloutRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['dry_run' => ['required', 'boolean'], 'scope' => ['required', Rule::in(ConnServerRollout::Scopes)]];
    }
}
