<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use App\Domain\SwampCredentials\SwampGatewayCredential;
use App\Domain\SwampCredentials\SwampGatewayToken;
use App\Infrastructure\Gateway\GatewayCommandNode;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use RuntimeException;

/**
 * Installs a verified Gateway Swamp token on the Gateway host in container mode, keeping the guest's projection read-only.
 * Uses the same protected host installer script as a release (`swamp/scripts/gateway-swamp-token-install.py` as `orbit`, then
 * `sudo -n /usr/local/libexec/orbit-gateway-environment refresh`) over the Gateway's managed SSH to its own host Node.
 */
class SwampHostTokenInstaller
{
    public function __construct(
        private readonly SshExecutor $ssh,
        private readonly SshKeyProvider $keys,
        private readonly KnownHostsStore $knownHosts,
    ) {}

    public function install(SwampGatewayToken $kind, SwampGatewayCredential $credential): void
    {
        $node = GatewayCommandNode::find();
        if ($node === null || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new RuntimeException('gateway.swamp_credentials_host_unavailable');
        }
        $scriptPath = dirname(base_path(), 2).'/swamp/scripts/gateway-swamp-token-install.py';
        $script = @file_get_contents($scriptPath);
        if ($script === false || $script === '') {
            throw new RuntimeException('gateway.swamp_credentials_installer_missing');
        }
        $reveal = json_encode([
            'name' => $credential->name,
            'token' => $credential->token(),
            'expiresAt' => $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR);

        $result = $this->ssh->execute(
            new SshConnection(
                host: $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: 60.0,
            ),
            new RemoteCommand(
                arguments: [
                    'bash', '-euo', 'pipefail', '-c',
                    'python3 -I -c "$1" "$2" && sudo -n /usr/local/libexec/orbit-gateway-environment refresh',
                    'bash', $script, $kind->value,
                ],
                protectedInput: ProtectedInput::fromString($reveal),
                maxOutputBytes: 16_384,
                timeout: 60.0,
            ),
        );
        if (! $result->succeeded()) {
            throw new RuntimeException('gateway.swamp_credentials_install_failed: '.substr(trim($result->stderr), -500));
        }
    }
}
