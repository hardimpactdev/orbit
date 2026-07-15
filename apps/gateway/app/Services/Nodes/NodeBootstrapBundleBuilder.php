<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Operations\CliArtifactPlatform;
use App\Services\Operations\NodeAgentServicePayloadBuilder;
use App\Services\Operations\ReleaseManifestResolver;
use RuntimeException;
use SensitiveParameter;

final readonly class NodeBootstrapBundleBuilder
{
    public function __construct(
        private ReleaseManifestResolver $manifests,
        private NodeAgentServicePayloadBuilder $agentServices,
    ) {}

    public function build(
        Node $node,
        Node $gateway,
        WireGuardPeer $peer,
        #[SensitiveParameter]
        string $wireguardConfig,
    ): string {
        if (! $node->isProvisioning() || $peer->node_id !== $node->id) {
            throw new RuntimeException('A provisioning node with its WireGuard peer is required.');
        }

        $platform = CliArtifactPlatform::forNode($node);
        $manifest = $this->manifests->resolve();
        $cliArtifact = $manifest->cliArtifacts[$platform] ?? null;
        $agentArtifact = $manifest->agentArtifacts[$platform] ?? null;

        if (! is_array($cliArtifact)) {
            throw new RuntimeException("Release manifest does not contain a CLI artifact for platform [{$platform}].");
        }

        if (! is_array($agentArtifact)) {
            throw new RuntimeException(
                "Release manifest does not contain an Agent artifact for platform [{$platform}].",
            );
        }

        $service = $this->agentServices->forProvisioningNode($node, $gateway);

        return $this->script(
            cliArtifact: $cliArtifact,
            agentArtifact: $agentArtifact,
            wireguardConfig: $wireguardConfig,
            service: $service,
        );
    }

    /**
     * @param  array{url: string, sha256: string}  $cliArtifact
     * @param  array{url: string, sha256: string}  $agentArtifact
     * @param  array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}  $service
     */
    private function script(
        array $cliArtifact,
        array $agentArtifact,
        #[SensitiveParameter]
        string $wireguardConfig,
        array $service,
    ): string {
        $runtimeUser = trim($service['user']);
        $home = $runtimeUser === 'root' ? '/root' : "/home/{$runtimeUser}";
        $cliPath = "{$home}/.local/bin/orbit";
        $runtimeUserShell = escapeshellarg($runtimeUser);
        $cliUrl = escapeshellarg($cliArtifact['url']);
        $cliSha256 = escapeshellarg(strtolower($cliArtifact['sha256']));
        $agentUrl = escapeshellarg($agentArtifact['url']);
        $agentSha256 = escapeshellarg(strtolower($agentArtifact['sha256']));
        $cliPathShell = escapeshellarg($cliPath);
        $agentPath = escapeshellarg($service['exec_start']);
        $agentConfigPath = escapeshellarg($service['config_path']);
        $agentCaPath = escapeshellarg($service['ca_path']);
        $agentUser = escapeshellarg($service['user']);
        $agentUnit = escapeshellarg($service['unit_name'].'.service');
        $agentUnitPath = escapeshellarg('/etc/systemd/system/'.$service['unit_name'].'.service');
        $wireguardBase64 = escapeshellarg(base64_encode($wireguardConfig));
        $agentConfigBase64 = escapeshellarg(base64_encode($service['config']));
        $agentCaBase64 = escapeshellarg(base64_encode($service['ca_pem']));

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail

            if ! command -v sudo >/dev/null 2>&1; then
                if [ "$(id -u)" -ne 0 ]; then
                    printf 'Bootstrap user must be root or have passwordless sudo.\n' >&2
                    exit 1
                fi
                apt-get -o DPkg::Lock::Timeout=300 update -qq
                DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq sudo
            fi
            if [ "$(id -u)" -ne 0 ] && ! sudo -n true; then
                printf 'Bootstrap user must have passwordless sudo.\n' >&2
                exit 1
            fi

            RUNTIME_USER={$runtimeUserShell}
            if ! id -u "\$RUNTIME_USER" >/dev/null 2>&1; then
                sudo useradd -m -s /bin/bash "\$RUNTIME_USER"
            fi
            sudo usermod -s /bin/bash "\$RUNTIME_USER" 2>/dev/null || true
            sudo usermod -p '*' "\$RUNTIME_USER" 2>/dev/null || true
            sudo usermod -aG sudo "\$RUNTIME_USER" 2>/dev/null || true
            sudo groupadd -f docker
            sudo usermod -aG docker "\$RUNTIME_USER"
            RUNTIME_HOME="\$(getent passwd "\$RUNTIME_USER" | cut -d: -f6)"
            if [ -z "\$RUNTIME_HOME" ]; then
                printf 'Could not resolve home for Orbit runtime user %s.\n' "\$RUNTIME_USER" >&2
                exit 1
            fi

            sudo install -d -m 0755 -o "\$RUNTIME_USER" -g "\$RUNTIME_USER" "\$RUNTIME_HOME/.local"
            sudo install -d -m 0755 -o "\$RUNTIME_USER" -g "\$RUNTIME_USER" "\$RUNTIME_HOME/.config"
            sudo install -d -m 0700 -o "\$RUNTIME_USER" -g "\$RUNTIME_USER" "\$RUNTIME_HOME/.ssh"
            BOOTSTRAP_KEYS="\${HOME:-}/.ssh/authorized_keys"
            if [ "\$(id -u)" -eq 0 ]; then
                BOOTSTRAP_KEYS="/root/.ssh/authorized_keys"
            fi
            if [ -s "\$BOOTSTRAP_KEYS" ] && [ "\$BOOTSTRAP_KEYS" != "\$RUNTIME_HOME/.ssh/authorized_keys" ]; then
                sudo install -m 0600 -o "\$RUNTIME_USER" -g "\$RUNTIME_USER" "\$BOOTSTRAP_KEYS" "\$RUNTIME_HOME/.ssh/authorized_keys"
            fi
            printf '%s ALL=(ALL:ALL) NOPASSWD:ALL\n' "\$RUNTIME_USER" | sudo tee /etc/sudoers.d/99-orbit >/dev/null
            sudo chmod 0440 /etc/sudoers.d/99-orbit

            if ! command -v curl >/dev/null 2>&1 || ! command -v wg >/dev/null 2>&1 || ! command -v wg-quick >/dev/null 2>&1; then
                sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
                sudo DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y -qq ca-certificates curl wireguard wireguard-tools
            fi

            tmp="\$(mktemp -d "\${TMPDIR:-/tmp}/orbit-node-bootstrap.XXXXXX")"
            cleanup() { rm -rf "\$tmp"; }
            trap cleanup EXIT

            curl -fsSL --retry 3 --retry-delay 2 {$cliUrl} -o "\$tmp/orbit"
            printf '%s  %s\n' {$cliSha256} "\$tmp/orbit" | sha256sum -c -
            chmod 0755 "\$tmp/orbit"
            sudo install -d -m 0755 -o "\$RUNTIME_USER" -g "\$RUNTIME_USER" "\$(dirname {$cliPathShell})"
            sudo install -m 0755 -o "\$RUNTIME_USER" -g "\$RUNTIME_USER" "\$tmp/orbit" {$cliPathShell}

            curl -fsSL --retry 3 --retry-delay 2 {$agentUrl} -o "\$tmp/orbit-agent"
            printf '%s  %s\n' {$agentSha256} "\$tmp/orbit-agent" | sha256sum -c -
            chmod 0755 "\$tmp/orbit-agent"

            printf '%s' {$wireguardBase64} | base64 --decode > "\$tmp/wg-orbit.conf"
            sudo install -d -m 0700 /etc/wireguard
            sudo install -m 0600 -o root -g root "\$tmp/wg-orbit.conf" /etc/wireguard/wg-orbit.conf

            printf '%s' {$agentConfigBase64} | base64 --decode > "\$tmp/agent.toml"
            printf '%s' {$agentCaBase64} | base64 --decode > "\$tmp/root.crt"
            sudo install -d -m 0755 -o {$agentUser} -g {$agentUser} "\$(dirname {$agentPath})"
            sudo install -d -m 0700 -o {$agentUser} -g {$agentUser} "\$(dirname {$agentConfigPath})"
            sudo install -d -m 0755 -o {$agentUser} -g {$agentUser} "\$(dirname {$agentCaPath})"
            sudo install -m 0755 -o {$agentUser} -g {$agentUser} "\$tmp/orbit-agent" {$agentPath}
            sudo install -m 0600 -o {$agentUser} -g {$agentUser} "\$tmp/agent.toml" {$agentConfigPath}
            sudo install -m 0644 -o {$agentUser} -g {$agentUser} "\$tmp/root.crt" {$agentCaPath}

            cat > "\$tmp/orbit-agent.service" <<'UNIT'
            [Unit]
            Description=Orbit Agent
            After=network-online.target wg-quick@wg-orbit.service
            Wants=network-online.target
            Requires=wg-quick@wg-orbit.service

            [Service]
            Type=simple
            User={$service['user']}
            Environment=ORBIT_AGENT_CONFIG={$service['config_path']}
            Environment=ORBIT_AGENT_HTTP_BIND={$service['http_bind']}
            Environment=ORBIT_AGENT_ORBIT_BINARY={$cliPath}
            ExecStart={$service['exec_start']}
            Restart=always
            RestartSec=3

            [Install]
            WantedBy=multi-user.target
            UNIT

            sudo install -m 0644 "\$tmp/orbit-agent.service" {$agentUnitPath}
            sudo systemctl daemon-reload
            sudo systemctl enable wg-quick@wg-orbit >/dev/null
            sudo systemctl restart wg-quick@wg-orbit
            sudo systemctl enable {$agentUnit} >/dev/null
            sudo systemctl restart {$agentUnit}
            BASH;
    }
}
