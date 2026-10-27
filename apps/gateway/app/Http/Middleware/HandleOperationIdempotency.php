<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationRequestFingerprint;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/** Checks a start key after access checks, before Form Requests or target creation. */
final readonly class HandleOperationIdempotency
{
    public function __construct(private OperationIdempotency $idempotency) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (! $route instanceof Route || ! is_object($controller = $route->getController())) {
            return $next($request);
        }

        $attribute = new ReflectionMethod($controller, $route->getActionMethod())->getAttributes(StartsOperation::class)[0] ?? null;

        if ($attribute === null) {
            return $next($request);
        }
        $start = $attribute->newInstance();
        if (! $request->headers->has('Idempotency-Key')) {
            if ($start->requiresKey) {
                throw ValidationException::withMessages(['Idempotency-Key' => ['Idempotency-Key is required.']]);
            }

            return $next($request);
        }

        $keys = $request->headers->all('Idempotency-Key');
        $key = $keys[0] ?? '';

        if (count($keys) !== 1 || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['The Idempotency-Key header must have 1 to 128 characters from A-Z a-z 0-9 . _ : -.']]);
        }

        $previous = [$this->idempotency->kind, $this->idempotency->key, $this->idempotency->fingerprint];
        $this->idempotency->kind = $start->kind;
        $this->idempotency->key = $key;
        $caller = $request->user();
        $this->idempotency->fingerprint = app(OperationRequestFingerprint::class)->make(
            $caller instanceof Node ? $caller : null, $route->getName(), $route->parameters(), $request->getContent(), $start,
        );

        try {
            $operation = $this->idempotency->replay($this->idempotency->kind);

            return $operation === null ? $next($request) : response()->json([
                'data' => OperationData::fromModel($operation)->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
            ], 202);
        } finally {
            [$this->idempotency->kind, $this->idempotency->key, $this->idempotency->fingerprint] = $previous;
        }
    }
}
