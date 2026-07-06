<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeCommandExecutionContext
{
    /**
     * @param  array<string, string>  $environment
     */
    public function __construct(
        public ?string $cwd = null,
        public array $environment = [],
    ) {}
}
