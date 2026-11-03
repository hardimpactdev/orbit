<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array($argv[2] ?? '', ['snapshot', 'verify'], true)) {
    $snapshot = json_encode(App\Models\NodeRole::query()->orderBy('id')->get(['id', 'node_id', 'role', 'status'])->toArray(), JSON_THROW_ON_ERROR);
    $path = '/home/orbit/.orbit/swamp-proof/role-refusal.json';
    if ($argv[2] === 'snapshot') {
        file_put_contents($path, $snapshot);
    } elseif (file_get_contents($path) !== $snapshot) {
        throw new RuntimeException('The refused request changed role records.');
    }
    exit(0);
}
$node = Node::findOrFail((int) $argv[1]);
if (isset($argv[2])) {
    $node->roles()->where('role', $argv[2])->delete();
} else {
    $node->roles()->delete();
}
