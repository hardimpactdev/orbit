<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InstanceQueueRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::in(['pending', 'failed'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return 'pending'|'failed' */
    public function state(): string
    {
        return $this->string('state', 'pending')->toString() === 'failed' ? 'failed' : 'pending';
    }

    public function limit(): int
    {
        return $this->integer('limit', 50);
    }
}
