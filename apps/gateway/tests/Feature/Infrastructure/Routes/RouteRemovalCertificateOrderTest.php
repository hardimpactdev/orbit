<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Models\Node;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

it('refuses to remove a certificate that a stored site still names', function (): void {
    $node = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy, 'node_id' => $node->id,
        'domain' => 'collector.lab.orbit', 'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $node->id, 'upstream' => 'http://127.0.0.1:4788']);
    $route->update(['status' => RouteStatus::Active]);
    $agents = new FakeAgentCommandRunner;
    $certificates = new RemoteAppDevCertificateManager($agents,
        Mockery::mock(LeafCertificateSigner::class), Mockery::mock(ManagedUserAccountResolver::class));

    expect(fn () => $certificates->removeCustomProxy($route, $node))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->step)->toBe('certificate-remove')
                ->and($exception->errorCode)->toBe('app-dev.certificate_in_use')
                ->and($exception->getMessage())->toContain('[collector.lab.orbit]');
        });

    expect($agents->calls)->toBe([])
        ->and($route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($route->fresh()->sites_published)->toBeTrue();
});
