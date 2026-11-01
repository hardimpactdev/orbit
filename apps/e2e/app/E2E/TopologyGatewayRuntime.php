<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Exports the committed PR source into an independent guest checkout without replacing the mount. */
final readonly class TopologyGatewayRuntime
{
    public function __construct(private IncusHost $host) {}

    public function prepare(TopologyTarget $target, string $worktree, string $commit): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new RuntimeException('e2e.gateway_runtime_revision_invalid.');
        }
        $head = Process::path($worktree)->run(['git', 'rev-parse', 'HEAD']);
        $clean = Process::path($worktree)->run(['git', 'status', '--porcelain', '--untracked-files=normal']);
        if (! $head->successful() || trim($head->output()) !== $commit || ! $clean->successful() || trim($clean->output()) !== '') {
            throw new RuntimeException('e2e.gateway_runtime_committed_source_required.');
        }
        $bundle = tempnam(sys_get_temp_dir(), 'orbit-gateway-bundle-');
        if ($bundle === false) {
            throw new RuntimeException('e2e.gateway_runtime_bundle_failed.');
        }
        try {
            $export = Process::path($worktree)->timeout(300)->run(['git', 'bundle', 'create', $bundle, 'HEAD']);
            if (! $export->successful()) {
                throw new RuntimeException('e2e.gateway_runtime_bundle_failed.');
            }
            $this->host->pushFiles(['gateway-runtime' => ['instance' => $target->instance('gateway'), 'source' => $bundle,
                'destination' => '/var/lib/orbit-e2e/gateway-source.bundle']]);
            $result = $this->host->exec($target->instance('gateway'), new GuestCommand(
                ['/usr/local/bin/converge-gateway.sh', 'runtime', $commit], 900,
            ));
            if (! $result->successful()) {
                throw new RuntimeException('e2e.gateway_runtime_checkout_failed.');
            }
        } finally {
            unlink($bundle);
        }
    }
}
