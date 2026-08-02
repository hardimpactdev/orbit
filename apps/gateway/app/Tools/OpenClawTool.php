<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * @mago-expect lint:too-many-methods
 */
final class OpenClawTool extends BaseTool
{
    /**
     * Managed web runtime port when Hermes occupies 8080 on the same agent node.
     */
    public const int WEB_PORT = 8081;

    public const string TOKEN_FILE = '/home/agent/.openclaw/gateway.token';

    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux'];

    protected const ?string RUNTIME_USER = 'agent';

    protected const bool REQUIRES_ROUTE_TLD = true;

    protected const ?string ISOLATION = 'unprivileged-user';

    public function slug(): string
    {
        return 'openclaw';
    }

    #[\Override]
    public function category(): string
    {
        return 'agent';
    }

    #[\Override]
    public function capabilities(): array
    {
        return ['install', 'remove', 'update', 'reconfigure', 'credentials', 'safe-fix', 'safe-adopt'];
    }

    /**
     * Orbit process lifecycle owns the gateway; OpenClaw native service install is not used.
     * The process command never embeds the token; the agent shell loads it into
     * OPENCLAW_GATEWAY_TOKEN immediately before exec.
     *
     * @return array{name: string, command: string, runtime: string, tool: string}
     */
    #[\Override]
    public function relatedProcess(): array
    {
        $port = self::WEB_PORT;

        // Use an explicit agent path for the token file so the command does not
        // depend on outer systemd Environment=HOME (node user, usually orbit).
        // Shell variables still use `$` and survive systemd via SystemdUnitRenderer
        // `$$` escaping of ExecStart.
        return [
            'name' => 'openclaw-gateway',
            'command' =>
                'sudo -u agent -H env OPENCLAW_SUPERVISOR_MODE=external OPENCLAW_SERVICE_REPAIR_POLICY=external bash -lc '
                    ."'set -euo pipefail; "
                    .'TOKEN_FILE="/home/agent/.openclaw/gateway.token"; '
                    .'[ -f "${TOKEN_FILE}" ] || { echo "openclaw gateway token missing" >&2; exit 1; }; '
                    .'export OPENCLAW_GATEWAY_TOKEN="$(tr -d "\r\n" < "${TOKEN_FILE}")"; '
                    ."exec openclaw gateway run --port {$port} --bind lan'",
            'runtime' => 'systemd',
            'tool' => 'openclaw',
        ];
    }

    public function installScript(array $config = []): string
    {
        $configure = $this->configureManagedGatewayScript($config);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit install openclaw
            set -euo pipefail
            sudo -u agent -H bash -lc 'curl -fsSL https://openclaw.ai/install.sh | bash -s -- --no-onboard'
            {$configure}
            BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            # orbit remove openclaw
            set -e
            sudo -u agent -H bash -lc 'npm uninstall -g openclaw 2>/dev/null || true'
            sudo -u agent -H bash -lc 'rm -rf "${HOME}/.openclaw" 2>/dev/null || true'
            BASH;
    }

    public function updateScript(array $config = []): string
    {
        $configure = $this->configureManagedGatewayScript($config);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit update openclaw
            set -euo pipefail
            sudo -u agent -H bash -lc 'npm install -g openclaw@latest'
            {$configure}
            BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostnameValue = $config['hostname'] ?? null;
        $hostname = is_string($hostnameValue) && $hostnameValue !== ''
            ? $hostnameValue
            : 'openclaw.agent';
        $tokenFile = "'".str_replace(search: "'", replace: "'\\''", subject: self::TOKEN_FILE)."'";

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            TOKEN_FILE={$tokenFile}
            TOKEN=""
            if [ -f "\${TOKEN_FILE}" ]; then
              TOKEN="\$(tr -d '\\r\\n' < "\${TOKEN_FILE}")"
            fi
            cat <<EOF
            {
              "url": "https://{$hostname}",
              "auth_mode": "token",
              "token": "\${TOKEN}"
            }
            EOF
            BASH;
    }

    public function reconfigureScript(array $config = []): string
    {
        $configure = $this->configureManagedGatewayScript($config);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit reconfigure openclaw
            set -euo pipefail
            {$configure}
            BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => 'openclaw',
            'version_command' => 'sudo -u agent -H bash -lc "openclaw --version"',
            'update_command' => $this->updateScript(),
        ];
    }

    /**
     * Merge only Orbit-managed gateway fields into the existing OpenClaw config.
     * Uses `openclaw config set` so agents/channels/models/settings are preserved.
     * Auth token stays only in gateway.token and is never passed as config-set argv.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function configureManagedGatewayScript(array $config): string
    {
        $hostnameValue = $config['hostname'] ?? null;
        $hostname = is_string($hostnameValue) && $hostnameValue !== ''
            ? $hostnameValue
            : 'openclaw.agent';
        $origin = "https://{$hostname}";
        $port = self::WEB_PORT;
        $allowedOriginsJson = json_encode([$origin], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $allowedOriginsEnv = "'".str_replace(search: "'", replace: "'\\''", subject: $allowedOriginsJson)."'";

        // Pass JSON via env so it is not nested as escapeshellarg inside bash -lc '...'.
        return (
            'sudo -u agent -H env'
            .' OPENCLAW_SUPERVISOR_MODE=external'
            .' OPENCLAW_SERVICE_REPAIR_POLICY=external'
            .' ORBIT_OPENCLAW_ALLOWED_ORIGINS='
            .$allowedOriginsEnv
            ." bash -lc '"
            .'set -euo pipefail; '
            .'STATE_DIR="${HOME}/.openclaw"; '
            .'TOKEN_FILE="${STATE_DIR}/gateway.token"; '
            .'mkdir -p "${STATE_DIR}"; '
            .'umask 077; '
            .'if [ ! -f "${TOKEN_FILE}" ]; then openssl rand -hex 32 > "${TOKEN_FILE}"; chmod 600 "${TOKEN_FILE}"; fi; '
            .'openclaw config set gateway.mode local; '
            ."openclaw config set gateway.port {$port} --strict-json; "
            .'openclaw config set gateway.bind lan; '
            .'openclaw config set gateway.auth.mode token; '
            .'openclaw config unset gateway.auth.token >/dev/null 2>&1 || true; '
            .'openclaw config set gateway.controlUi.allowedOrigins "${ORBIT_OPENCLAW_ALLOWED_ORIGINS}" --strict-json'
            ."'"
        );
    }
}
