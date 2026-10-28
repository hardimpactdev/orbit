<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use JsonSerializable;

/** Request-memory-only masks for the original invocation. No source resolution on recovery. */
final readonly class EffectReceiptMetadata implements JsonSerializable
{
    public const string InputKey = '__orbit_effect_receipt';

    /** @param list<string> $masks */
    public function __construct(#[\SensitiveParameter] private array $masks) {}

    /** @param array<string, mixed> $run */
    public static function fromRun(#[\SensitiveParameter] array $run): self
    {
        $metadata = $run['effect_metadata'] ?? null;
        if (! $metadata instanceof self) {
            throw new ResourceOperationException('operation.receipt_unproven', 'Protected effect metadata is required before execution.', 422);
        }

        return $metadata;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function attach(#[\SensitiveParameter] array $input): array
    {
        if (array_key_exists(self::InputKey, $input)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The reserved transport metadata field is unavailable.', 422);
        }

        return [...$input, self::InputKey => $this];
    }

    /** @return array{redactions: list<string>} Protected stdin only. */
    public function envelope(): array
    {
        return ['redactions' => $this->masks];
    }

    /** @return array{protected: true} */
    public function __debugInfo(): array
    {
        return ['protected' => true];
    }

    public function jsonSerialize(): string
    {
        return '[PROTECTED]';
    }
}
