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
                                docker start wg-easy >/dev/null 2>&1 || true
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
            'if docker inspect wg-easy >/dev/null 2>&1; then docker rm wg-easy >/dev/null; fi',
            "Could not remove the stopped prepared wg-easy container on {$gateway->name()}",
        );
    }

    public function restoreStandalone(E2EInstance $gateway): void
    {
        E2ECommand::exec(
            $gateway,
            <<<'SH'
                set -euo pipefail

                if ! docker inspect wg-easy >/dev/null 2>&1; then
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
