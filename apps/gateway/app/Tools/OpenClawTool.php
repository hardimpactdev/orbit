<?php

declare(strict_types=1);

namespace App\Tools;

use App\Services\Tools\ManagedToolShell;

/**
 * @mago-expect lint:too-many-methods
 */
final class OpenClawTool extends BaseTool
{
    /**
     * OpenClaw's documented default gateway port (not Orbit Caddy's private 8081).
     * Co-hosts cleanly with Hermes on 8080 on the same agent node.
     *
     * A hypothetical 8081 fallback would require proving Hermes specifically owns
     * a collision on this default and persisting the selected port; that path is
     * not exercised today while Hermes remains on 8080.
     */
    public const int WEB_PORT = 18789;

    public const string TOKEN_FILE = '/home/agent/.openclaw/gateway.token';

    /**
     * Local-prefix OpenClaw CLI installed by install-cli.sh under the agent home.
     * Absolute path so process/configure/probe never depend on ambient PATH.
     */
    public const string PREFIX_BIN = '/home/agent/.openclaw/bin/openclaw';

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
        $prefixBin = self::PREFIX_BIN;

        // Use an explicit agent path for the token file so the command does not
        // depend on outer systemd Environment=HOME (node user, usually orbit).
        // Shell variables still use `$` and survive systemd via SystemdUnitRenderer
        // `$$` escaping of ExecStart.
        //
        // Build the complete inner script first, then single-quote it once for
        // bash -lc so helper snippets never open/close the outer quote.
        $innerScript =
            'set -euo pipefail; '
            .'TOKEN_FILE="/home/agent/.openclaw/gateway.token"; '
            .ManagedToolShell::requireNonEmptySecretFromFile(
                fileVar: '${TOKEN_FILE}',
                targetVar: 'TOKEN',
                missingMessage: 'openclaw gateway token missing',
            )
            .'export OPENCLAW_GATEWAY_TOKEN="${TOKEN}"; '
            ."exec {$prefixBin} gateway run --port {$port} --bind lan";

        return [
            'name' => 'openclaw-gateway',
            'command' =>
                'sudo -u agent -H env OPENCLAW_SUPERVISOR_MODE=external OPENCLAW_SERVICE_REPAIR_POLICY=external bash -lc '
                    .ManagedToolShell::singleQuote($innerScript),
            'runtime' => 'systemd',
            'tool' => 'openclaw',
        ];
    }

    public function installScript(array $config = []): string
    {
        $configure = $this->configureManagedGatewayScript($config);
        $prefixInstall = $this->localPrefixInstallCommand();

        return <<<BASH
            #!/usr/bin/env bash
            # orbit install openclaw
            set -euo pipefail
            {$prefixInstall}
            {$configure}
            BASH;
    }

    public function removeScript(array $config = []): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            # orbit remove openclaw
            set -e
            sudo -u agent -H bash -lc 'rm -rf "${HOME}/.openclaw" 2>/dev/null || true'
            BASH;
    }

    public function updateScript(array $config = []): string
    {
        $configure = $this->configureManagedGatewayScript($config);
        $prefixInstall = $this->localPrefixInstallCommand();

        return <<<BASH
            #!/usr/bin/env bash
            # orbit update openclaw
            set -euo pipefail
            {$prefixInstall}
            {$configure}
            BASH;
    }

    public function credentialsScript(array $config = []): string
    {
        $hostnameValue = $config['hostname'] ?? null;
        $hostname = is_string($hostnameValue) && $hostnameValue !== ''
            ? $hostnameValue
            : 'openclaw.agent';
        $tokenFile = ManagedToolShell::singleQuote(self::TOKEN_FILE);

        return <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            TOKEN_FILE={$tokenFile}
            TOKEN=""
            if [ -f "\${TOKEN_FILE}" ]; then
              TOKEN="\$(tr -d '[:space:]' < "\${TOKEN_FILE}")"
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
        $prefixBin = self::PREFIX_BIN;

        return [
            'binary' => $prefixBin,
            // Presence must run as agent: agent home is owner-only 0700 and orbit
            // cannot [ -x ] the local-prefix path. Observed path stays PREFIX_BIN.
            'binary_as_user' => 'agent',
            'version_command' => 'sudo -u agent -H bash -lc '.ManagedToolShell::singleQuote("{$prefixBin} --version"),
            'update_command' => $this->updateScript(),
        ];
    }

    /**
     * Local-prefix installer under the agent home so Node/OpenClaw do not need
     * system Node or agent self-sudo (install.sh requires admin privileges).
     */
    private function localPrefixInstallCommand(): string
    {
        // Quote-once the full agent-local install line; no nested sudo as agent.
        return 'sudo -u agent -H bash -lc '
        .ManagedToolShell::singleQuote(
            'curl -fsSL --proto \'=https\' --tlsv1.2 https://openclaw.ai/install-cli.sh | bash -s -- --no-onboard --prefix "$HOME/.openclaw"',
        );
    }

    /**
     * Merge only Orbit-managed gateway fields into the existing OpenClaw config.
     * Uses absolute prefix binary + `config set` so agents/channels/models/settings
     * are preserved. Auth token stays only in gateway.token and is never argv.
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
        $prefixBin = self::PREFIX_BIN;
        $allowedOriginsJson = json_encode([$origin], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $allowedOriginsEnv = ManagedToolShell::singleQuote($allowedOriginsJson);
        $ensureToken = ManagedToolShell::ensureNonEmptySecretFile(
            fileVar: '${TOKEN_FILE}',
            generateCommand: 'openssl rand -hex 32',
        );

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
            .$ensureToken
            ."{$prefixBin} config set gateway.mode local; "
            ."{$prefixBin} config set gateway.port {$port} --strict-json; "
            ."{$prefixBin} config set gateway.bind lan; "
            ."{$prefixBin} config set gateway.auth.mode token; "
            ."{$prefixBin} config unset gateway.auth.token >/dev/null 2>&1 || true; "
            ."{$prefixBin} config set gateway.controlUi.allowedOrigins \"\${ORBIT_OPENCLAW_ALLOWED_ORIGINS}\" --strict-json"
            ."'"
        );
    }
}
