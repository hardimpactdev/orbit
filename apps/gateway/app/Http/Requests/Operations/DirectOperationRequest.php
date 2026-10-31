<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPublicView;
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
        $peer = $this->peerNodeId();
        $operation = $this->route('operation');
        if ($operation instanceof Operation) {
            if ($operation->node_id !== $peer) {
                $projection = app(DirectOperation::class)->projection($operation);
                $participants = $operation->input['participant_node_ids'] ?? [];
                if ($this->route()?->getActionMethod() !== 'show'
                    || ! is_array($participants) || ! in_array($peer, $participants, true)
                    || ! $projection instanceof DirectOperationPublicView) {
                    throw new ResourceOperationException('operation.executor_forbidden', 'Only the root Node may mutate its intent; public participants may read their own public view.', 403);
                }
                if ($operation->status->terminal()) {
                    throw new ResourceOperationException('operation.finished', 'The Operation has ended.', 409);
                }
                $projection->assertPublicParticipant($operation, $peer);
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
