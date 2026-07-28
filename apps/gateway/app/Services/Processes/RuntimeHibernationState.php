<?php

declare(strict_types=1);

namespace App\Services\Processes;

final readonly class RuntimeHibernationState
{
    private function __construct(
        public string $key,
        public bool $awake,
        public bool $hibernated,
        public bool $cold,
        public ?int $lastActivityAt,
    ) {}

    public static function from(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $key = $value['key'] ?? null;
        $awake = $value['awake'] ?? null;
        $hibernated = $value['hibernated'] ?? null;
        $cold = $value['cold'] ?? false;
        $lastActivityAt = $value['last_activity_at'] ?? null;

        if (
            ! is_string($key)
            || ! is_bool($awake)
            || ! is_bool($hibernated)
            || ! is_bool($cold)
            || ! is_int($lastActivityAt)
            && $lastActivityAt !== null
        ) {
            return null;
        }

        return new self($key, $awake, $hibernated, $cold, $lastActivityAt);
    }

    public function shouldHibernate(int $cutoff): bool
    {
        if ($this->hibernated) {
            return false;
        }

        return ! $this->awake || $this->lastActivityAt === null || $this->lastActivityAt <= $cutoff;
    }

    /**
     * @return array{key: string, awake: bool, hibernated: bool, cold: bool, last_activity_at: int|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'awake' => $this->awake,
            'hibernated' => $this->hibernated,
            'cold' => $this->cold,
            'last_activity_at' => $this->lastActivityAt,
        ];
    }
}
