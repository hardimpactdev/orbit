<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationIdempotency;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use JsonException;
use ReflectionMethod;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

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

        if ($attribute === null || ! $request->headers->has('Idempotency-Key')) {
            return $next($request);
        }

        $keys = $request->headers->all('Idempotency-Key');
        $key = $keys[0] ?? '';

        if (count($keys) !== 1 || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['The Idempotency-Key header must have 1 to 128 characters from A-Z a-z 0-9 . _ : -.']]);
        }

        $previous = [$this->idempotency->kind, $this->idempotency->key, $this->idempotency->fingerprint];
        $this->idempotency->kind = $attribute->newInstance()->kind;
        $this->idempotency->key = $key;
        $this->idempotency->fingerprint = hash('sha256', json_encode([
            'target' => array_map(static fn (mixed $value): mixed => $value instanceof Model ? $value->getKey() : $value, $route->parameters()),
            'input' => $this->input($request->getContent()),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

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

    private function input(string $content): string
    {
        try {
            $input = json_decode($content, flags: JSON_THROW_ON_ERROR);

            if ($input instanceof stdClass) {
                new TopLevelJsonObjectInspector()->inspect($content, array_keys(get_object_vars($input)));
            }

            return json_encode($this->canonical($input), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException|UnexpectedValueException) {
            // Invalid input still conflicts with an existing key before the Form Request validates it.
            return 'invalid-json:'.hash('sha256', $content);
        }
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return (object) array_map($this->canonical(...), $properties);
        }

        return is_array($value) ? array_map($this->canonical(...), $value) : $value;
    }
}
