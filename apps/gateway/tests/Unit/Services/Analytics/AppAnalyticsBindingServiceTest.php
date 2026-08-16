<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\AnalyticsDomainRequired;
use App\Exceptions\AnalyticsMutationBusy;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsPublicHostNormalizer;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Analytics\AppAnalyticsBindingService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

describe('AppAnalyticsBindingService', function (): void {
    it('fails before mutation when another analytics binding mutation holds the lease', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $lock = Cache::lock('orbit:app-analytics:mutation', 60);

        expect($lock->get())->toBeTrue();

        try {
            $service = new AppAnalyticsBindingService(
                app(AnalyticsRouteRegistrar::class),
                new AnalyticsPublicHostNormalizer,
                lockSeconds: 60,
                lockWaitSeconds: 0,
            );

            expect(fn () => $service->enable($app, []))
                ->toThrow(AnalyticsMutationBusy::class, 'Another app analytics mutation is still running.');
        } finally {
            $lock->release();
        }

        expect(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())->toBeFalse();
    });

    it('keeps a second mutation out after the former 120 second lease boundary', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        Carbon::setTestNow('2026-07-17 12:00:00');
        $lock = Cache::lock(
            'orbit:app-analytics:mutation',
            AppAnalyticsBindingService::MUTATION_LOCK_SECONDS,
        );

        expect($lock->get())->toBeTrue();

        try {
            Carbon::setTestNow(now()->addSeconds(121));

            $service = new AppAnalyticsBindingService(
                app(AnalyticsRouteRegistrar::class),
                new AnalyticsPublicHostNormalizer,
                lockWaitSeconds: 0,
            );

            expect(fn () => $service->enable($app, []))
                ->toThrow(AnalyticsMutationBusy::class, 'Another app analytics mutation is still running.');
        } finally {
            $lock->release();
            Carbon::setTestNow();
        }

        expect(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())->toBeFalse();
    });

    it('scales the mutation lease for legacy bindings with more than the current route limit', function (): void {
        $app = createAnalyticsApp();
        $config = $app->driver_config;
        assert($config instanceof OrbitInstanceDriverConfigData);

        foreach (range(start: 1, end: 25) as $index) {
            ProxyRoute::query()->create([
                'node_id' => $config->node_id,
                'domain' => "legacy-analytics-{$index}.docs.test",
                'app_id' => $app->app_id,
                'owner_type' => 'app-analytics',
                'kind' => 'proxy',
                'source_hash' => hash('sha256', "legacy-analytics-{$index}.docs.test"),
                'config' => ['instance_id' => $app->id],
            ]);
        }

        $expectedLeaseSeconds =
            (
                (25 + AnalyticsPublicHostNormalizer::MAXIMUM_HOSTS)
                * AppAnalyticsBindingService::ROUTE_MUTATION_BUDGET_SECONDS
            )
            + AppAnalyticsBindingService::MUTATION_LOCK_BUFFER_SECONDS;
        $lock = Mockery::mock(Lock::class);

        Cache::shouldReceive('lock')
            ->once()
            ->with('orbit:app-analytics:mutation', $expectedLeaseSeconds)
            ->andReturn($lock);
        $lock
            ->shouldReceive('block')
            ->once()
            ->withArgs(fn (int $waitSeconds, Closure $mutation): bool => $waitSeconds === 10)
            ->andReturnUsing(static fn (int $waitSeconds, Closure $mutation): AppAnalyticsBinding => $mutation());

        $binding = new AppAnalyticsBindingService(
            new AnalyticsBindingRecordingRegistrar,
            new AnalyticsPublicHostNormalizer,
        )->enable($app, []);

        expect($binding->enabled)->toBeTrue();
    });

    it('creates enabled bindings with a default analytics host derived from the app domain', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();

        $binding = app(AppAnalyticsBindingService::class)->enable($app, []);

        expect($binding)
            ->toBeInstanceOf(AppAnalyticsBinding::class)
            ->and($binding->enabled)
            ->toBeTrue()
            ->and($binding->public_hosts)
            ->toBe(['analytics.docs.test'])
            ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())
            ->toBeTrue()
            ->and(
                ProxyRoute::query()
                    ->where('domain', 'analytics.docs.test')
                    ->where('owner_type', 'app-analytics')
                    ->exists(),
            )
            ->toBeTrue();
    });

    it('rejects public host URLs', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        $first = $service->enable($app, [' https://invalid.test ']);
    })->throws(InvalidArgumentException::class, 'Analytics public hosts must be hostnames, not URLs.');

    it('rejects malformed public hostnames before route enactment', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();

        expect(fn () => app(AppAnalyticsBindingService::class)->enable($app, ['analytics.docs.test/path']))
            ->toThrow(InvalidArgumentException::class, 'Analytics public hosts must be public DNS hostnames.');

        expect(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('owner_type', 'app-analytics')->exists())
            ->toBeFalse();
    });

    it('rejects IP, single-label, and excessive public host lists before route enactment', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        expect(fn () => $service->enable($app, ['127.0.0.1']))
            ->toThrow(InvalidArgumentException::class, 'Analytics public hosts must be public DNS hostnames.')
            ->and(fn () => $service->enable($app, ['localhost']))
            ->toThrow(InvalidArgumentException::class, 'Analytics public hosts must be public DNS hostnames.')
            ->and(fn () => $service->enable($app, array_map(
                static fn (int $index): string => "analytics-{$index}.docs.test",
                range(1, AnalyticsPublicHostNormalizer::MAXIMUM_HOSTS + 1),
            )))
            ->toThrow(
                InvalidArgumentException::class,
                'Analytics supports at most '.AnalyticsPublicHostNormalizer::MAXIMUM_HOSTS.' public hosts.',
            );

        expect(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('owner_type', 'app-analytics')->exists())
            ->toBeFalse();
    });

    it('updates existing bindings with normalized explicit public hosts', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        $first = $service->enable($app, []);
        $updated = $service->enable($app, [' Metrics.Docs.Test ', 'metrics.docs.test', '']);

        expect($updated->id)
            ->toBe($first->id)
            ->and($updated->enabled)
            ->toBeTrue()
            ->and($updated->public_hosts)
            ->toBe(['metrics.docs.test'])
            ->and(
                ProxyRoute::query()
                    ->where('domain', 'analytics.docs.test')
                    ->where('owner_type', 'app-analytics')
                    ->exists(),
            )
            ->toBeFalse()
            ->and(
                ProxyRoute::query()
                    ->where('domain', 'metrics.docs.test')
                    ->where('owner_type', 'app-analytics')
                    ->exists(),
            )
            ->toBeTrue();
    });

    it('keeps distinct bindings for sibling instances', function (): void {
        createAnalyticsRoutePrerequisites();
        $production = createAnalyticsApp();
        $production->loadMissing('app');
        $config = $production->driver_config;
        assert($config instanceof OrbitInstanceDriverConfigData);
        $staging = Instance::factory()->for($production->app)->create([
            'name' => 'staging',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $config->node_id,
                domain: 'staging.docs.test',
            ),
        ]);

        $service = app(AppAnalyticsBindingService::class);
        $productionBinding = $service->enable($production, ['analytics.docs.test']);
        $stagingBinding = $service->enable($staging, ['analytics.staging.docs.test']);

        expect($productionBinding->instance_id)
            ->toBe($production->id)
            ->and($stagingBinding->instance_id)
            ->toBe($staging->id)
            ->and(AppAnalyticsBinding::query()->count())
            ->toBe(2);
    });

    it('disables bindings and removes public analytics routes without removing the private service route', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $service = app(AppAnalyticsBindingService::class);

        $binding = $service->enable($app, ['analytics.docs.test']);
        $disabled = $service->disable($app);

        expect($disabled->id)
            ->toBe($binding->id)
            ->and($disabled->enabled)
            ->toBeFalse()
            ->and($disabled->public_hosts)
            ->toBe([])
            ->and(
                ProxyRoute::query()
                    ->where('domain', 'analytics.docs.test')
                    ->where('owner_type', 'app-analytics')
                    ->exists(),
            )
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('domain', 'analytics.orbit')->where('owner_type', 'router')->exists())
            ->toBeTrue();
    });

    it('requires role deployment to create the private analytics route', function (): void {
        createAnalyticsRoutePrerequisites(createServiceRoute: false);
        $app = createAnalyticsApp();

        expect(fn () => app(AppAnalyticsBindingService::class)->enable($app, []))
            ->toThrow(RuntimeException::class, 'The analytics role must be deployed before enabling app analytics.');

        expect(ProxyRoute::query()->where('domain', 'analytics.orbit')->exists())
            ->toBeFalse()
            ->and(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())
            ->toBeFalse();
    });

    it('requires a configured app domain before enabling analytics', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp(domain: null);

        expect(fn () => app(AppAnalyticsBindingService::class)->enable($app, []))
            ->toThrow(
                AnalyticsDomainRequired::class,
                "Instance 'docs.production' requires a configured valid public domain before analytics can be enabled.",
            );

        expect(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('owner_type', 'app-analytics')->exists())
            ->toBeFalse();
    });

    it('does not allow an explicit analytics host to bypass the app domain requirement', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp(domain: null);

        expect(fn () => app(AppAnalyticsBindingService::class)->enable($app, ['analytics.docs.test']))
            ->toThrow(
                AnalyticsDomainRequired::class,
                "Instance 'docs.production' requires a configured valid public domain before analytics can be enabled.",
            );

        expect(AppAnalyticsBinding::query()->where('instance_id', $app->id)->exists())
            ->toBeFalse()
            ->and(ProxyRoute::query()->where('owner_type', 'app-analytics')->exists())
            ->toBeFalse();
    });

    it('validates, cleans obsolete routes, persists intent, and enacts routes in order', function (): void {
        $app = createAnalyticsApp();
        $registrar = new AnalyticsBindingRecordingRegistrar;

        $binding = new AppAnalyticsBindingService($registrar, new AnalyticsPublicHostNormalizer)->enable($app, []);

        expect($binding->enabled)
            ->toBeTrue()
            ->and($binding->public_hosts)
            ->toBe(['analytics.docs.test'])
            ->and($registrar->calls)
            ->toBe([
                'require-service-route',
                'assert-public-hosts:analytics.docs.test',
                'remove-obsolete-public-hosts:analytics.docs.test',
                'sync-public-hosts',
                'converge-public-hosts',
            ]);
    });

    it('keeps the binding enabled when public route cleanup fails during disable', function (): void {
        $app = createAnalyticsApp();
        AppAnalyticsBinding::query()->create([
            'instance_id' => $app->id,
            'enabled' => true,
            'public_hosts' => ['analytics.docs.test'],
        ]);
        $registrar = new AnalyticsBindingRecordingRegistrar(failCleanup: true);

        expect(fn () => new AppAnalyticsBindingService($registrar, new AnalyticsPublicHostNormalizer)->disable($app))
            ->toThrow(RuntimeException::class, 'Public analytics route cleanup failed.');

        $binding = AppAnalyticsBinding::query()->where('instance_id', $app->id)->firstOrFail();

        expect($binding->enabled)
            ->toBeTrue()
            ->and($binding->public_hosts)
            ->toBe(['analytics.docs.test'])
            ->and($registrar->calls)
            ->toBe(['remove-obsolete-public-hosts:']);
    });

    it('enacts router before ingress and removes ingress before router', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp();
        $shell = new class implements RemoteShell {
            /** @var list<string> */
            public array $nodes = [];

            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                $this->nodes[] = $node->name;

                return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 0);
            }
        };
        app()->instance(RemoteShell::class, $shell);
        $service = app(AppAnalyticsBindingService::class);

        $service->enable($app, []);

        expect(array_search('router-1', $shell->nodes, true))
            ->toBeLessThan(array_search('edge-1', $shell->nodes, true));

        $shell->nodes = [];
        $service->disable($app);

        expect(array_search('edge-1', $shell->nodes, true))
            ->toBeLessThan(array_search('router-1', $shell->nodes, true));
    });
});

