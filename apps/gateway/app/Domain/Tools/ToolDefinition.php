<?php

declare(strict_types=1);

namespace App\Domain\Tools;

final readonly class ToolDefinition
{
    /** @param array<string, mixed>|null $repository */
    public function __construct(public ?string $version, public ?string $constraint, public ?array $repository) {}

    public function matches(ToolManagerName $manager, string $observed): bool
    {
        return $manager === ToolManagerName::Vp || $manager === ToolManagerName::Composer
            ? ltrim($this->version ?? '', 'v') === ltrim($observed, 'v')
            : $this->version === $observed;
    }

    public function sameAs(self $other): bool
    {
        return $this->version === $other->version && $this->constraint === $other->constraint
            && self::sameRepository($this->repository, $other->repository);
    }

    /** @param array<string, mixed>|null $first
     * @param  array<string, mixed>|null  $second
     */
    public static function sameRepository(?array $first, ?array $second): bool
    {
        return self::canonical($first) === self::canonical($second);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @return array{version: ?string, constraint: ?string, repository: ?array<string, mixed>} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'constraint' => $this->constraint, 'repository' => $this->repository];
    }
}
