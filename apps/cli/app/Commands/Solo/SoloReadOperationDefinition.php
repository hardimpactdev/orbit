<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final readonly class SoloReadOperationDefinition
{
    /**
     * @param  list<string>  $requiredArguments
     * @param  array<string, string>  $queryOptions
     */
    public function __construct(
        public string $command,
        public string $signature,
        public string $gatewayPath,
        public array $requiredArguments = [],
        public array $queryOptions = [],
    ) {}
}