final class AnalyticsBindingRecordingRegistrar extends AnalyticsRouteRegistrar
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly bool $failCleanup = false,
    ) {}

    public function requireServiceRoute(): ProxyRoute
    {
        $this->calls[] = 'require-service-route';

        return new ProxyRoute(['domain' => self::ServiceDomain]);
    }

    /** @param list<string> $hosts */
    public function assertPublicHostsAvailable(Instance $app, array $hosts): void
    {
        $this->calls[] = 'assert-public-hosts:'.implode(',', $hosts);
    }

    /** @param list<string> $desiredHosts */
    public function removeObsoletePublicHosts(Instance $app, array $desiredHosts): void
    {
        $this->calls[] = 'remove-obsolete-public-hosts:'.implode(',', $desiredHosts);

        if ($this->failCleanup) {
            throw new RuntimeException('Public analytics route cleanup failed.');
        }
    }

    public function syncPublicHosts(AppAnalyticsBinding $binding): void
    {
        $this->calls[] = 'sync-public-hosts';
    }

    public function convergePublicHosts(AppAnalyticsBinding $binding): void
    {
        $this->calls[] = 'converge-public-hosts';
    }
}

function createAnalyticsRoutePrerequisites(bool $createServiceRoute = true): void
{
    Node::factory()
        ->router()
        ->create([
            'name' => 'router-1',
            'wireguard_address' => '10.6.0.2',
        ]);

    Node::factory()
        ->withActiveRole('analytics')
        ->create([
            'name' => 'analytics-1',
            'wireguard_address' => '10.6.0.50',
        ]);

    if ($createServiceRoute) {
        app(AnalyticsRouteRegistrar::class)->syncServiceRoute();
    }
}

function createAnalyticsApp(?string $domain = 'docs.test', bool $withIngress = true): Instance
{
    $ingress = $withIngress
        ? Node::factory()
            ->ingress()
            ->create([
                'name' => 'edge-1',
                'wireguard_address' => '10.6.0.10',
            ])
        : null;

    $appNode = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-1',
            'wireguard_address' => '10.6.0.21',
        ]);

    if ($ingress instanceof Node) {
        $appNode
            ->roleAssignments()
            ->where('role', 'app-prod')
            ->update(['settings' => ['ingress_node_id' => $ingress->id]]);
    }

    $app = App::factory()->create([
        'name' => 'docs',
    ]);

    return Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $appNode->id,
            domain: $domain,
        ),
    ]);
}
