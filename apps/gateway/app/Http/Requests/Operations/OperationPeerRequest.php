<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

abstract class OperationPeerRequest extends FormRequest
{
    public function peerNodeId(): int
    {
        $caller = $this->getUserResolver()();
        if (! $caller instanceof Node) {
            throw new ResourceOperationException('operation.executor_forbidden', 'An authenticated Node peer is required.', 403);
        }

        return $caller->id;
    }
}
