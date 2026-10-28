<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gateway-peer primitives are restricted to the configured Services identity.
 *
 * @phpstan-import-type StepInput from SwampOperationExecutor
 * @phpstan-import-type CompletionInput from SwampOperationExecutor
 * @phpstan-import-type RunInput from SwampOperationExecutor
 */
abstract class ExecutorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $services = config('dependency_detection.services_node_id');
        $workers = config('operation_executor.worker_node_ids', []);
        $ids = is_array($workers) ? [...$workers, $services] : [$services];
        $caller = $this->getUserResolver()();
        $allowed = array_any($ids, static fn (mixed $id): bool => (is_int($id) || is_string($id))
            && preg_match('/\A[1-9][0-9]*\z/D', (string) $id) === 1
            && $caller instanceof Node && (string) $caller->id === (string) $id);
        if (! $allowed) {
            throw new ResourceOperationException('operation.executor_forbidden', 'A configured executor peer is required.', 403);
        }

        return true;
    }

    public function worker(): string
    {
        return ValidatedData::string($this->validated('worker'));
    }

    /** @return StepInput */
    public function stepInput(): array
    {
        return ['worker' => $this->worker(), 'key' => ValidatedData::string($this->validated('key')),
            'name' => ValidatedData::string($this->validated('name')), 'status' => ValidatedData::string($this->validated('status')),
            'output' => ValidatedData::string($this->validated('output', '')),
            'result' => ValidatedData::object($this->validated('result', [])),
            'error_code' => ValidatedData::nullableString($this->validated('error_code'))];
    }

    /** @return CompletionInput */
    public function completionInput(): array
    {
        return ['worker' => $this->worker(), 'status' => ValidatedData::string($this->validated('status')),
            'result' => ValidatedData::object($this->validated('result', [])),
            'error_code' => ValidatedData::nullableString($this->validated('error_code'))];
    }

    /** @return RunInput */
    public function runInput(): array
    {
        return ['worker' => $this->worker(), 'key' => ValidatedData::string($this->validated('key')),
            'transport' => ValidatedData::string($this->validated('transport')),
            'name' => ValidatedData::string($this->validated('name')), 'input' => ValidatedData::object($this->validated('input'))];
    }

    /**
     * Preserve log bytes that the ordinary string-trimming middleware removes.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = parent::validationData();
        $raw = json_decode($this->getContent(), true);
        if (is_array($raw) && array_key_exists('output', $raw)) {
            $data['output'] = $raw['output'];
        }

        return $data;
    }
}
