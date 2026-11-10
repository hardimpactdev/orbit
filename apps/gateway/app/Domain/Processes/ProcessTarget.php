<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Models\Instance;
use App\Models\Node;

final readonly class ProcessTarget
{
    public string $defaultWorkingDirectory;

    /**
     * @param  array{instance_id:int,project:string,name:string}|null  $container  The Incus container of a container Instance: its
     *                                                                             Processes are `orbit` units in it, not on the Node.
     */
    public function __construct(
        public Node $node,
        public string $user,
        public string $checkoutPath,
        public ?string $certificateScope = null,
        public ?Instance $instance = null,
        public string $environmentFile = '',
        public bool $productionReleaseLayout = false,
        public ?string $routeDomain = null,
        public bool $onDemandHostStart = false,
        public ?array $container = null,
    ) {
        $this->defaultWorkingDirectory = $checkoutPath;
    }
}
