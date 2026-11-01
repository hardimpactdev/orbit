<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Starts canonical Gateway services from the committed mounted PR source. */
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
        $result = $this->host->exec($target->instance('gateway'), new GuestCommand(
            ['/usr/local/bin/converge-gateway.sh', 'configure-mounted'], 900,
        ));
        if (! $result->successful() || json_decode($result->stdout, true) !== [
            'gateway_install' => 'mounted_source', 'release_runner_exercised' => false,
        ]) {
            throw new RuntimeException((new GuestFailureDetail)->append('e2e.gateway_mounted_configuration_failed.', $result->stderr));
        }
    }
}
