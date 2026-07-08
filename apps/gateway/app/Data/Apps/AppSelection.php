<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Models\App;
use App\Models\AppInstance;

final readonly class AppSelection
{
    public function __construct(
        public App $app,
        public ?AppInstance $instance = null,
        public ?string $selector = null,
        public ?string $instanceSelector = null,
    ) {}

    public function hasInstance(): bool
    {
        return $this->instance instanceof AppInstance;
    }
}
