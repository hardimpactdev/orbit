<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

final class AdmitConnServerRolloutRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['dry_run' => ['sometimes', 'boolean']];
    }
}
