<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Support\ValidatedData;

/** An explicit clone source. The observed commit is frozen before reservation. */
final readonly class InstanceBranchSelection
{
    public function __construct(
        public string $mode,
        public string $target,
        public ?string $base,
        public ?string $sourceSha = null,
    ) {}

    /** @return array{mode:string,target:string,base:?string,source_sha:?string} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'target' => $this->target, 'base' => $this->base, 'source_sha' => $this->sourceSha];
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        return new self(ValidatedData::string($input['mode']), ValidatedData::string($input['target']),
            ValidatedData::nullableString($input['base']), ValidatedData::nullableString($input['source_sha']));
    }

    public function sameIntent(self $other): bool
    {
        return $this->mode === $other->mode && $this->target === $other->target && $this->base === $other->base;
    }
}
