<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$node = Node::findOrFail((int) $argv[1]);
if (isset($argv[2])) {
    $node->roles()->where('role', $argv[2])->delete();
} else {
    $node->roles()->delete();
}
