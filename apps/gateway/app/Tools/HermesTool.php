<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * @mago-expect lint:too-many-methods
 */
final class HermesTool extends BaseTool
{
    /**
     * Orbit proxy target for the Hermes web dashboard. Co-hosts with OpenClaw
     * on 18789 and leaves Caddy's private backend on 8081 free.
     */
    public const int WEB_PORT = 8080;

    public const string PASSWORD_FILE = '/home/agent/.hermes/dashboard.password';

    public const string SECRET_FILE = '/home/agent/.hermes/dashboard.secret';

    public const string PUBLIC_URL_FILE = '/home/agent/.hermes/dashboard.public_url';

    public const string AUTH_USERNAME = 'orbit';

    /**
     * @var list<string>
     */
    protected const array SUPPORTED_OPERATING_SYSTEMS = ['linux'];

    protected const ?string RUNTIME_USER = 'agent';

    protected const bool REQUIRES_ROUTE_TLD = true;

    protected const ?string ISOLATION = 'unprivileged-user';

    public function slug(): string
    {
        return 'hermes';
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
     * Orbit process lifecycle owns the web dashboard. Secrets load from agent
     * home files immediately before exec and never appear in process argv.
     * Binding 0.0.0.0 engages Hermes' June 2026 auth gate and accepts reverse-
     * proxy Host headers such as hermes.agent (see Hermes Host middleware).
     *
     * @return array{name: string, command: string, runtime: string, tool: string}
     */
    #[\Override]
    public function relatedProcess(): array
    {
        $port = self::WEB_PORT;
        $username = self::AUTH_USERNAME;

        return [
            'name' => 'hermes-dashboard',
            'command' =>
                'sudo -u agent -H bash -lc '
                    ."'set -euo pipefail; "
                    .'PASSWORD_FILE="/home/agent/.hermes/dashboard.password"; '
                    .'SECRET_FILE="/home/agent/.hermes/dashboard.secret"; '
                    .'PUBLIC_URL_FILE="/home/agent/.hermes/dashboard.public_url"; '
                    .'[ -f "${PASSWORD_FILE}" ] || { echo "hermes dashboard password missing" >&2; exit 1; }; '
                    .'[ -f "${SECRET_FILE}" ] || { echo "hermes dashboard secret missing" >&2; exit 1; }; '
                    ."export HERMES_DASHBOARD_BASIC_AUTH_USERNAME={$username}; "
                    .'export HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="$(tr -d "\r\n" < "${PASSWORD_FILE}")"; '
                    .'export HERMES_DASHBOARD_BASIC_AUTH_SECRET="$(tr -d "\r\n" < "${SECRET_FILE}")"; '
                    .'if [ -f "${PUBLIC_URL_FILE}" ]; then '
                    .'export HERMES_DASHBOARD_PUBLIC_URL="$(tr -d "\r\n" < "${PUBLIC_URL_FILE}")"; '
                    .'fi; '
                    ."exec hermes dashboard --host 0.0.0.0 --port {$port} --no-open'",
            'runtime' => 'systemd',
            'tool' => 'hermes',
        ];
    }

    public function installScript(array $config = []): string
    {
        $configure = $this->configureManagedDashboardScript($config);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit install hermes
            set -euo pipefail
            sudo -u agent -H bash -lc 'curl -fsSL https://raw.githubusercontent.com/NousResearch/hermes-agent/main/scripts/install.sh | bash -s -- --skip-setup'
            sudo tee /usr/local/bin/hermes >/dev/null <<'SH'
            #!/usr/bin/env bash
            exec sudo -u agent -H /home/agent/.local/bin/hermes "\$@"
            SH
            sudo chmod 0755 /usr/local/bin/hermes
            {$configure}
            BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            # orbit remove hermes
            set -e
            sudo -u agent -H bash -lc 'hermes dashboard --stop 2>/dev/null || true'
            sudo -u agent -H bash -lc 'rm -rf "${HOME}/.hermes" 2>/dev/null || true'
            sudo -u agent -H bash -lc 'rm -f "${HOME}/.local/bin/hermes" 2>/dev/null || true'
            sudo rm -f /usr/local/bin/hermes
            BASH;
    }

    public function updateScript(array $config = []): string
    {
        $configure = $this->configureManagedDashboardScript($config);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit update hermes
            set -euo pipefail
            sudo -u agent -H bash -lc 'hermes update'
            {$configure}
            BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostnameValue = $config['hostname'] ?? null;
        $hostname = is_string($hostnameValue) && $hostnameValue !== ''
            ? $hostnameValue
            : 'hermes.agent';
        $passwordFile = "'".str_replace(search: "'", replace: "'\\''", subject: self::PASSWORD_FILE)."'";
        $username = self::AUTH_USERNAME;

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            PASSWORD_FILE={$passwordFile}
            PASSWORD=""
            if [ -f "\${PASSWORD_FILE}" ]; then
              PASSWORD="\$(tr -d '\\r\\n' < "\${PASSWORD_FILE}")"
            fi
            cat <<EOF
            {
              "url": "https://{$hostname}",
              "auth_mode": "basic",
              "username": "{$username}",
              "password": "\${PASSWORD}"
            }
            EOF
            BASH;
    }

    public function reconfigureScript(array $config = []): string
    {
        $configure = $this->configureManagedDashboardScript($config);

        return <<<BASH
            #!/usr/bin/env bash
            # orbit reconfigure hermes
            set -euo pipefail
            {$configure}
            BASH;
    }

    #[\Override]
    public function probeMetadata(): array
    {
        return [
            'binary' => '/usr/local/bin/hermes',
            'version_command' => '/usr/local/bin/hermes --version 2>/dev/null || true',
            'update_command' => $this->updateScript(),
        ];
    }

    /**
     * Ensure durable basic-auth material and stop unmanaged Hermes web listeners
     * so the Orbit-owned hermes-dashboard process can bind port 8080 cleanly.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function configureManagedDashboardScript(array $config): string
    {
        $hostnameValue = $config['hostname'] ?? null;
        $hostname = is_string($hostnameValue) && $hostnameValue !== ''
            ? $hostnameValue
            : 'hermes.agent';
        $publicUrl = "https://{$hostname}";
        $publicUrlEnv = "'".str_replace(search: "'", replace: "'\\''", subject: $publicUrl)."'";

        return (
            'sudo -u agent -H env'
            .' ORBIT_HERMES_PUBLIC_URL='
            .$publicUrlEnv
            ." bash -lc '"
            .'set -euo pipefail; '
            .'STATE_DIR="${HOME}/.hermes"; '
            .'PASSWORD_FILE="${STATE_DIR}/dashboard.password"; '
            .'SECRET_FILE="${STATE_DIR}/dashboard.secret"; '
            .'PUBLIC_URL_FILE="${STATE_DIR}/dashboard.public_url"; '
            .'mkdir -p "${STATE_DIR}"; '
            .'umask 077; '
            .'if [ ! -f "${PASSWORD_FILE}" ]; then openssl rand -hex 24 > "${PASSWORD_FILE}"; chmod 600 "${PASSWORD_FILE}"; fi; '
            .'if [ ! -f "${SECRET_FILE}" ]; then openssl rand -base64 32 > "${SECRET_FILE}"; chmod 600 "${SECRET_FILE}"; fi; '
            .'printf "%s\n" "${ORBIT_HERMES_PUBLIC_URL}" > "${PUBLIC_URL_FILE}"; '
            .'chmod 600 "${PUBLIC_URL_FILE}"; '
            // Stop unmanaged (non-systemd) dashboard listeners so port 8080 is
            // free for the Orbit hermes-dashboard process unit.
            .'hermes dashboard --stop 2>/dev/null || true'
            ."'"
        );
    }
}
