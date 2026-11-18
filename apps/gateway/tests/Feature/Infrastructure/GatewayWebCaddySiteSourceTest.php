<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\Sources\GatewayWebCaddySiteSource;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

it('selects the prepared Caddy handlers only on the Node serving this Gateway', function (): void {
    $home = sys_get_temp_dir().'/orbit-gateway-site-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($home);
    try {
        config(['orbit.home' => $home]);
        $nodes = [];
        foreach ([2, 3] as $suffix) {
            $node = Node::withoutEvents(fn (): Node => Node::query()->create([
                'name' => "gateway-{$suffix}", 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
                'wireguard_ip' => "10.44.0.{$suffix}", 'public_ssh_host' => "10.44.0.{$suffix}", 'user' => 'orbit',
            ]));
            $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
            $nodes[] = $node;
        }
        [$other, $serving] = $nodes;
        app(GatewayServingHost::class)->remember($serving);
        $sites = app(GatewayWebCaddySiteSource::class);
        expect($sites->sites($serving)[0]->body)->toContain('php_fastcgi');
        touch($home.'/gateway-site-prepared');
        expect($sites->sites($serving)[0]->body)->toContain('import /etc/caddy/orbit-gateway-handlers.caddy')
            ->and($sites->sites($other)[0]->body)->toContain('php_fastcgi');
    } finally {
        File::deleteDirectory($home);
    }
});
