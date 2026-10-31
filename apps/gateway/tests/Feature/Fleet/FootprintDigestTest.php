<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\PrivateDnsListenerRelease;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\SourceClosure;
use App\Infrastructure\Fleet\Footprint\SourceDigest;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Models\NodeRole;
use Illuminate\Support\ServiceProvider;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Tests\Support\Fleet\FleetFixtures;

describe('footprint digests', function (): void {
    it('pins the Caddy digest to the code the Caddy build renders from, never to a rendered Caddyfile', function (): void {
        $inputs = app(CaddyFootprintArtifact::class)->inputs();
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), contributions: app()->tagged(ContributedCaddySiteSource::class));
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
        $artifact = new PrivateDnsFootprintArtifact(contributions: app()->tagged(ContributedPrivateHostnames::class));

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

        expect($footprint->expected($services)->artifacts)->toHaveKey('ai-router')
            ->and($footprint->expected($other)->artifacts)->not->toHaveKey('ai-router');
    });

    it('keeps the private-DNS digest when a Node joins and the records change', function (): void {
        $vpn = FleetFixtures::node('vpn', [RoleName::Vpn]);
        $artifact = new PrivateDnsFootprintArtifact(contributions: app()->tagged(ContributedPrivateHostnames::class));
        $before = $artifact->digest($vpn);

        FleetFixtures::node('joined', [RoleName::AppDev]);

        expect($artifact->digest($vpn))->toBe($before);
    });

    it('keeps the Caddy digest the same for every Node, whatever its sites', function (): void {
        $artifact = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), contributions: app()->tagged(ContributedCaddySiteSource::class));

        expect($artifact->digest(FleetFixtures::node('dev', [RoleName::AppDev])))
            ->toBe($artifact->digest(FleetFixtures::node('prod', [RoleName::AppProd])));
    });

    it('runs the Caddy package step through the agent on the Nodes that run Caddy, pinned to the program and the floor', function (): void {
        $caddy = new CaddyFootprintArtifact(app(NodeCaddyfileRenderer::class), contributions: app()->tagged(ContributedCaddySiteSource::class));
        $artifact = new CaddyPackageFootprintArtifact($caddy);
        $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
        $database = FleetFixtures::node('database', [RoleName::Database]);

        expect($artifact->name())->toBe('caddy-package')
            ->and($artifact->applies($dev))->toBeTrue()
            ->and($artifact->applies($database))->toBe($caddy->applies($database))
            ->and($artifact->digest($dev))->toBe(SourceDigest::of([
                'app/Infrastructure/Nodes/AgentCaddyPackage.php',
                'app/Domain/Nodes/CaddyRelease.php',
            ]));
    });

    it('publishes the tmpfiles rule on active Linux app-dev Nodes only', function (): void {
        $artifact = new TmpfilesFootprintArtifact;
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

    });
});
