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

    /** @return array{version: ?string, constraint: ?string, repository: ?array<string, mixed>} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'constraint' => $this->constraint, 'repository' => $this->repository];
    }
}
