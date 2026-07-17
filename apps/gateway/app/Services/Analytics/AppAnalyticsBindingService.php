<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Exceptions\AnalyticsMutationBusy;
use App\Exceptions\AnalyticsRouteCleanupFailed;
use App\Exceptions\AnalyticsRouteEnactmentFailed;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class AppAnalyticsBindingService
{
    public function __construct(
        private AnalyticsRouteRegistrar $routes,
        private AnalyticsPublicHostNormalizer $publicHostNormalizer,
        private int $lockSeconds = 120,
        private int $lockWaitSeconds = 10,
    ) {}

    /**
     * @param  array<int, mixed>  $publicHosts
     */
    public function enable(App $app, array $publicHosts): AppAnalyticsBinding
    {
        return $this->withMutationLock(fn (): AppAnalyticsBinding => $this->enableUnlocked($app, $publicHosts));
    }

    /** @param array<int, mixed> $publicHosts */
    private function enableUnlocked(App $app, array $publicHosts): AppAnalyticsBinding
    {
        $hosts = $this->publicHostNormalizer->normalize($app, $publicHosts);
        $this->routes->requireServiceRoute();
        $this->routes->assertPublicHostsAvailable($app, $hosts);

        try {
            $this->routes->removeObsoletePublicHosts($app, $hosts);
        } catch (RuntimeException $exception) {
            throw new AnalyticsRouteCleanupFailed($exception->getMessage(), previous: $exception);
        }

        /** @var AppAnalyticsBinding $binding */
        $binding = DB::transaction(function () use ($app, $hosts): AppAnalyticsBinding {
            $binding = $this->existingBinding($app);
            $attributes = [
                'enabled' => true,
                'public_hosts' => $hosts,
            ];

            if ($binding instanceof AppAnalyticsBinding) {
                $binding->fill($attributes);
                $binding->save();
            } else {
                $binding = AppAnalyticsBinding::query()->create([
                    'app_id' => $app->id,
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

    public function disable(App $app): AppAnalyticsBinding
    {
        return $this->withMutationLock(fn (): AppAnalyticsBinding => $this->disableUnlocked($app));
    }

    private function disableUnlocked(App $app): AppAnalyticsBinding
    {
        $this->binding($app);

        try {
            $this->routes->removeObsoletePublicHosts($app, []);
        } catch (RuntimeException $exception) {
            throw new AnalyticsRouteCleanupFailed($exception->getMessage(), previous: $exception);
        }

        /** @var AppAnalyticsBinding $binding */
        $binding = DB::transaction(function () use ($app): AppAnalyticsBinding {
            $binding = $this->binding($app);

            $binding->fill([
                'enabled' => false,
                'public_hosts' => [],
            ]);
            $binding->save();

            return $binding->refresh();
        });

        return $binding;
    }

    public function show(App $app): AppAnalyticsBinding
    {
        return $this->binding($app);
    }

    private function existingBinding(App $app): ?AppAnalyticsBinding
    {
        $binding = AppAnalyticsBinding::query()
            ->where('app_id', $app->id)
            ->first();

        return $binding instanceof AppAnalyticsBinding ? $binding : null;
    }

    private function binding(App $app): AppAnalyticsBinding
    {
        $binding = $this->existingBinding($app);

        if (! $binding instanceof AppAnalyticsBinding) {
            throw new RuntimeException("App '{$app->name}' does not have an analytics binding.");
        }

        return $binding;
    }

    /** @param Closure(): AppAnalyticsBinding $mutation */
    private function withMutationLock(Closure $mutation): AppAnalyticsBinding
    {
        try {
            /** @var AppAnalyticsBinding $binding */
            $binding = Cache::lock('orbit:app-analytics:mutation', $this->lockSeconds)
                ->block($this->lockWaitSeconds, $mutation);

            return $binding;
        } catch (LockTimeoutException $exception) {
            throw new AnalyticsMutationBusy(
                'Another app analytics mutation is still running.',
                previous: $exception,
            );
        }
    }
}
