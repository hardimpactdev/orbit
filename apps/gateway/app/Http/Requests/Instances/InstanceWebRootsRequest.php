<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\Instances\Environment\DirectInstanceWebRoots;
use App\Domain\Operations\DirectOperation;
use App\Http\Requests\Operations\ExecutorRequest;
use App\Models\Operation;

final class InstanceWebRootsRequest extends ExecutorRequest
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
            'result' => [$result ? 'sometimes' : 'prohibited', 'array'],
            'result.instance_id' => ['sometimes', 'integer', 'min:1'],
            'result.confirmed' => ['sometimes', 'boolean'],
            'result.no_op' => ['sometimes', 'boolean'],
            'result.error_code' => ['sometimes', 'string', 'max:100', 'regex:/\A[a-z0-9_.-]+\z/D'],
            'error_code' => ['required_if:status,failed', 'nullable', 'string', 'max:100', 'regex:/\A[a-z0-9_.-]+\z/D'],
            'node_id' => ['prohibited'], 'instance_id' => ['prohibited'], 'input' => ['prohibited'],
            'transport' => ['prohibited'], 'command' => ['prohibited'], 'worker' => ['prohibited'], 'key' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        parent::authorize();
        $operation = $this->route('operation');
        if ($operation instanceof Operation) {
            app(DirectInstanceWebRoots::class)->assertOperation($operation);
            if (is_string($this->input('run_id'))) {
                app(DirectOperation::class)->assertRun($operation, $this->input('run_id'));
            }
        }

        return true;
    }
}
