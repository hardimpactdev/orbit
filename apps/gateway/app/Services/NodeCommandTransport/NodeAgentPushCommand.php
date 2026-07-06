<?php

declare(strict_types=1);

namespace App\Services\NodeCommandTransport;

final readonly class NodeAgentPushCommand
{
    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        public string $operationId,
        public string $binary,
        public array $argv,
        public ?string $input = null,
        public NodeCommandExecutionContext $executionContext = new NodeCommandExecutionContext,
    ) {}
}
