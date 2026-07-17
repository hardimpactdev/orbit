<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\AnalyticsDomainRequired;
use App\Exceptions\AnalyticsMutationBusy;
use App\Models\App;
use App\Models\AppAnalyticsBinding;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Analytics\AnalyticsPublicHostNormalizer;
use App\Services\Analytics\AnalyticsRouteRegistrar;
use App\Services\Analytics\AppAnalyticsBindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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

        expect(AppAnalyticsBinding::query()->where('app_id', $app->id)->exists())->toBeFalse();
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
            ->toThrow(InvalidArgumentException::class, 'Analytics public hosts must be valid hostnames.');

        expect(AppAnalyticsBinding::query()->where('app_id', $app->id)->exists())
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
            ->and(AppAnalyticsBinding::query()->where('app_id', $app->id)->exists())
            ->toBeFalse();
    });

    it('requires a configured app domain before enabling analytics', function (): void {
        createAnalyticsRoutePrerequisites();
        $app = createAnalyticsApp(domain: null);

        expect(fn () => app(AppAnalyticsBindingService::class)->enable($app, []))
            ->toThrow(
                AnalyticsDomainRequired::class,
                "App 'docs' requires a configured valid public domain before analytics can be enabled.",
            );

        expect(AppAnalyticsBinding::query()->where('app_id', $app->id)->exists())
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
                "App 'docs' requires a configured valid public domain before analytics can be enabled.",
            );

        expect(AppAnalyticsBinding::query()->where('app_id', $app->id)->exists())
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
            'app_id' => $app->id,
            'enabled' => true,
            'public_hosts' => ['analytics.docs.test'],
        ]);
        $registrar = new AnalyticsBindingRecordingRegistrar(failCleanup: true);

        expect(fn () => new AppAnalyticsBindingService($registrar, new AnalyticsPublicHostNormalizer)->disable($app))
            ->toThrow(RuntimeException::class, 'Public analytics route cleanup failed.');

        $binding = AppAnalyticsBinding::query()->where('app_id', $app->id)->firstOrFail();

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
    public function assertPublicHostsAvailable(App $app, array $hosts): void
    {
        $this->calls[] = 'assert-public-hosts:'.implode(',', $hosts);
    }

    /** @param list<string> $desiredHosts */
    public function removeObsoletePublicHosts(App $app, array $desiredHosts): void
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

function createAnalyticsApp(?string $domain = 'docs.test', bool $withIngress = true): App
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

    return App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'domain' => $domain,
    ]);
}
