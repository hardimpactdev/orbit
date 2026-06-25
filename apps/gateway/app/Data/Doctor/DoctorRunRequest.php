<?php

declare(strict_types=1);

namespace App\Data\Doctor;

final readonly class DoctorRunRequest
{
    public function __construct(
        public ?string $key = null,
        public bool $dryRun = false,
        public ?DoctorTargetScope $scope = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function targetScope(): DoctorTargetScope
    {
        return $this->scope ?? DoctorTargetScope::none();
    }
}
