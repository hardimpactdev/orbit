<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Shared\StoredValue;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeWebSocketRuntimeLifecycle implements WebSocketRuntimeLifecycle
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ManagedUserAccountResolver $accounts,
        private WebSocketEnvironmentRenderer $environment = new WebSocketEnvironmentRenderer,
        private WebSocketServiceUnitRenderer $unit = new WebSocketServiceUnitRenderer,
        private string $repository = '',
        private string $ref = '',
        private string $installPath = '',
        private int $port = 0,
    ) {}

    public function converge(Node $node, NodeRole $assignment, WebSocketServerCredentials $credentials): void
    {
        $address = $this->address($node);
        $account = $this->accounts->resolve($node);

        $caddyPackage = $this->commands->caddyPackage($node, RoleName::WebSocket);
        if ($caddyPackage instanceof RemoteCommand) {
            $source = $this->ssh->execute($this->connection($node, $address), $caddyPackage);

            if (! $source->succeeded()) {
                throw new NodeRoleOperationException(
                    'caddy-package-source',
                    'node_role.convergence_failed',
                    'websocket.prerequisite_failed',
                    "The Caddy package step failed on node [{$node->name}].",
                    $source,
                );
            }
        }

        $prerequisites = $this->ssh->execute(
            $this->connection($node, $address),
            $this->commands->make($node, RoleName::WebSocket, $account),
        );

        if (! $prerequisites->succeeded()) {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'websocket.prerequisite_failed',
                "WebSocket role prerequisites failed on node [{$node->name}].",
                $prerequisites,
            );
        }

        $port = $this->resolvedPort();
        $envContents = $this->environment->render($credentials, $port);
        $unitContents = $this->unit->render(
            $this->resolvedInstallPath(),
            $account->user,
            $account->group,
            $port,
        );

        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    $account->user,
                    $account->group,
                    $this->resolvedInstallPath(),
                    $this->resolvedRepository(),
                    $this->resolvedRef(),
                    (string) $port,
                ],
                protectedInput: ProtectedInput::fromString($this->script($envContents, $unitContents)),
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'websocket-runtime',
                'node_role.convergence_failed',
                'websocket.runtime_convergence_failed',
                "WebSocket role convergence failed on node [{$node->name}].",
                $result,
            );
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $address = $this->address($node);
        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    $this->resolvedInstallPath(),
                    $purgeData ? '1' : '0',
                ],
                input: <<<'BASH'
                    install_path=$1
                    purge_data=$2
                    service=orbit-websocket
                    if systemctl list-unit-files -- "$service.service" >/dev/null 2>&1; then
                        systemctl disable --now -- "$service"
                    fi
                    rm -f -- "/etc/systemd/system/$service.service"
                    systemctl daemon-reload || true
                    systemctl reset-failed -- "$service.service" >/dev/null 2>&1 || true
                    if [ "$purge_data" = 1 ]; then
                        rm -rf -- "$install_path" "$install_path.releases"
                    fi
                    BASH,
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'websocket-runtime-removal',
                'node_role.removal_failed',
                'websocket.runtime_removal_failed',
                "WebSocket role removal failed on node [{$node->name}].",
                $result,
            );
        }
    }

    public function health(Node $node): bool
    {
        $address = $this->address($node);
        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: ['systemctl', 'is-active', '--quiet', 'orbit-websocket'],
            ),
        );

        return $result->succeeded();
    }

    private function script(string $envContents, string $unitContents): string
    {
        $envEncoded = base64_encode($envContents);
        $unitEncoded = base64_encode($unitContents);

        return <<<BASH
            managed_user=\$1
            managed_group=\$2
            install_path=\$3
            repository=\$4
            ref=\$5
            port=\$6
            umask 0077
            install -d -m 0755 -- "\$(dirname "\$install_path")"
            exec 9>"\$install_path.install.lock"
            flock -x 9
            releases="\$install_path.releases"
            install -d -m 0755 -o "\$managed_user" -g "\$managed_group" -- "\$releases"
            candidate=\$(mktemp -d "\$releases/release.XXXXXXXX")
            verify_directory=\$(mktemp -d)
            activated=0
            cleanup() {
                rm -rf -- "\$verify_directory"
                if [ "\$activated" = 0 ]; then
                    rm -rf -- "\$candidate"
                fi
            }
            trap cleanup EXIT
            chown "\$managed_user":"\$managed_group" "\$candidate"
            sudo -u "\$managed_user" -H git clone --quiet --no-checkout -- "\$repository" "\$candidate"
            sudo -u "\$managed_user" -H git -C "\$candidate" fetch --quiet origin "\$ref"
            sudo -u "\$managed_user" -H git -C "\$candidate" checkout --quiet --detach FETCH_HEAD
            sudo -u "\$managed_user" -H env COMPOSER_ALLOW_SUPERUSER=1 composer install \\
                --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir="\$candidate"
            printf '%s' '{$envEncoded}' | base64 --decode > "\$candidate/.env"
            chown "\$managed_user":"\$managed_group" "\$candidate/.env"
            chmod 0600 "\$candidate/.env"
            sudo -u "\$managed_user" -H /usr/bin/php "\$candidate/artisan" orbit:reverb:start --help >/dev/null
            printf '%s' '{$unitEncoded}' | base64 --decode > "\$verify_directory/orbit-websocket.service"
            systemd-analyze verify "\$verify_directory/orbit-websocket.service"
            # All preparation succeeds before stopping the process or replacing its code.
            if systemctl cat orbit-websocket.service >/dev/null 2>&1; then
                systemctl stop orbit-websocket
            fi
            previous=""
            if [ -L "\$install_path" ]; then
                previous=\$(readlink -f -- "\$install_path")
            fi
            if [ -d "\$install_path" ] && [ ! -L "\$install_path" ]; then
                previous=\$(mktemp -d "\$releases/previous.XXXXXXXX")
                rmdir "\$previous"
                mv -T -- "\$install_path" "\$previous"
            fi
            ln -s -- "\$candidate" "\$verify_directory/current"
            mv -fT -- "\$verify_directory/current" "\$install_path"
            activated=1
            install -o root -g root -m 0644 -- "\$verify_directory/orbit-websocket.service" /etc/systemd/system/.orbit-websocket.service.orbit-candidate
            mv -fT -- /etc/systemd/system/.orbit-websocket.service.orbit-candidate /etc/systemd/system/orbit-websocket.service
            systemctl daemon-reload
            systemctl enable --quiet orbit-websocket
            systemctl restart orbit-websocket
            ready=0
            for ((attempt = 0; attempt < 30; attempt++)); do
                if (exec 3<>"/dev/tcp/127.0.0.1/\$port") 2>/dev/null; then
                    ready=1
                    break
                fi
                sleep 1
            done
            if [ "\$ready" != 1 ]; then
                printf 'Reverb did not open its listener within 30 seconds on port %s.\n' "\$port" >&2
                exit 1
            fi
            for release in "\$releases"/release.* "\$releases"/previous.*; do
                [ -d "\$release" ] || continue
                if [ "\$release" != "\$candidate" ] && [ "\$release" != "\$previous" ]; then
                    rm -rf -- "\$release"
                fi
            done
            BASH;
    }

    private function connection(Node $node, string $address): SshConnection
    {
        return new SshConnection(
            $address,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }

    private function address(Node $node): string
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'websocket.wireguard_ip_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return $node->wireguard_ip;
    }

    private function resolvedRepository(): string
    {
        return $this->repository !== '' ? $this->repository : StoredValue::string(config('orbit.websocket.repository'));
    }

    private function resolvedRef(): string
    {
        return $this->ref !== '' ? $this->ref : StoredValue::string(config('orbit.websocket.ref'));
    }

    private function resolvedInstallPath(): string
    {
        return $this->installPath !== '' ? $this->installPath : StoredValue::string(config('orbit.websocket.install_path'));
    }

    private function resolvedPort(): int
    {
        return $this->port > 0 ? $this->port : StoredValue::integer(config('orbit.websocket.port'));
    }
}
