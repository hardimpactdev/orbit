<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Facades\Config;

/** Public capture for official SSH executed by Node 209 workers; no host effects. */
final readonly class SwampSshExecutionInput
{
    /** @return array{control_node_id:209,identity_file:string,public_key:string} */
    public function identity(): array
    {
        return [
            'control_node_id' => 209,
            'identity_file' => '/run/credentials/orbit-node-worker.service/proxy-ssh-identity',
            'public_key' => Config::string('swamp.ssh_public_key'),
        ];
    }

    /** @return array{control_node_id:209,identity_file:string,public_key:string,target:array{name:string,host:string,user:string,port:int,host_keys:list<string>}} */
    public function forNode(Node $node): array
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === ''
            || $node->user === '' || ! is_string($node->ssh_host_key_type) || $node->ssh_host_key_type === ''
            || ! is_string($node->ssh_host_key) || $node->ssh_host_key === '') {
            throw new ResourceOperationException('swamp.ssh_target_unavailable', 'Official SSH requires the stored target address, user and host key.', 503);
        }
        $identity = $this->identity();

        return [...$identity,
            'target' => ['name' => 'target', 'host' => $node->wireguard_ip, 'user' => $node->user, 'port' => 22,
                'host_keys' => [$node->ssh_host_key_type.' '.$node->ssh_host_key]],
        ];
    }
}
