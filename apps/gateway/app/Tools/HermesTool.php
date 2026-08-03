<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Tools\ManagedToolShell;

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

    /**
     * Orbit-owned process name. Distinct from Hermes native
     * hermes-dashboard.service so unit ownership cannot collide.
     */
    public const string PROCESS_NAME = 'orbit-hermes-dashboard';

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

        $requirePassword = ManagedToolShell::requireNonEmptySecretFromFile(
            fileVar: '${PASSWORD_FILE}',
            targetVar: 'PASSWORD',
            missingMessage: 'hermes dashboard password missing',
        );
        $requireSecret = ManagedToolShell::requireNonEmptySecretFromFile(
            fileVar: '${SECRET_FILE}',
            targetVar: 'SECRET',
            missingMessage: 'hermes dashboard secret missing',
        );

        return [
            'name' => self::PROCESS_NAME,
            'command' =>
                'sudo -u agent -H bash -lc '
                    ."'set -euo pipefail; "
                    .'PASSWORD_FILE="/home/agent/.hermes/dashboard.password"; '
                    .'SECRET_FILE="/home/agent/.hermes/dashboard.secret"; '
                    .'PUBLIC_URL_FILE="/home/agent/.hermes/dashboard.public_url"; '
                    // Canonical non-empty rule: missing/zero-byte/whitespace-only
                    // credential files fail closed (same normalization as regen).
                    .$requirePassword
                    .$requireSecret
                    ."export HERMES_DASHBOARD_BASIC_AUTH_USERNAME={$username}; "
                    .'export HERMES_DASHBOARD_BASIC_AUTH_PASSWORD="${PASSWORD}"; '
                    .'export HERMES_DASHBOARD_BASIC_AUTH_SECRET="${SECRET}"; '
                    .'if [ -f "${PUBLIC_URL_FILE}" ]; then '
                    .'export HERMES_DASHBOARD_PUBLIC_URL="$(tr -d "[:space:]" < "${PUBLIC_URL_FILE}")"; '
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
        $hostname = $this->resolvedHostname($config);
        $passwordFile = ManagedToolShell::singleQuote(self::PASSWORD_FILE);
        $username = self::AUTH_USERNAME;

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            PASSWORD_FILE={$passwordFile}
            PASSWORD=""
            if [ -f "\${PASSWORD_FILE}" ]; then
              PASSWORD="\$(tr -d '[:space:]' < "\${PASSWORD_FILE}")"
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
     * Ensure durable basic-auth material. Stop unmanaged Hermes dashboard
     * listeners only when the Orbit unit is not active so update/reconfigure
     * never kill the managed process that owns port 8080.
     *
     * @param  array<array-key, mixed>  $config
     */
    private function configureManagedDashboardScript(array $config): string
    {
        $publicUrl = 'https://'.$this->resolvedHostname($config);
        $publicUrlEnv = ManagedToolShell::singleQuote($publicUrl);
        $unit = self::PROCESS_NAME.'.service';
        $ensurePassword = ManagedToolShell::ensureNonEmptySecretFile(
            fileVar: '${PASSWORD_FILE}',
            generateCommand: 'openssl rand -hex 24',
        );
        $ensureSecret = ManagedToolShell::ensureNonEmptySecretFile(
            fileVar: '${SECRET_FILE}',
            generateCommand: 'openssl rand -base64 32',
        );

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
            // Canonical non-empty rule: missing, zero-byte, or whitespace-only
            // (after stripping spaces/newlines) must be regenerated securely.
            .$ensurePassword
            .$ensureSecret
            .'printf "%s\n" "${ORBIT_HERMES_PUBLIC_URL}" > "${PUBLIC_URL_FILE}"; '
            .'chmod 600 "${PUBLIC_URL_FILE}"; '
            // Read-only unit ActiveState (no agent sudo). Treat active,
            // activating, and reloading as managed so reconfigure never races
            // a unit mid-start/reload with hermes dashboard --stop.
            // Only when fully inactive/failed/missing do we free port 8080.
            ."ORBIT_HERMES_UNIT_STATE=\"\$(systemctl show -p ActiveState --value {$unit} 2>/dev/null || true)\"; "
            .'case "${ORBIT_HERMES_UNIT_STATE}" in '
            .'active|activating|reloading) : ;; '
            .'*) hermes dashboard --stop 2>/dev/null || true ;; '
            .'esac'
            ."'"
        );
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function resolvedHostname(array $config): string
    {
        $hostnameValue = $config['hostname'] ?? null;

        return is_string($hostnameValue) && $hostnameValue !== ''
            ? $hostnameValue
            : 'hermes.agent';
    }
}
