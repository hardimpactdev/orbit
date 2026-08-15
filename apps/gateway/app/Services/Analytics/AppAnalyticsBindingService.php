<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsMutationBusy;
use App\Exceptions\AnalyticsRouteCleanupFailed;
use App\Exceptions\AnalyticsRouteEnactmentFailed;
use App\Models\AppAnalyticsBinding;
use App\Models\Instance;
use App\Models\ProxyRoute;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class AppAnalyticsBindingService
{
    public const int MUTATION_LOCK_SECONDS = 3600;

    public const int ROUTE_MUTATION_BUDGET_SECONDS = 120;

    public const int MUTATION_LOCK_BUFFER_SECONDS = 600;

    public function __construct(
        private AnalyticsRouteRegistrar $routes,
        private AnalyticsPublicHostNormalizer $publicHostNormalizer,
        private int $lockSeconds = self::MUTATION_LOCK_SECONDS,
        private int $lockWaitSeconds = 10,
    ) {}

    /**
     * @param  array<int, mixed>  $publicHosts
     */
    public function enable(Instance $instance, array $publicHosts): AppAnalyticsBinding
    {
        return $this->withMutationLock(
            $instance,
            fn (): AppAnalyticsBinding => $this->enableUnlocked($instance, $publicHosts),
        );
    }

    /** @param array<int, mixed> $publicHosts */
    private function enableUnlocked(Instance $instance, array $publicHosts): AppAnalyticsBinding
    {
        $hosts = $this->publicHostNormalizer->normalize($instance, $publicHosts);
        $this->routes->requireServiceRoute();
        $this->routes->assertPublicHostsAvailable($instance, $hosts);

        try {
            $this->routes->removeObsoletePublicHosts($instance, $hosts);
        } catch (RuntimeException $exception) {
            throw new AnalyticsRouteCleanupFailed($exception->getMessage(), previous: $exception);
        }

        /** @var AppAnalyticsBinding $binding */
        $binding = DB::transaction(function () use ($instance, $hosts): AppAnalyticsBinding {
            $binding = $this->existingBinding($instance);
            $attributes = [
                'enabled' => true,
                'public_hosts' => $hosts,
            ];

            if ($binding instanceof AppAnalyticsBinding) {
                $binding->fill($attributes);
                $binding->save();
            } else {
                $binding = AppAnalyticsBinding::query()->create([
                    'instance_id' => $instance->id,
                    ...$attributes,
                ]);
            }

            $binding = $binding->refresh();
            $this->routes->syncPublicHosts($binding);

            return $binding->refresh();
        });

        try {
            $this->routes->convergePublicHosts($binding);
        } catch (RuntimeException $exception) {
            throw new AnalyticsRouteEnactmentFailed($exception->getMessage(), previous: $exception);
        }

        return $binding->refresh();
    }

    public function disable(Instance $instance): AppAnalyticsBinding
    {
        return $this->withMutationLock($instance, fn (): AppAnalyticsBinding => $this->disableUnlocked($instance));
    }

    private function disableUnlocked(Instance $instance): AppAnalyticsBinding
    {
        $this->binding($instance);

        try {
            $this->routes->removeObsoletePublicHosts($instance, []);
        } catch (RuntimeException $exception) {
            throw new AnalyticsRouteCleanupFailed($exception->getMessage(), previous: $exception);
        }

        /** @var AppAnalyticsBinding $binding */
        $binding = DB::transaction(function () use ($instance): AppAnalyticsBinding {
            $binding = $this->binding($instance);

            $binding->fill([
                'enabled' => false,
                'public_hosts' => [],
            ]);
            $binding->save();

            return $binding->refresh();
        });

        return $binding;
    }

    public function show(Instance $instance): AppAnalyticsBinding
    {
        return $this->binding($instance);
    }

    private function existingBinding(Instance $instance): ?AppAnalyticsBinding
    {
        $binding = AppAnalyticsBinding::query()
            ->where('instance_id', $instance->id)
            ->first();

        return $binding instanceof AppAnalyticsBinding ? $binding : null;
    }

    private function binding(Instance $instance): AppAnalyticsBinding
    {
        $binding = $this->existingBinding($instance);

        if (! $binding instanceof AppAnalyticsBinding) {
            throw new RuntimeException("Instance '{$instance->name}' does not have an analytics binding.");
        }

        return $binding;
    }

    /** @param Closure(): AppAnalyticsBinding $mutation */
    private function withMutationLock(Instance $instance, Closure $mutation): AppAnalyticsBinding
    {
        try {
            /** @var AppAnalyticsBinding $binding */
            $binding = Cache::lock('orbit:app-analytics:mutation', $this->mutationLockSeconds($instance))
                ->block($this->lockWaitSeconds, $mutation);

            return $binding;
        } catch (LockTimeoutException $exception) {
            throw new AnalyticsMutationBusy(
                'Another app analytics mutation is still running.',
                previous: $exception,
            );
        }
    }

    private function mutationLockSeconds(Instance $instance): int
    {
        $instance->loadMissing('app');
        $existingRouteCount = ProxyRoute::query()
            ->where('app_id', $instance->app_id)
            ->where('owner_type', 'app-analytics')
            ->where('config->instance_id', $instance->id)
            ->count();

        $routeBudgetSeconds =
            (($existingRouteCount + AnalyticsPublicHostNormalizer::MAXIMUM_HOSTS) * self::ROUTE_MUTATION_BUDGET_SECONDS)
            + self::MUTATION_LOCK_BUFFER_SECONDS;

        return max($this->lockSeconds, $routeBudgetSeconds);
    }
}
