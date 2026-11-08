<?php

declare(strict_types=1);

// Test support only: read exact native artifacts on the nested Gateway, never latest behavior output.
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../apps/gateway/vendor/autoload.php';
$app = require __DIR__.'/../../apps/gateway/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$operation = Operation::query()->findOrFail($input['operation']);
if (! in_array($operation->kind, ['node.role_add', 'node.role_converge'], true)) {
    throw new InvalidArgumentException('Expected a role Operation.');
}
$reader = app(SwampCommandStep::class);
// Reuse the production transport and exact-artifact resolution without adding a public product API for test support.
$history = new ReflectionMethod($reader, 'history');
$artifact = new ReflectionMethod($reader, 'artifactResult');
$run = $history->invoke($reader, $operation->state['swamp_run_id']);
$result = [];
foreach ($run['jobs'] as $job) {
    foreach ($job['steps'] as $step) {
        if (! in_array($step['name'], $input['phases'], true)) {
            continue;
        }
        $output = $step['outputs']['stdout'] ?? '';
        if (isset($step['dataArtifacts']) && $step['dataArtifacts'] !== []) {
            $output = $artifact->invoke($reader, $step, 'output')['stdout'] ?? '';
        }
        // Recaps are at the end of full command output; the 4 KiB history summary cannot prove no changes.
        $recap = str_contains($output, 'PLAY RECAP') ? substr($output, strrpos($output, 'PLAY RECAP')) : '';
        preg_match_all('/^([^\s:]+)\s+:\s+ok=(\d+)\s+changed=(\d+)\s+unreachable=(\d+)\s+failed=(\d+)/m', $recap, $rows, PREG_SET_ORDER);
        $result[$step['name']] = ['status' => $step['status'], 'hosts' => array_map(fn ($row) => [
            'host' => $row[1], 'ok' => (int) $row[2], 'changed' => (int) $row[3], 'unreachable' => (int) $row[4], 'failed' => (int) $row[5],
        ], $rows)];
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
