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
        $publicKey = trim(Config::string('swamp.ssh_public_key'));
        if (preg_match('/\Assh-ed25519 ([A-Za-z0-9+\/]{68})(?: [^\r\n]+)?\z/', $publicKey, $match) !== 1
            || ! str_starts_with((string) base64_decode($match[1], true), pack('N', 11).'ssh-ed25519'.pack('N', 32))) {
            throw new ResourceOperationException('swamp.ssh_identity_unavailable', 'Configure the swamp worker SSH public key before starting native SSH work.', 503);
        }

        return [
            'control_node_id' => 209,
            'identity_file' => '/etc/orbit/swamp-role-worker/ssh-identity',
            'public_key' => $publicKey,
        ];
    }

    /** A Node in {@see PendingSshHostKey} gets `host_keys: null`, which its run binds before use.
     * @return array{control_node_id:209,identity_file:string,public_key:string,target:array{name:string,host:string,user:string,port:int,host_keys:?list<string>}}
     */
    public function forNode(Node $node): array
    {
        $pending = app(PendingSshHostKey::class)->covers($node->id);
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '' || $node->user === '' || (! $pending
            && (! is_string($node->ssh_host_key_type) || $node->ssh_host_key_type === ''
            || ! is_string($node->ssh_host_key) || $node->ssh_host_key === ''))) {
            throw new ResourceOperationException('swamp.ssh_target_unavailable', 'Official SSH requires the stored target address, user and host key.', 503);
        }
        $identity = $this->identity();

        return [...$identity,
            'target' => ['name' => 'target', 'host' => $node->wireguard_ip, 'user' => $node->user, 'port' => 22,
                'host_keys' => $pending ? null : [$node->ssh_host_key_type.' '.$node->ssh_host_key]],
        ];
    }
}
