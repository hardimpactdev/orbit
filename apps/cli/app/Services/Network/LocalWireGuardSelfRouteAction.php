<?php

declare(strict_types=1);

namespace App\Services\Network;

use Symfony\Component\Process\Process;

final readonly class LocalWireGuardSelfRouteAction
{
    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(mixed $address): array
    {
        $address = $this->address($address);
        $process = new Process(['ip', 'route', 'get', $address]);
        $process->setTimeout(10);
        $process->run();

        return [
            'data' => [
                'address' => $address,
                'command' => "ip route get {$address}",
                'exit_code' => $process->getExitCode(),
                'output' => trim($process->getOutput().$process->getErrorOutput()),
            ],
            'meta' => [],
        ];
    }

    private function address(mixed $value): string
    {
        if (is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }

        throw new LocalWireGuardSelfRouteFailure(
            errorCode: 'validation_failed',
            message: 'WireGuard address must be a valid IP address.',
            meta: ['field' => 'address'],
        );
    }
}
