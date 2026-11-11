<?php

declare(strict_types=1);

namespace App\Http\Requests\Processes;

use App\Domain\Processes\ProcessTargetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListProcessesRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Omit both to list Processes on Nodes the caller may reach.
            'target_type' => ['required_with:target_id', 'nullable', Rule::enum(ProcessTargetType::class)],
            'target_id' => ['required_with:target_type', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function hasTarget(): bool
    {
        return $this->validated('target_type') !== null;
    }

    public function targetType(): ProcessTargetType
    {
        return ProcessTargetType::from($this->string('target_type')->toString());
    }

    public function targetId(): int
    {
        return $this->integer('target_id');
    }
}
