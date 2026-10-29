<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\NodeProvisioningException;

/** Serializes the fresh peer read, render and hub publication across different Node Operations. */
final readonly class NodeWireGuardProjectionLock
{
    public function __construct(private string $orbitHome) {}

    /** @template T
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $directory = rtrim($this->orbitHome, '/').'/locks';
        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw $this->failure();
        }
        chmod($directory, 0o700);
        $lock = fopen($directory.'/wireguard-server.lock', 'c+');
        if ($lock === false) {
            throw $this->failure();
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw $this->failure();
            }

            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function failure(): NodeProvisioningException
    {
        return new NodeProvisioningException('wireguard-server-lock', 'vpn.server_lock_failed', 'Could not converge the gateway WireGuard service.');
    }
}
