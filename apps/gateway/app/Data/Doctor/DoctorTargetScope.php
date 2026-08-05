<?php

declare(strict_types=1);

namespace App\Data\Doctor;

final readonly class DoctorTargetScope
{
    public function __construct(
        public ?string $app = null,
        public ?string $workspace = null,
        public ?int $instanceId = null,
        public ?string $instance = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public static function from(
        ?string $app,
        ?string $workspace,
        ?int $instanceId = null,
        ?string $instance = null,
    ): self {
        return new self($app, $workspace, $instanceId, $instance);
    }
}
