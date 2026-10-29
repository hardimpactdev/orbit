<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Arr;
use JsonSerializable;

/** Request-memory-only masks for the original invocation. No source resolution on recovery. */
final class EffectReceiptMetadata implements JsonSerializable
{
    public const string InputKey = '__orbit_effect_receipt';

    /** @var array<string, mixed> */
    private array $receipt = [];

    /** @param list<string> $masks
     * @param  array{nonce:string,created_at:int}|null  $identity
     * @param  list<bool>  $booleans
     * @param  list<string>  $secretOutputs
     */
    public function __construct(#[\SensitiveParameter] private readonly array $masks, private readonly ?array $identity = null, #[\SensitiveParameter] private readonly array $booleans = [], private readonly array $secretOutputs = []) {}

    /** @param list<string> $masks
     * @param  array{nonce:string,created_at:int}  $identity
     * @param  array<string,mixed>  $input
     * @param  list<string>  $parameters
     * @param  list<string>  $secretOutputs
     */
    public static function hydrated(#[\SensitiveParameter] array $masks, array $identity, #[\SensitiveParameter] array $input, array $parameters, array $secretOutputs = []): self
    {
        $booleans = [];
        $collect = static function (mixed $value) use (&$collect, &$booleans): void {
            if (is_bool($value)) {
                $booleans[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $collect($item);
                }
            }
        };
        foreach ($parameters as $parameter) {
            $collect(Arr::get($input, $parameter));
        }

        return new self($masks, $identity, array_values(array_unique($booleans, SORT_REGULAR)), $secretOutputs);
    }

    /** @return array{nonce:string,created_at:int} */
    public function identity(): array
    {
        if ($this->identity === null) {
            throw new ResourceOperationException('operation.receipt_unproven', 'An original Operation identity is required before execution.', 422);
        }

        return $this->identity;
    }

    /** @param array<string,mixed> $receipt */
    public function received(array $receipt): void
    {
        $this->receipt = array_intersect_key($receipt, array_flip(['state', 'exit_code', 'finished_at', 'effect_nonce', 'effect_created_at']));
    }

    /** @return array<string,mixed> */
    public function receipt(): array
    {
        return $this->receipt;
    }

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

    /** @return array{redactions: list<string>,booleans:list<bool>,nonce:string,created_at:int,secret_outputs?:list<string>} Protected stdin only. */
    public function envelope(): array
    {
        return ['redactions' => $this->masks, 'booleans' => $this->booleans, ...$this->identity(), ...($this->secretOutputs === [] ? [] : ['secret_outputs' => $this->secretOutputs])];
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
