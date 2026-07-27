<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteExecutor;
use Orbit\Core\Nodes\NodeSystemdServiceRenderer;
use RuntimeException;

class ProvisioningAgentInstaller
{
    public function __construct(
        private readonly RemoteExecutor $transport,
        private readonly ReleaseManifestResolver $manifests,
        private readonly NodeAgentServicePayloadBuilder $agentServices,
        private readonly FleetUpdateTargetSelector $targets,
        private readonly ?NodeSystemdServiceRenderer $systemdServices = null,
    ) {}

    public function install(Node $node): RemoteShellResult
    {
        if (! $node->isProvisioning()) {
            throw new RuntimeException('Provisioning Agent installation requires a provisioning node.');
        }

        $gateway = $this->targets->gatewayNode();

        if (! $gateway instanceof Node) {
            throw new RuntimeException('An active gateway identity is required to bootstrap Orbit Agent.');
        }

        $platform = CliArtifactPlatform::forNode($node);
        $artifact = $this->manifests->resolve()->agentArtifacts[$platform] ?? null;

        if ($artifact === null) {
            throw new RuntimeException(
                "Release manifest does not contain an Agent artifact for platform [{$platform}].",
            );
        }

        $service = $this->agentServices->forProvisioningNode($node, $gateway);

        // @orbit-ssh-lane provisioning-ssh
        $result = $this->transport->run(
            node: $node,
            script: $this->installScript($artifact, $service),
            options: [
                'timeout' => 300,
                'input' => $this->installInput($service),
                'strict' => true,
            ],
        );

        if ($result->successful() || ! str_ends_with(trim($result->stdout), 'agent-ready')) {
            return $result;
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $result->stdout,
            stderr: $result->stderr,
            durationMs: $result->durationMs,
        );
    }

    /**
     * @param  array{url: string, sha256: string}  $artifact
     * @param  array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}  $service
     */
    private function installScript(array $artifact, array $service): string
    {
        $artifactUrl = escapeshellarg($artifact['url']);
        $artifactSha256 = escapeshellarg(strtolower($artifact['sha256']));
        $binaryPath = escapeshellarg($service['exec_start']);
        $configPath = escapeshellarg($service['config_path']);
        $caPath = escapeshellarg($service['ca_path']);
        $serviceUser = escapeshellarg($service['user']);
        $httpBind = escapeshellarg($service['http_bind']);
        $commandUrl = escapeshellarg('http://'.$service['http_bind'].'/v1/commands');
        $unitName = escapeshellarg($service['unit_name'].'.service');
        $unitPath = escapeshellarg('/etc/systemd/system/'.$service['unit_name'].'.service');
        $systemdServices = $this->systemdServices ?? new NodeSystemdServiceRenderer;
        $agentUnitBase64 = escapeshellarg(base64_encode($systemdServices->agentUnit(
            user: $service['user'],
            agentBinary: $service['exec_start'],
            orbitBinary: dirname($service['exec_start']).'/orbit',
            configPath: $service['config_path'],
            httpBind: $service['http_bind'],
        )));
        $runtimeBootScriptBase64 = escapeshellarg(base64_encode($systemdServices->runtimeBootScript()));
        $runtimeBootUnitBase64 = escapeshellarg(base64_encode($systemdServices->runtimeBootUnit()));
        $runtimeBootScriptPath = escapeshellarg(NodeSystemdServiceRenderer::RuntimeBootScriptPath);
        $runtimeBootUnit = escapeshellarg(NodeSystemdServiceRenderer::RuntimeBootUnitName);
        $runtimeBootUnitPath = escapeshellarg(NodeSystemdServiceRenderer::RuntimeBootUnitPath);

        return <<<BASH
            set -euo pipefail
            tmp="\$(mktemp -d "\${TMPDIR:-/tmp}/orbit-agent-bootstrap.XXXXXX")"
            cleanup() { rm -rf "\$tmp"; }
            trap cleanup EXIT

            IFS= read -r agent_config_base64
            IFS= read -r root_ca_base64

            curl -fksSL --retry 3 --retry-delay 2 {$artifactUrl} -o "\$tmp/orbit-agent-linux-x64"
            printf '%s  %s\n' {$artifactSha256} "\$tmp/orbit-agent-linux-x64" | sha256sum -c -
            chmod 0755 "\$tmp/orbit-agent-linux-x64"

            sudo install -d -m 0755 -o {$serviceUser} -g {$serviceUser} "\$(dirname {$binaryPath})"
            sudo install -d -m 0700 -o {$serviceUser} -g {$serviceUser} "\$(dirname {$configPath})"
            sudo install -d -m 0755 -o {$serviceUser} -g {$serviceUser} "\$(dirname {$caPath})"
            printf '%s' "\$agent_config_base64" | base64 --decode > "\$tmp/agent.toml"
            printf '%s' "\$root_ca_base64" | base64 --decode > "\$tmp/root.crt"
            sudo install -m 0755 -o {$serviceUser} -g {$serviceUser} "\$tmp/orbit-agent-linux-x64" {$binaryPath}
            sudo install -m 0600 -o {$serviceUser} -g {$serviceUser} "\$tmp/agent.toml" {$configPath}
            sudo install -m 0644 -o {$serviceUser} -g {$serviceUser} "\$tmp/root.crt" {$caPath}

            printf '%s' {$agentUnitBase64} | base64 --decode > "\$tmp/orbit-agent.service"
            printf '%s' {$runtimeBootScriptBase64} | base64 --decode > "\$tmp/orbit-runtime-boot-converge"
            printf '%s' {$runtimeBootUnitBase64} | base64 --decode > "\$tmp/orbit-runtime-boot-converge.service"
            sudo install -d -m 0755 "\$(dirname {$runtimeBootScriptPath})"
            sudo install -m 0755 "\$tmp/orbit-runtime-boot-converge" {$runtimeBootScriptPath}
            sudo install -m 0644 "\$tmp/orbit-runtime-boot-converge.service" {$runtimeBootUnitPath}
            sudo install -m 0644 "\$tmp/orbit-agent.service" {$unitPath}
            sudo systemctl daemon-reload
            sudo systemctl enable {$runtimeBootUnit}
            sudo systemctl enable {$unitName}
            sudo systemctl restart {$unitName}

            for attempt in \$(seq 1 30); do
                command_status="\$(curl -sS --max-time 2 -o /dev/null -w '%{http_code}' {$commandUrl} || true)"

                if [ "\$command_status" = 405 ]; then
                    printf '%s\n' agent-ready
                    exit 0
                fi

                sleep 1
            done

            sudo systemctl status {$unitName} --no-pager >&2 || true
            printf 'Orbit Agent did not become ready on %s.\n' {$httpBind} >&2
            exit 1
            BASH;
    }

    /**
     * @param  array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}  $service
     */
    private function installInput(array $service): string
    {
        return base64_encode($service['config'])."\n".base64_encode($service['ca_pem'])."\n";
    }
}
