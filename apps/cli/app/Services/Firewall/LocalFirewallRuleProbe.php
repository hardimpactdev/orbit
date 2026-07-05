<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use Symfony\Component\Process\Process;

final readonly class LocalFirewallRuleProbe
{
    /**
     * @return array{output: string}
     */
    public function check(): array
    {
        $status = $this->runProcess(['sudo', 'ufw', 'status', 'numbered']);

        if (! $status->isSuccessful()) {
            throw new LocalFirewallRuleProbeFailure(
                errorCode: 'firewall_rule.probe_failed',
                message: 'Firewall backend could not be inspected.',
                meta: [
                    'exit_code' => $status->getExitCode(),
                    'stderr' => trim($status->getErrorOutput()),
                ],
            );
        }

        $storedRules = $this->runProcess([
            'sudo',
            'awk',
            <<<'AWK'
                FILENAME ~ /user6\.rules$/ && /^-A ufw6-user-input/ { print "__orbit_ufw_file:user6:" $0 }
                FILENAME ~ /user\.rules$/ && /^-A ufw-user-input/ { print "__orbit_ufw_file:user:" $0 }
                AWK,
            '/etc/ufw/user.rules',
            '/etc/ufw/user6.rules',
        ]);

        $output = trim($status->getOutput())."\n";

        if ($storedRules->isSuccessful() && trim($storedRules->getOutput()) !== '') {
            $output .= trim($storedRules->getOutput())."\n";
        }

        return ['output' => $output];
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->run();

        return $process;
    }
}
