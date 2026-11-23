<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Tasks\TaskInsights;
use Illuminate\Foundation\Http\FormRequest;

final class ShowTaskInsightsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', 'in:'.TaskInsights::periodList()],
            'project_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return key-of<TaskInsights::Periods> */
    public function period(): string
    {
        $period = $this->validated('period', '30d');

        return is_string($period) && array_key_exists($period, TaskInsights::Periods) ? $period : '30d';
    }

    public function projectId(): ?int
    {
        $value = $this->validated('project_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
