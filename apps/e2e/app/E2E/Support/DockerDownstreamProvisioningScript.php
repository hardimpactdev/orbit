<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class DockerDownstreamProvisioningScript
{
    /**
     * @param  array<string, string>  $tasks
     * @param  array<string, string>  $afterSuccessfulTasks
     */
    public static function make(array $tasks, array $afterSuccessfulTasks = []): string
    {
        $lines = [
            'set -euo pipefail;',
            'STATUS=0;',
        ];
        $pids = [];

        foreach ($tasks as $role => $command) {
            $pid = 'PID_NODE_NEW_'.strtoupper(str_replace('-', '_', $role));
            $log = "/tmp/orbit-e2e-docker-node-new-{$role}.log";
            $lines[] = "({$command}) > {$log} 2>&1 & {$pid}=\$!;";
            $pids[$role] = [$pid, $log];
        }

        foreach ($pids as $role => [$pid, $log]) {
            $lines[] = "wait \"\${$pid}\" || { CODE=\$?; echo \"Docker downstream {$role} provisioning failed\" >&2; cat {$log} >&2 || true; if [ \"\$STATUS\" -eq 0 ]; then STATUS=\$CODE; fi; };";
        }

        $lines[] = 'if [ "$STATUS" -ne 0 ]; then exit "$STATUS"; fi;';

        foreach ($afterSuccessfulTasks as $role => $command) {
            $pid = 'PID_NODE_NEW_'.strtoupper(str_replace('-', '_', $role));
            $log = "/tmp/orbit-e2e-docker-node-new-{$role}.log";
            $lines[] = "({$command}) > {$log} 2>&1 & {$pid}=\$!;";
            $lines[] = "wait \"\${$pid}\" || { CODE=\$?; echo \"Docker downstream {$role} provisioning failed\" >&2; cat {$log} >&2 || true; exit \"\$CODE\"; };";
        }

        $lines[] = 'exit "$STATUS";';

        return implode("\n", $lines);
    }
}
