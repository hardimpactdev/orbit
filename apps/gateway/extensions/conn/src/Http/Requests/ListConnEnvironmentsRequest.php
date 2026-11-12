<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListConnEnvironmentsRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['include' => ['sometimes', 'string', 'in:git']];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    public function includeGit(): bool
    {
        return $this->validated('include') === 'git';
    }
}
