<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use Illuminate\Foundation\Http\FormRequest;

final class ShowOperationRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'after_sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function afterSequence(): ?int
    {
        return $this->validated('after_sequence') === null ? null : $this->integer('after_sequence');
    }
}
