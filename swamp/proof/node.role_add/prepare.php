<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$node = Node::findOrFail((int) $argv[1]);
if (($argv[2] ?? null) === 'metrics') {
    // The synthetic caller has no SSH host. It must not appear in managed exporter work.
    Node::query()->where('name', 'operator')->update(['public_ssh_host' => null, 'ssh_host_key_type' => null,
        'ssh_host_key' => null, 'ssh_host_fingerprint' => null]);
}
if (isset($argv[2])) {
    $node->roles()->where('role', $argv[2])->delete();
} else {
    $node->roles()->delete();
}
