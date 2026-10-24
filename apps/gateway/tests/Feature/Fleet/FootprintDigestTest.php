<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Fleet\FootprintArtifactSkipped;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\SourceClosure;
use App\Infrastructure\Fleet\Footprint\SourceDigest;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\ServiceProvider;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeNodeCaddyBuilds;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\ScriptedSshExecutor;

describe('footprint digests', function (): void {
    it('pins the Caddy digest to the code the Caddy build renders from, never to a rendered Caddyfile', function (): void {
        $inputs = app(CaddyFootprintArtifact::class)->inputs();
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds, contributions: app()->tagged(ContributedCaddySiteSource::class));
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);

        expect($inputs)->toContain(
            'app/Infrastructure/Caddy/Build/NodeCaddyfileRenderer.php',
            'app/Infrastructure/Caddy/Build/Sources/RouteCaddySiteSource.php',
            'app/Infrastructure/AppDev/DevelopmentSite.php',
            'app/Domain/Nodes/CaddyRelease.php',
            'extensions/ai-router/src/Infrastructure/AiRouterCaddySiteSource.php',
            'extensions/ai-router/src/Infrastructure/AiRouterCaddySiteRenderer.php',
        )
            ->and(array_filter($inputs, static fn (string $path): bool => str_starts_with($path, 'app/Models/')))->toBe([])
            ->and($artifact->digest($node))->toBe(SourceDigest::of($inputs))
            ->and($artifact->digest($node))->not->toBe(app(NodeCaddyfileRenderer::class)->render($node)->version);
    });

    it('walks extension source imports and same-namespace references', function (): void {
        $files = SourceClosure::of([], [AiRouterCaddySiteSource::class]);
        expect($files)->toContain(
            'extensions/ai-router/src/Infrastructure/AiRouterCaddySiteSource.php',
            'extensions/ai-router/src/Infrastructure/AiRouterCaddySiteRenderer.php',
            'extensions/ai-router/src/Domain/AiRouterState.php',
            'app/Domain/Settings/SettingRepository.php',
        );
        expect(array_filter($files, static fn (string $file): bool => str_contains($file, '/Models/')))->toBe([]);
    });

    it('refuses a start class outside core and extensions', function (): void {
        expect(fn (): array => SourceClosure::of([], [ServiceProvider::class]))
            ->toThrow(LogicException::class, 'The source closure reads only core and extension classes');
    });

    it('pins the private-DNS digest to the publication code and the record sources that extensions contribute', function (): void {
        $vpn = FleetFixtures::node('vpn', [RoleName::Vpn]);
        $artifact = new PrivateDnsFootprintArtifact(new class implements PrivateDnsManager
        {
            public function converge(?Node $pendingNode = null): void {}
        }, contributions: app()->tagged(ContributedPrivateHostnames::class));

        expect(app(PrivateDnsFootprintArtifact::class)->inputs())->toContain(
            'app/Infrastructure/AppDev/DevelopmentDnsConfigRenderer.php',
            'extensions/ai-router/src/Infrastructure/AiRouterPrivateHostnames.php',
            'extensions/ai-router/src/Domain/AiRouterState.php',
            'extensions/ai-router/src/Domain/AiRouterHostname.php',
        )
            ->and($artifact->digest($vpn))->toBe(hash('sha256', PrivateDnsListenerRelease::fromGateway()->id()."\n".SourceDigest::of(app(PrivateDnsFootprintArtifact::class)->inputs())));
    });

    it('carries the contributed collector script on the collector Node only', function (): void {
        $services = FleetFixtures::node('services', [RoleName::AppDev]);
        $other = FleetFixtures::node('other', [RoleName::AppDev]);
        app(AiRouterState::class)->enable($services->id, 'valkey', 'http://127.0.0.1:8317', 'management', 'read', 'control');
        $footprint = app(NodeFootprint::class);

        expect($footprint->expected($services)->artifacts)->toHaveKey('ai-router', hash('sha256', (string) file_get_contents(base_path('extensions/ai-router/resources/collector.py'))))
            ->and($footprint->expected($other)->artifacts)->not->toHaveKey('ai-router');
    });

    it('keeps the private-DNS digest when a Node joins and the records change', function (): void {
        $vpn = FleetFixtures::node('vpn', [RoleName::Vpn]);
        $artifact = new PrivateDnsFootprintArtifact(new class implements PrivateDnsManager
        {
            public function converge(?Node $pendingNode = null): void {}
        }, contributions: app()->tagged(ContributedPrivateHostnames::class));
        $before = $artifact->digest($vpn);

        FleetFixtures::node('joined', [RoleName::AppDev]);

        expect($artifact->digest($vpn))->toBe($before);
    });

    it('keeps the Caddy digest the same for every Node, whatever its sites', function (): void {
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds, contributions: app()->tagged(ContributedCaddySiteSource::class));

        expect($artifact->digest(FleetFixtures::node('dev', [RoleName::AppDev])))
            ->toBe($artifact->digest(FleetFixtures::node('prod', [RoleName::AppProd])));
    });

    it('skips a Caddyfile the render or validation refuses with its reason, and fails a broken publish', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $builds = new FakeNodeCaddyBuilds;
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), $builds, contributions: app()->tagged(ContributedCaddySiteSource::class));

        foreach (['render' => 'caddy_render_refused', 'validate' => 'caddy_validate_refused'] as $stage => $reason) {
            $builds->failures['dev'] = new NodeCaddyBuildException('dev', $stage, 'A site is invalid.');
            expect(fn () => $artifact->apply($node))->toThrow(fn (FootprintArtifactSkipped $skipped) => expect($skipped->reason)->toBe($reason));
        }

        $builds->failures['dev'] = new NodeCaddyBuildException('dev', 'reload', 'Caddy did not reload.');
        expect(fn () => $artifact->apply($node))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('node.footprint_caddy_failed'));
    });

    it('runs the Caddy package step through the agent on the Nodes that run Caddy, pinned to the program and the floor', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('caddy.install-package', ['changed' => false]);
        $caddy = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds, contributions: app()->tagged(ContributedCaddySiteSource::class));
        $artifact = new CaddyPackageFootprintArtifact($caddy, new AgentCaddyPackage($agents));
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $database = FleetFixtures::node('database', [RoleName::Database]);

        expect($artifact->name())->toBe('caddy-package')
            ->and($artifact->applies($dev))->toBeTrue()
            ->and($artifact->applies($database))->toBe($caddy->applies($database))
            ->and($artifact->digest($dev))->toBe(SourceDigest::of([
                'app/Infrastructure/Nodes/AgentCaddyPackage.php',
                'app/Domain/Nodes/CaddyRelease.php',
            ]))
            ->and($artifact->apply($dev))->toBeFalse()
            ->and($agents->calls[0]['input'])->toBe(AgentCaddyPackage::input());
    });

    it('reports a changed Caddy package and fails a refused one with its own code', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $caddy = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), new FakeNodeCaddyBuilds, contributions: app()->tagged(ContributedCaddySiteSource::class));

        expect((new CaddyPackageFootprintArtifact($caddy, new AgentCaddyPackage((new FakeAgentCommandRunner)->answer('caddy.install-package', ['changed' => true]))))->apply($node))->toBeTrue();

        $refused = new AgentCaddyPackage((new FakeAgentCommandRunner)->refuse('caddy.install-package', 'caddy.package_failed', ['reason' => 'The Caddy 2.11.7 package does not match the Orbit pin.']));
        expect(fn () => (new CaddyPackageFootprintArtifact($caddy, $refused))->apply($node))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.footprint_caddy_package_failed')
                ->and($exception->getMessage())->toContain('does not match the Orbit pin');
        });
    });

    it('publishes the tmpfiles rule on active Linux app-dev Nodes only', function (): void {
        $ssh = new ScriptedSshExecutor;
        $agents = (new FakeAgentCommandRunner)->answer('nodes.publish-tmpfiles', ['changed' => false]);
        $artifact = new TmpfilesFootprintArtifact($agents);
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $pending = FleetFixtures::node('pending');
        NodeRole::query()->create(['node_id' => $pending->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Provisioning]);

        expect($artifact->name())->toBe('tmpfiles')
            ->and($artifact->applies($dev))->toBeTrue()
            ->and($artifact->applies(FleetFixtures::node('database', [RoleName::Database])))->toBeFalse()
            ->and($artifact->applies(FleetFixtures::node('mac', [RoleName::AppDev], 'macos')))->toBeFalse()
            ->and($artifact->applies($pending->load('roles')))->toBeFalse()
            ->and($artifact->digest($dev))->toBe(hash('sha256', TmpfilesFootprintArtifact::Rule))
            ->and(TmpfilesFootprintArtifact::Rule)->toContain("\ne /tmp/orbit-* - - - 1d\ne /dev/shm/orbit-* - - - 1d\n");

        expect($artifact->apply($dev))->toBeFalse()
            ->and($agents->calls[0]['input'])->toBe(['rule' => TmpfilesFootprintArtifact::Rule])
            ->and(new TmpfilesFootprintArtifact((new FakeAgentCommandRunner)->answer('nodes.publish-tmpfiles', ['changed' => true]))->apply($dev))->toBeTrue();

        $refused = (new FakeAgentCommandRunner)->refuse('nodes.publish-tmpfiles', 'nodes.tmpfiles_failed', ['reason' => 'cannot move']);
        expect(fn () => new TmpfilesFootprintArtifact($refused)->apply($dev))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.footprint_tmpfiles_failed');
        });
    });
});
