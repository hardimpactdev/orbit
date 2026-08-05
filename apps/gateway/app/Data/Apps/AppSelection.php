<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Models\App;
use App\Models\Instance;

final readonly class AppSelection
{
    public function __construct(
        public App $app,
        public ?Instance $instance = null,
        public ?string $selector = null,
        public ?string $instanceSelector = null,
    ) {}

    public function hasInstance(): bool
    {
        return $this->instance instanceof Instance;
    }
}
