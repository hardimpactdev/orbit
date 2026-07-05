<?php

declare(strict_types=1);

namespace App\Services\Network;

use Symfony\Component\Process\Process;

final readonly class LocalWireGuardInterfacePublicKeyReadAction
{
    /**
     * @return array{public_key: string}
     */
    public function run(): array
    {
        $process = new Process(['sudo', 'wg', 'show', 'wg-orbit', 'public-key']);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new LocalWireGuardInterfacePublicKeyReadFailure(
                errorCode: 'wireguard_interface_public_key_unavailable',
                message: 'WireGuard interface public key could not be read.',
                meta: [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => trim($process->getErrorOutput()),
                ],
            );
        }

        $publicKey = trim($process->getOutput());

        if ($publicKey === '') {
            throw new LocalWireGuardInterfacePublicKeyReadFailure(
                errorCode: 'wireguard_interface_public_key_empty',
                message: 'WireGuard interface public key is empty.',
            );
        }

        return ['public_key' => $publicKey];
    }
}
