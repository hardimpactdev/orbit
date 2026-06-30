<?php

declare(strict_types=1);

namespace App\Services\Skill;

final readonly class SkillInstallRequest
{
    public function __construct(
        public ?string $provider,
        public ?string $path,
        public bool $force,
    ) {}
}
