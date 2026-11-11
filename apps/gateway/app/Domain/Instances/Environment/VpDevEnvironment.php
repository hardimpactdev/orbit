<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Processes\VpDevPreset;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Process;
use App\Models\Route;

/** Instance-owned Vite settings, derived at render time instead of copied from a default or stored port. */
final readonly class VpDevEnvironment
{
    public const array Keys = ['INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS'];

    /** @param array<string, string> $values
     * @return array<string, string>
     */
    public function project(Instance $instance, #[\SensitiveParameter] array $values, ?string $primaryDomain = null): array
    {
        $values = array_diff_key($values, array_flip(self::Keys));
        if (! $instance->placedOnAppDev() || ! is_int($instance->vite_port)
            || ! Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instance->id)
                ->where('status', '!=', LifecycleStatus::Removing->value)->where('runtime_config->preset', VpDevPreset::NAME)->exists()) {
            return $values;
        }

        $values['INERTIA_SSR_HOT_URL'] = 'http://127.0.0.1:'.$instance->vite_port;
        // Every Route of this Instance sends /__orbit/vite to the same endpoint, including web-root Routes.
        $routes = $instance->routes()->orderBy('routes.id')->get();
        $authoritative = $routes->filter(static fn (Route $route): bool => $route->isAuthoritative());
        $primaryDomain ??= ($authoritative->first(static fn (Route $route): bool => ! $route->hasWebRoot())
            ?? $authoritative->first() ?? $routes->first())?->domain;
        $domains = array_values(array_unique(array_filter([$primaryDomain, ...$routes->map(static fn (Route $route): string => $route->domain)->all()],
            static fn (?string $domain): bool => $domain !== null && $domain !== '')));
        if ($domains !== []) {
            // Vite splits this variable on commas, trims each hostname and discards empty entries.
            $values['__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS'] = implode(',', $domains);
        }

        return $values;
    }
}
