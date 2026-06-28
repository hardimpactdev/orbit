<?php

declare(strict_types=1);

namespace Orbit\Core\Extensions;

final readonly class OrbitExtensionDefinition
{
    /**
     * @param  list<string>  $commands
     * @param  list<string>  $permissions
     * @param  list<string>  $gatewayRoutePrefixes
     *
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        public string $slug,
        public string $label,
        public string $description,
        public array $commands,
        public array $permissions,
        public array $gatewayRoutePrefixes,
    ) {}
}
