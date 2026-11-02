<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Models\Instance;
use App\Models\Node;

final readonly class ScheduleTarget
{
    public function __construct(
        public Node $node,
        public string $user,
        /** Group, home and shell are read from the Node only for a Node timer's units; a native run reads them on the Node. */
        public ?string $group,
        public ?string $home,
        /** Null runs in the user's home. */
        public ?string $workingDirectory,
        public ?string $shell,
        public bool $loginShell,
        public ?Instance $instance,
    ) {}

    public function isProduction(): bool
    {
        return $this->instance?->placedOnAppProd() ?? false;
    }
}
