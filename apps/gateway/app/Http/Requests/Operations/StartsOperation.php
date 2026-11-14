<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use Attribute;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/** Marks an operation-start endpoint for shared idempotency handling and OpenAPI generation. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class StartsOperation
{
    /** @param list<string> $secretInputs Dot paths excluded from the idempotency fingerprint.
     * @param  array<string, string>  $kindWhenTrue  Declared boolean request fields that select another kind.
     * @param  bool  $keyed  False for a read that may start an Operation and dedupes its starts itself: it ignores Idempotency-Key, so a
     *                       repeated read answers the current state, never a replay.
     */
    public function __construct(public string $kind, public bool $requiresKey = false, public ?int $maxJsonBytes = null, public array $secretInputs = [], public bool $bindCaller = false, public array $kindWhenTrue = [], public bool $keyed = true) {}

    public function requestKind(Request $request): string
    {
        foreach ($this->kindWhenTrue as $field => $kind) {
            if ($request->boolean($field)) {
                return $kind;
            }
        }

        return $this->kind;
    }

    public static function forRoute(Route $route): ?self
    {
        $controller = $route->getControllerClass();
        if (! is_string($controller)) {
            return null;
        }
        $attribute = new \ReflectionMethod($controller, $route->getActionMethod())->getAttributes(self::class)[0] ?? null;

        return $attribute?->newInstance();
    }
}
