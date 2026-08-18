<?php

declare(strict_types=1);

namespace App\Services\Skill;

final readonly class SkillTargetResolution
{
    public function __construct(
        public ?string $provider,
        public string $target,
    ) {}
}
