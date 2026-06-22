<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class GatewayManagementSshKey
{
    public function publicKey(): string
    {
        $result = Process::timeout(30)->run('ssh-keygen -y -f ~/.ssh/id_ed25519');
        $publicKey = trim($result->output());

        if (! $result->successful() || $publicKey === '') {
            throw new RuntimeException('Gateway SSH public key could not be read.');
        }

        return $publicKey;
    }
}
