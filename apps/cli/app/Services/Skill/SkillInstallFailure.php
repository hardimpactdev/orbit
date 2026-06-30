<?php

declare(strict_types=1);

namespace App\Services\Skill;

final readonly class SkillInstallFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $meta = [],
    ) {}
}
