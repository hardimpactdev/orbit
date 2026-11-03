<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$node = Node::findOrFail((int) $argv[1]);
$version = app(NativeCaddyPublicationInput::class)->forNode($node)['caddy']['version'];
$operation = app(StartOperationAction::class)->execute('route.caddy_converge', 'node_route_projection', $node->id, $node->id,
    ['reason' => 'rule14-proof'], 'operator', 'proof-'.$argv[2]);
echo json_encode(['bindings' => ['operation' => $operation->id, 'version' => $version]]), PHP_EOL;
