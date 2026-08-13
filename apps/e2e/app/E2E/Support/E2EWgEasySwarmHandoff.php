<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EWgEasySwarmHandoff
{
    private const string SWARM_STATE_PATH = '/home/orbit/.config/orbit/wg-easy';

    private const string GATEWAY_ENVIRONMENT_PATH = '/home/orbit/.config/orbit/.env';

    /**
     * Quiesce the prepared standalone container before moving its state to
     * the source-mounted checkout's Swarm-owned state path. The stopped
     * container remains available until the caller completes the handoff.
     */
    public function stage(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            sprintf(
                <<<'SH'
                    set -euo pipefail
                    handoff_complete=0
                    state_move_started=0
                    legacy_link_started=0
                    peer_snapshot=/run/orbit/wg-easy-handoff-peers.tsv
                    peer_snapshot_temporary=

                    restore_peer_sessions() {
                        container="$1"
                        snapshot="$2"

                        if ! sudo test -f "$snapshot"; then
                            return 0
                        fi

                        for i in $(seq 1 30); do
                            if docker exec "$container" wg show wg0 >/dev/null 2>&1; then
                                break
                            fi

                            sleep 1
                        done

                        docker exec "$container" wg show wg0 >/dev/null

                        sudo cat "$snapshot" | while IFS="$(printf '\t')" read -r peer_public_key peer_endpoint peer_address; do
                            docker exec "$container" wg show wg0 peers | grep -Fx -- "$peer_public_key" >/dev/null
                            docker exec "$container" wg set wg0 peer "$peer_public_key" endpoint "$peer_endpoint"
                            docker exec "$container" ping -c 1 -W 1 "$peer_address" >/dev/null 2>&1 || true
                        done
                    }

                    restore_legacy_wg_easy() {
                        exit_status="$?"
                        trap - EXIT

                        if [ "$handoff_complete" -eq 0 ]; then
                            if [ "$legacy_link_started" -eq 1 ] && [ -L /home/orbit/.wg-easy ]; then
                                sudo rm /home/orbit/.wg-easy
                            fi

                            if [ "$state_move_started" -eq 1 ] \
                                && [ -d /home/orbit/.config/orbit/wg-easy ] \
                                && [ ! -e /home/orbit/.wg-easy ]; then
                                sudo mv /home/orbit/.config/orbit/wg-easy /home/orbit/.wg-easy
                            fi

                            if [ -d /home/orbit/.wg-easy ] && docker inspect wg-easy >/dev/null 2>&1; then
                                if docker start wg-easy >/dev/null 2>&1; then
                                    restore_peer_sessions wg-easy "$peer_snapshot" || true
                                fi
                            fi

                            sudo rm -f "$peer_snapshot"

                            if [ -n "$peer_snapshot_temporary" ]; then
                                sudo rm -f "$peer_snapshot_temporary"
                            fi
                        fi

                        exit "$exit_status"
                    }

                    trap restore_legacy_wg_easy EXIT

                    already_handed_off="$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn' | head -n 1)"

                    if [ -n "$already_handed_off" ]; then
                        test -f /home/orbit/.wg-easy/wg-easy.db
                        test -f /home/orbit/.config/orbit/wg-easy/wg-easy.db
                        handoff_complete=1
                        trap - EXIT
                        exit 0
                    fi

                    docker inspect wg-easy >/dev/null
                    test -f /home/orbit/.wg-easy/wg-easy.db
                    test ! -e /home/orbit/.config/orbit/wg-easy
                    source_database_checksum="$(sha256sum /home/orbit/.wg-easy/wg-easy.db | awk '{ print $1 }')"
                    sudo install -d -m 0700 /run/orbit
                    sudo rm -f "$peer_snapshot"
                    peer_snapshot_temporary="$(sudo mktemp /run/orbit/wg-easy-handoff-peers.XXXXXX)"
                    docker exec wg-easy wg show wg0 endpoints | while read -r peer_public_key peer_endpoint; do
                        if [ "$peer_endpoint" = '(none)' ]; then
                            continue
                        fi

                        peer_allowed_ips="$(docker exec wg-easy wg show wg0 allowed-ips | awk -v peer="$peer_public_key" '$1 == peer { print $2; exit }')"
                        test -n "$peer_allowed_ips"
                        peer_address="${peer_allowed_ips%%%%,*}"
                        peer_address="${peer_address%%%%/*}"
                        printf '%%s\t%%s\t%%s\n' "$peer_public_key" "$peer_endpoint" "$peer_address"
                    done | sudo tee "$peer_snapshot_temporary" >/dev/null
                    sudo test -s "$peer_snapshot_temporary"
                    sudo chmod 0600 "$peer_snapshot_temporary"
                    sudo mv "$peer_snapshot_temporary" "$peer_snapshot"
                    peer_snapshot_temporary=
                    docker stop wg-easy >/dev/null
                    state_move_started=1
                    sudo mv /home/orbit/.wg-easy /home/orbit/.config/orbit/wg-easy
                    legacy_link_started=1
                    sudo ln -s /home/orbit/.config/orbit/wg-easy /home/orbit/.wg-easy
                    sudo chown -h orbit:orbit /home/orbit/.wg-easy
                    target_database_checksum="$(sha256sum /home/orbit/.config/orbit/wg-easy/wg-easy.db | awk '{ print $1 }')"
                    test "$source_database_checksum" = "$target_database_checksum"
                    %s

                    handoff_complete=1
                    trap - EXIT
                    SH,
                $this->phpCommand(
                    marker: 'ORBIT_WG_EASY_SWARM_HANDOFF_PHP',
                    environment: [
                        'ORBIT_WG_EASY_ENV_PATH' => self::GATEWAY_ENVIRONMENT_PATH,
                        'ORBIT_WG_EASY_ADMIN_PASSWORD' => E2EWgEasyGateway::ADMIN_PASSWORD,
                        'ORBIT_WG_EASY_DB_PATH' => self::SWARM_STATE_PATH.'/wg-easy.db',
                    ],
                    script: $this->stateScript(),
                ),
            ),
            "Could not stage prepared wg-easy state for the Swarm runtime on {$gateway->name()}",
            timeoutSeconds: 120,
        );
    }

    public function complete(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            <<<'SH'
                set -euo pipefail
                peer_snapshot=/run/orbit/wg-easy-handoff-peers.tsv

                if ! sudo test -f "$peer_snapshot" && ! docker inspect wg-easy >/dev/null 2>&1; then
                    exit 0
                fi

                sudo test -f "$peer_snapshot"

                vpn_task=

                for i in $(seq 1 30); do
                    vpn_task="$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn' | head -n 1)"

                    if [ -n "$vpn_task" ] && docker exec "$vpn_task" wg show wg0 >/dev/null 2>&1; then
                        break
                    fi

                    sleep 1
                done

                test -n "$vpn_task"
                docker exec "$vpn_task" wg show wg0 >/dev/null

                sudo cat "$peer_snapshot" | while IFS="$(printf '\t')" read -r peer_public_key peer_endpoint peer_address; do
                    docker exec "$vpn_task" wg show wg0 peers | grep -Fx -- "$peer_public_key" >/dev/null
                    docker exec "$vpn_task" wg set wg0 peer "$peer_public_key" endpoint "$peer_endpoint"
                    docker exec "$vpn_task" ping -c 1 -W 1 "$peer_address" >/dev/null 2>&1 || true
                done

                for i in $(seq 1 15); do
                    if sudo cat "$peer_snapshot" | while IFS="$(printf '\t')" read -r peer_public_key peer_endpoint peer_address; do
                        latest_handshake="$(docker exec "$vpn_task" wg show wg0 latest-handshakes | awk -v peer="$peer_public_key" '$1 == peer { print $2; exit }')"
                        test "${latest_handshake:-0}" -gt 0
                    done; then
                        break
                    fi

                    sleep 1
                done

                sudo cat "$peer_snapshot" | while IFS="$(printf '\t')" read -r peer_public_key peer_endpoint peer_address; do
                    latest_handshake="$(docker exec "$vpn_task" wg show wg0 latest-handshakes | awk -v peer="$peer_public_key" '$1 == peer { print $2; exit }')"
                    test "${latest_handshake:-0}" -gt 0
                done

                sudo rm -f "$peer_snapshot"

                if docker inspect wg-easy >/dev/null 2>&1; then
                    docker rm wg-easy >/dev/null
                fi
                SH,
            "Could not remove the stopped prepared wg-easy container on {$gateway->name()}",
            timeoutSeconds: 60,
        );
    }

    public function restoreStandalone(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            <<<'SH'
                set -euo pipefail
                peer_snapshot=/run/orbit/wg-easy-handoff-peers.tsv

                if ! docker inspect wg-easy >/dev/null 2>&1; then
                    sudo rm -f "$peer_snapshot"
                    exit 0
                fi

                docker service rm orbit_orbit-vpn orbit_orbit-dns >/dev/null 2>&1 || true

                for i in $(seq 1 30); do
                    vpn_task="$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn' | head -n 1)"
                    dns_task="$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns' | head -n 1)"

                    if [ -z "$vpn_task" ] && [ -z "$dns_task" ]; then
                        break
                    fi

                    sleep 1
                done

                test -z "$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn' | head -n 1)"
                test -z "$(docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns' | head -n 1)"

                if [ -L /home/orbit/.wg-easy ]; then
                    test -d /home/orbit/.config/orbit/wg-easy
                    sudo rm /home/orbit/.wg-easy
                    sudo mv /home/orbit/.config/orbit/wg-easy /home/orbit/.wg-easy
                fi

                test -d /home/orbit/.wg-easy
                docker start wg-easy >/dev/null

                if sudo test -f "$peer_snapshot"; then
                    for i in $(seq 1 30); do
                        if docker exec wg-easy wg show wg0 >/dev/null 2>&1; then
                            break
                        fi

                        sleep 1
                    done

                    docker exec wg-easy wg show wg0 >/dev/null

                    sudo cat "$peer_snapshot" | while IFS="$(printf '\t')" read -r peer_public_key peer_endpoint peer_address; do
                        docker exec wg-easy wg show wg0 peers | grep -Fx -- "$peer_public_key" >/dev/null
                        docker exec wg-easy wg set wg0 peer "$peer_public_key" endpoint "$peer_endpoint"
                        docker exec wg-easy ping -c 1 -W 1 "$peer_address" >/dev/null 2>&1 || true
                    done
                fi

                sudo rm -f "$peer_snapshot"
                SH,
            "Could not restore the prepared standalone wg-easy runtime on {$gateway->name()}",
            timeoutSeconds: 60,
        );
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function phpCommand(string $marker, array $environment, string $script): string
    {
        $assignments = [];

        foreach ($environment as $key => $value) {
            $assignments[] = "{$key}=".escapeshellarg($value);
        }

        return sprintf(
            "sudo -u orbit env %s php <<'%s'\n%s\n%s",
            implode(' ', $assignments),
            $marker,
            $script,
            $marker,
        );
    }

    private function stateScript(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            $environmentPath = getenv('ORBIT_WG_EASY_ENV_PATH');
            $password = getenv('ORBIT_WG_EASY_ADMIN_PASSWORD');
            $databasePath = getenv('ORBIT_WG_EASY_DB_PATH');

            if (! is_string($environmentPath) || trim($environmentPath) === '' || ! is_file($environmentPath)) {
                fwrite(STDERR, "The source-mounted gateway environment file is required.\n");
                exit(1);
            }

            if (! is_string($password) || $password === '') {
                fwrite(STDERR, "The prepared wg-easy credential is required.\n");
                exit(1);
            }

            if (! is_string($databasePath) || trim($databasePath) === '' || ! is_file($databasePath)) {
                fwrite(STDERR, "The migrated wg-easy database is required.\n");
                exit(1);
            }

            $database = new PDO('sqlite:'.$databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);

            if ($database->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
                fwrite(STDERR, "The migrated wg-easy database failed its integrity check.\n");
                exit(1);
            }

            $passwordHash = $database->query('SELECT password FROM users_table ORDER BY id LIMIT 1')->fetchColumn();

            if (! is_string($passwordHash) || ! password_verify($password, $passwordHash)) {
                fwrite(STDERR, "The prepared wg-easy credential does not match the migrated database.\n");
                exit(1);
            }

            $contents = file_get_contents($environmentPath);

            if (! is_string($contents)) {
                fwrite(STDERR, "Could not read the source-mounted gateway environment file.\n");
                exit(1);
            }

            $line = 'WG_EASY_PASSWORD='.$password;
            $updated = preg_match('/^WG_EASY_PASSWORD=/m', $contents) === 1
                ? preg_replace('/^WG_EASY_PASSWORD=.*$/m', $line, $contents)
                : rtrim($contents)."\n{$line}\n";

            if (! is_string($updated)) {
                fwrite(STDERR, "Could not update the source-mounted gateway environment file.\n");
                exit(1);
            }

            $temporaryPath = $environmentPath.'.wg-easy-handoff';

            if (
                file_put_contents($temporaryPath, $updated, LOCK_EX) === false
                || ! chmod($temporaryPath, 0o600)
                || ! rename($temporaryPath, $environmentPath)
            ) {
                @unlink($temporaryPath);
                fwrite(STDERR, "Could not persist the prepared wg-easy credential.\n");
                exit(1);
            }
            PHP;
    }
}
