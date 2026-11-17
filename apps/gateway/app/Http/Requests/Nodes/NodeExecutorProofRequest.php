<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class NodeExecutorProofRequest extends FormRequest
{
    public function authorize(NodeAccessAuthorizer $access): bool
    {
        $caller = $this->user();

        return $caller instanceof Node && $access->hasGatewayAuthority($caller);
    }

    protected function failedAuthorization(): never
    {
        throw new ResourceOperationException('node_access.required', 'Gateway access is required.', 403);
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        // PHP can consume a multipart body before the request reaches Laravel.
        if ((int) $this->server->getString('CONTENT_LENGTH') > 0 || $this->getContent() !== '' || $this->all() !== []) {
            throw ValidationException::withMessages(['input' => ['The executor diagnostic requires an empty body and no query parameters.']]);
        }

        return [];
    }
}
