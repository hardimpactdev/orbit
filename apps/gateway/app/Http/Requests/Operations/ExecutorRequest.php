<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

/** Authenticates configured Node peers for retained intent and private-material APIs. */
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

    public function peerNodeId(): int
    {
        $caller = $this->getUserResolver()();
        if (! $caller instanceof Node) {
            throw new ResourceOperationException('operation.executor_forbidden', 'An authenticated executor peer is required.', 403);
        }

        return $caller->id;
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
