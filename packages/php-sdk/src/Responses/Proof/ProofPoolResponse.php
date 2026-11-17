<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Proof;

use InvalidArgumentException;

/** The warm proof copy pool: whether it is drained, its warm copies and the copies of running proofs, by Instance id. */
final readonly class ProofPoolResponse
{
    /**
     * @param  list<int>  $warmCopies
     * @param  list<int>  $proofCopies
     */
    public function __construct(public bool $drained, public array $warmCopies, public array $proofCopies, public string $requestId) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $drained = $data['drained'] ?? null;
        if (! is_bool($drained)) {
            throw new InvalidArgumentException('Gateway response contains invalid proof pool state.');
        }

        return new self($drained, self::ids($data['warm_copies'] ?? null), self::ids($data['proof_copies'] ?? null), $requestId);
    }

    /** @return array{drained: bool, warm_copies: list<int>, proof_copies: list<int>} */
    public function toArray(): array
    {
        return ['drained' => $this->drained, 'warm_copies' => $this->warmCopies, 'proof_copies' => $this->proofCopies];
    }

    /** @return list<int> */
    private static function ids(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Gateway response contains invalid proof pool copies.');
        }

        return array_map(static fn (mixed $id): int => is_int($id) ? $id : throw new InvalidArgumentException('Gateway response contains invalid proof pool copies.'), $value);
    }
}
