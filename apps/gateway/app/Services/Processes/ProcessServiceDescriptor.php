<?php

declare(strict_types=1);

namespace App\Services\Processes;

final readonly class ProcessServiceDescriptor
{
    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @param  array<string, mixed>  $credentials
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        public string $service,
        public string $versionFamily,
        public string $version,
        public string $command,
        public array $runtimeConfig,
        public array $credentials,
    ) {}
}
