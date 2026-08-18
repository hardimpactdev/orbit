<?php

declare(strict_types=1);

namespace App\Services\Skill;

final readonly class SkillInstallPlan
{
    public function __construct(
        public ?string $provider,
        public string $target,
        public string $source,
        public bool $force,
        public bool $targetExistsAtPlan,
    ) {}

    public function withForce(): self
    {
        return new self(
            provider: $this->provider,
            target: $this->target,
            source: $this->source,
            force: true,
            targetExistsAtPlan: $this->targetExistsAtPlan,
        );
    }
}
