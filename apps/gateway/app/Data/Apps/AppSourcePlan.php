<?php

declare(strict_types=1);

namespace App\Data\Apps;

final readonly class AppSourcePlan
{
    private function __construct(
        public string $repository,
        public ?string $sourceRepository,
        public ?string $templateRepository,
        public ?string $newRepository,
    ) {}

    public static function clone(string $repository): self
    {
        return new self(
            repository: $repository,
            sourceRepository: $repository,
            templateRepository: null,
            newRepository: null,
        );
    }

    public static function template(
        string $repository,
        string $templateRepository,
        string $newRepository,
    ): self {
        return new self(
            repository: $repository,
            sourceRepository: null,
            templateRepository: $templateRepository,
            newRepository: $newRepository,
        );
    }
}
