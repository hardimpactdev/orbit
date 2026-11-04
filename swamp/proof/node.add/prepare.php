<?php

declare(strict_types=1);

use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$hub = Node::query()->where('name', 'target')->sole();
$hub->roles()->delete();
$hub->roles()->create(['role' => 'vpn', 'status' => 'active']);
$hub->update(['wireguard_ip' => '10.232.0.1', 'wireguard_public_key' => $argv[1]]);
app(VpnSettings::class)->configure(subnet: '10.232.0.0/24', endpoint: '10.231.0.10:51820');
Node::query()->whereHas('roles', fn ($query) => $query->where('role', 'gateway'))->update(['wireguard_ip' => '10.232.0.2', 'wireguard_public_key' => null]);
// The caller and Gateway use the routed proof bridge; neither owns a tunnel peer.
Node::query()->where('name', 'operator')->update(['wireguard_public_key' => null]);
Node::query()->where('name', 'metrics')->sole()->delete();
