<?php

declare(strict_types=1);

namespace App\Services\Skill;

final readonly class SkillInstallResult
{
    public function __construct(
        public ?string $provider,
        public string $target,
        public string $source,
    ) {}

    /**
     * @return array{
     *     action: string,
     *     provider: string|null,
     *     target: string,
     *     source: string
     * }
     */
    public function data(): array
    {
        return [
            'action' => 'installed',
            'provider' => $this->provider,
            'target' => $this->target,
            'source' => $this->source,
        ];
    }
}
