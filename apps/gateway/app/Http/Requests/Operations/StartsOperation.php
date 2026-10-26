<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

use Attribute;

/** Marks an operation-start endpoint for shared idempotency handling and OpenAPI generation. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class StartsOperation
{
    /** @param list<string> $secretInputs Dot paths excluded from the idempotency fingerprint. */
    public function __construct(public string $kind, public bool $requiresKey = false, public ?int $maxJsonBytes = null, public array $secretInputs = []) {}
}
