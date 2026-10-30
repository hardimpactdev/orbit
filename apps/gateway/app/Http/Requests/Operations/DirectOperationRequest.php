<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

class DirectOperationRequest extends ExecutorRequest
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
            $peer = $this->peerNodeId();
            if (! in_array(config('dependency_detection.services_node_id'), [$peer, (string) $peer], true) && $operation->node_id !== $peer) {
                throw new ResourceOperationException('operation.executor_forbidden', 'A Node worker may access only its own Node intent.', 403);
            }
        }

        return true;
    }

    protected function passedValidation(): void
    {
        $operation = $this->route('operation');
        if ($operation instanceof Operation) {
            $direct = app(DirectOperation::class);
            $direct->projection($operation);
            $direct->assertRun($operation, ValidatedData::string($this->validated('run_id')));
        }
    }
}
