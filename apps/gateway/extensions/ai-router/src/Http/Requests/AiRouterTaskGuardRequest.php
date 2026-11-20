<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AiRouterTaskGuardRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['remove' => ['sometimes', 'boolean']];
    }

    public function remove(): bool
    {
        return $this->boolean('remove');
    }
}
