<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRuntimeDriverRegistry
{
    /** @var array<string, object> */
    private array $drivers;

    /**
     * @param  list<object>  $drivers
     */
    public function __construct(array $drivers = [])
    {
        $this->drivers = $this->keyDrivers($drivers === [] ? $this->defaultDrivers() : $drivers);
    }

    public function resolve(ToolRuntimeSelection|ToolRegistryFailure $selection): mixed
    {
        if ($selection instanceof ToolRegistryFailure) {
            return $selection;
        }

        $driver = $this->drivers[$selection->implementationKey] ?? null;

        if ($driver !== null) {
            return $driver;
        }

        return ToolRegistryFailure::runtimePlatformUnsupported(
            tool: $selection->tool,
            runtime: $selection->runtime,
            platform: $selection->nodePlatform,
            platformFamily: $selection->platformFamily,
            implementationKey: $selection->implementationKey,
        );
    }

    /**
     * @return list<ToolRuntimeDriver>
     */
    private function defaultDrivers(): array
    {
        return [
            new DockerToolRuntimeDriver('linux'),
            new DockerToolRuntimeDriver('ubuntu'),
            new DockerSwarmToolRuntimeDriver('linux'),
            new DockerSwarmToolRuntimeDriver('ubuntu'),
        ];
    }

    /**
     * @param  list<object>  $drivers
     * @return array<string, object>
     */
    private function keyDrivers(array $drivers): array
    {
        $keyed = [];

        foreach ($drivers as $driver) {
            $key = $this->implementationKey($driver);

            if ($key !== null) {
                $keyed[$key] = $driver;
            }
        }

        return $keyed;
    }

    private function implementationKey(object $driver): ?string
    {
        if (! method_exists($driver, 'implementationKey')) {
            return null;
        }

        $key = $driver->implementationKey();

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }
}
