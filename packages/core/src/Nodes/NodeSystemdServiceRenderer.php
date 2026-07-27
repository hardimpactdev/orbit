<?php

declare(strict_types=1);

namespace Orbit\Core\Nodes;

final readonly class NodeSystemdServiceRenderer
{
    public const string RuntimeBootScriptPath = '/usr/local/libexec/orbit-runtime-boot-converge';

    public const string RuntimeBootUnitName = 'orbit-runtime-boot-converge.service';

    public const string RuntimeBootUnitPath = '/etc/systemd/system/'.self::RuntimeBootUnitName;

    public function agentUnit(
        string $user,
        string $agentBinary,
        string $orbitBinary,
        string $configPath,
        string $httpBind,
    ): string {
        return <<<UNIT
            [Unit]
            Description=Orbit Agent
            After=network-online.target
            Wants=network-online.target

            [Service]
            Type=simple
            User={$user}
            Environment=ORBIT_AGENT_CONFIG={$configPath}
            Environment=ORBIT_AGENT_HTTP_BIND={$httpBind}
            Environment=ORBIT_AGENT_ORBIT_BINARY={$orbitBinary}
            ExecStart={$agentBinary}
            Restart=always
            RestartSec=3

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }

    public function runtimeBootScript(): string
    {
        return <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            command -v docker >/dev/null 2>&1
            docker info >/dev/null

            managed_container_ids() {
                kind="$1"
                docker container ls -aq \
                    --filter label=orbit.managed=true \
                    --filter "label=orbit.container.kind=$kind"
            }

            restart_policy_for() {
                docker container inspect \
                    --format '{{.HostConfig.RestartPolicy.Name}}' \
                    "$1"
            }

            reconnect_configured_network() {
                container="$1"
                network="$(docker container inspect --format '{{.HostConfig.NetworkMode}}' "$container")"

                case "$network" in
                    ""|default|host|none) return ;;
                esac

                if ! docker container inspect \
                    --format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' \
                    "$container" | grep -Fx "$network" >/dev/null; then
                    docker network connect "$network" "$container"
                fi
            }

            for container in $(managed_container_ids caddy); do
                restart_policy="$(restart_policy_for "$container")"

                if [ "$restart_policy" = "always" ]; then
                    reconnect_configured_network "$container"
                    docker restart "$container"
                fi
            done

            for kind in app-runtime workspace-runtime websocket-runtime; do
                for container in $(managed_container_ids "$kind"); do
                    restart_policy="$(restart_policy_for "$container")"

                    if [ "$restart_policy" != "always" ]; then
                        continue
                    fi

                    state="$(docker container inspect --format '{{.State.Status}}' "$container")"

                    if [ "$state" != "running" ]; then
                        docker start "$container"
                    fi
                done
            done
            BASH;
    }

    public function runtimeBootUnit(): string
    {
        return <<<'UNIT'
            [Unit]
            Description=Converge Orbit runtimes after node boot
            After=docker.service network-online.target
            Wants=network-online.target
            Requires=docker.service
            StartLimitIntervalSec=0

            [Service]
            Type=oneshot
            ExecStart=/usr/local/libexec/orbit-runtime-boot-converge
            RemainAfterExit=yes
            Restart=on-failure
            RestartSec=5
            TimeoutStartSec=120

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
