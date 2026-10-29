<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Shared\ResourceOperationException;

/** Only Services discovers fleet work; workers claim their own leases. */
final class ClaimableExecutorRequest extends ExecutorRequest
{
    public function authorize(): bool
    {
        parent::authorize();
        $services = config('dependency_detection.services_node_id');
        if ((! is_int($services) && ! is_string($services))
            || preg_match('/\A[1-9][0-9]*\z/D', (string) $services) !== 1
            || (string) $this->peerNodeId() !== (string) $services) {
            throw new ResourceOperationException('operation.executor_forbidden', 'The configured Services peer is required.', 403);
        }

        return true;
    }

    /** @return array<string,list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
