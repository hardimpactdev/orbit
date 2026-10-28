<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use Attribute;
use Illuminate\Routing\Route;

/** Marks an operation-start endpoint for shared idempotency handling and OpenAPI generation. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class StartsOperation
{
    /** @param list<string> $secretInputs Dot paths excluded from the idempotency fingerprint. */
    public function __construct(public string $kind, public bool $requiresKey = false, public ?int $maxJsonBytes = null, public array $secretInputs = [], public bool $bindCaller = false) {}

    public static function forRoute(Route $route): ?self
    {
        $controller = $route->getController();
        if (! is_object($controller)) {
            return null;
        }
        $attribute = new \ReflectionMethod($controller, $route->getActionMethod())->getAttributes(self::class)[0] ?? null;

        return $attribute?->newInstance();
    }
}
