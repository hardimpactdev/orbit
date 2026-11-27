<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/** A Tool's desired state: its exact coordinate, its constraint and the name of the Node's apt repository it installs from. */
final readonly class ToolDefinition
{
    public function __construct(public ?string $version, public ?string $constraint, public ?string $repository) {}

    public function matches(ToolManagerName $manager, string $observed): bool
    {
        return $manager === ToolManagerName::Vp || $manager === ToolManagerName::Composer
            ? ltrim($this->version ?? '', 'v') === ltrim($observed, 'v')
            : $this->version === $observed;
    }

    public function sameAs(self $other): bool
    {
        return $this->version === $other->version && $this->constraint === $other->constraint && $this->repository === $other->repository;
    }

    /** @return array{version: ?string, constraint: ?string, repository: ?string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'constraint' => $this->constraint, 'repository' => $this->repository];
    }
}
