<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 3).'/apps/gateway';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$precondition = '/home/orbit/.orbit/swamp-proof/role-refusal-node.json';
if (($argv[2] ?? '') === 'precondition') {
    $node = Node::findOrFail((int) $argv[1]);
    $attributes = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($precondition, json_encode(['id' => $node->id,
        'before' => $node->only(array_keys($attributes)), 'expected' => $attributes], JSON_THROW_ON_ERROR));
    $node->forceFill($attributes)->save();
    exit(0);
}
if (in_array($argv[2] ?? '', ['snapshot', 'verify'], true)) {
    $snapshot = json_encode(App\Models\NodeRole::query()->orderBy('id')->get(['id', 'node_id', 'role', 'status'])->toArray(), JSON_THROW_ON_ERROR);
    $path = '/home/orbit/.orbit/swamp-proof/role-refusal.json';
    if ($argv[2] === 'snapshot') {
        file_put_contents($path, $snapshot);
    } elseif (file_get_contents($path) !== $snapshot) {
        throw new RuntimeException('The refused request changed role records.');
    }
    if ($argv[2] === 'verify' && is_file($precondition)) {
        $saved = json_decode(file_get_contents($precondition), true, flags: JSON_THROW_ON_ERROR);
        $node = Node::findOrFail($saved['id']);
        $actual = $node->only(array_keys($saved['expected']));
        // Compare persisted scalar values, including enum-backed attributes.
        if (json_encode($actual, JSON_THROW_ON_ERROR) !== json_encode($saved['expected'], JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('The refused request changed its Node preconditions.');
        }
        $node->forceFill($saved['before'])->save();
        unlink($precondition);
    }
    exit(0);
}
$node = Node::findOrFail((int) $argv[1]);
if (isset($argv[2])) {
    $node->roles()->where('role', $argv[2])->delete();
} else {
    $node->roles()->delete();
}

// This fixture changes only the disposable Gateway's recipe before admission.
if (($argv[3] ?? null) === 'missing-mirror') {
    $path = $root.'/resources/machine-software/recipe.json';
    $recipe = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $recipe['artifact_mirror'] = 'https://github.com/hardimpactdev/orbit/releases/download/proof-missing-deb-mirror';
    file_put_contents($path, json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

if (($argv[3] ?? null) === 'corrupt-mirror') {
    $path = $root.'/resources/machine-software/recipe.json';
    $recipe = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    // A pinned public README responds 200 with wrong bytes, regardless of the appended artifact filename.
    $recipe['artifact_mirror'] = 'https://raw.githubusercontent.com/hardimpactdev/orbit/a2bfa79fff5348324a3b57934392054832d7b577/README.md?corrupt-artifact=';
    file_put_contents($path, json_encode($recipe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
