<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Models\AppInstance;
use App\Models\Project;

final readonly class AppSelection
{
    public function __construct(
        public Project $app,
        public ?AppInstance $instance = null,
        public ?string $selector = null,
        public ?string $instanceSelector = null,
    ) {}

    public function hasInstance(): bool
    {
        return $this->instance instanceof AppInstance;
    }
}
