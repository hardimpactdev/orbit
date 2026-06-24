<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2ETopologyProviderPool
{
    /**
     * @param  list<E2ETopologyProvider>  $providers
     */
    public function __construct(
        private array $providers,
    ) {}

    public static function fromEnvironment(?E2EConfig $config = null): self
    {
        $config ??= E2EConfig::fromEnvironment();

        return new self(array_map(
            fn (string $provider): E2ETopologyProvider => self::makeProvider($provider, $config),
            $config->topologyProviderNames,
        ));
    }

    private static function makeProvider(string $provider, E2EConfig $config): E2ETopologyProvider
    {
        return match ($provider) {
            'incus' => new IncusTopologyProvider($config),
            'docker' => new DockerTopologyProvider($config),
            default => throw new \InvalidArgumentException("Unknown E2E topology provider [{$provider}]."),
        };
    }

    public function select(
        E2ETopologyKind $kind,
        ?E2ETopologyCapabilities $required = null,
    ): E2ETopologyProviderSelection {
        $failures = [];

        foreach ($this->providers as $provider) {
            if ($required !== null && ! $provider->capabilities()->satisfies($required)) {
                $failures[] = "{$provider->name()}: capabilities do not satisfy required";

                continue;
            }

            $availability = $provider->availability($kind);

            if ($availability->available) {
                return new E2ETopologyProviderSelection($provider, "{$provider->name()}: {$availability->message}");
            }

            $failures[] = "{$provider->name()}: {$availability->message}";
        }

        return new E2ETopologyProviderSelection(null, implode('; ', $failures));
    }
}
