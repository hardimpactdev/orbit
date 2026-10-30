<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\DirectOperation;
use App\Models\Operation;

final class DirectOperationRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $result = $this->route()?->getActionMethod() === 'result';

        return [
            'run_id' => ['required', 'uuid'],
            'status' => [$result ? 'required' : 'prohibited', 'in:running,succeeded,failed,cancelled'],
            'started_at' => [$result ? 'required' : 'prohibited', 'date_format:Y-m-d\TH:i:s.v\Z'],
            'finished_at' => ['required_if:status,succeeded,failed,cancelled', 'nullable', 'date_format:Y-m-d\TH:i:s.v\Z', 'after_or_equal:started_at'],
            'result' => ['sometimes', 'array'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:100', 'regex:/\A[a-z0-9_.-]+\z/D'],
            'node_id' => ['prohibited'],
            'input' => ['prohibited'],
            'transport' => ['prohibited'],
            'command' => ['prohibited'],
            'worker' => ['prohibited'],
            'key' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        parent::authorize();
        $operation = $this->route('operation');
        if ($operation instanceof Operation) {
            app(DirectOperation::class)->assertRun($operation, is_string($this->input('run_id')) ? $this->input('run_id') : '');
        }

        return true;
    }
}
