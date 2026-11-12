<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Keep an invisible holder's details an empty JSON object. */
final class InstanceBranchInUse extends ResourceOperationException
{
    public function render(Request $request): JsonResponse
    {
        $response = parent::render($request);
        $body = $response->getData();
        assert($body instanceof \stdClass);
        $error = $body->error;
        assert($error instanceof \stdClass);
        $error->details = (object) $this->details;

        return $response->setData($body);
    }
}
