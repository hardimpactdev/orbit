<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

final class ShowTaskInsightsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', 'in:24h,7d,30d'],
            'project_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return '24h'|'7d'|'30d' */
    public function period(): string
    {
        return match ($this->validated('period')) {
            '24h' => '24h',
            '7d' => '7d',
            default => '30d',
        };
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
