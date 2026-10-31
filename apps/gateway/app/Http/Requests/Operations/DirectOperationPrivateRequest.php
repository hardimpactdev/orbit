<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Validation\Validator;

final class DirectOperationPrivateRequest extends DirectOperationRequest
{
    public function authorize(): bool
    {
        $peer = $this->peerNodeId();
        $operation = $this->route('operation');
        if ($operation instanceof Operation) {
            app(DirectOperationPrivateBindings::class)->assertParticipant($operation, $peer);
            $definition = app(DirectOperation::class)->definition($operation->kind);
            $transport = $this->isMethod('GET') ? 'private_material' : 'secret_outputs';
            if ($operation->node_id !== $peer && ($definition[$transport] ?? []) === []) {
                throw new ResourceOperationException('operation.executor_forbidden', 'This Node has no declared private transport.', 403);
            }
        }

        return true;
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['run_id', 'outputs', 'artifact']) !== []) {
                $validator->errors()->add('request', 'Unexpected request fields.');
            }
        }];
    }

    /** Preserve private JSON bytes before global string normalization.
     * @return array<string,mixed>
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $raw = json_decode($this->getContent(), true);
        if (is_array($raw) && array_key_exists('outputs', $raw)) {
            $data['outputs'] = $raw['outputs'];
        }

        return $data;
    }

    public function rules(): array
    {
        return ['run_id' => ['required', 'uuid'],
            'artifact' => [$this->isMethod('GET') ? 'sometimes' : 'prohibited', 'string', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/D'],
            'outputs' => [$this->route()?->getActionMethod() === 'secretOutput' ? 'required' : 'prohibited', 'array', 'min:1', 'max:64'],
        ];
    }
}
