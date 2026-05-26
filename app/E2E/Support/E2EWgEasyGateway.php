<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EWgEasyGateway
{
    public function start(E2EInstance $gateway, string $advertisedHost): void
    {
        E2ECommand::exec(
            $gateway,
            sprintf(
                <<<'SH'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
if ! command -v docker >/dev/null 2>&1 || ! command -v sqlite3 >/dev/null 2>&1; then
    sudo apt-get -o DPkg::Lock::Timeout=300 update -qq
    packages=""
    command -v docker >/dev/null 2>&1 || packages="${packages} docker.io"
    command -v sqlite3 >/dev/null 2>&1 || packages="${packages} sqlite3"
    sudo apt-get -o DPkg::Lock::Timeout=300 install -y -qq ${packages}
fi
sudo systemctl enable --now docker
docker rm -f wg-easy >/dev/null 2>&1 || true
sudo install -d -m 0755 -o orbit -g orbit /home/orbit/.wg-easy
docker run -d \
    --name wg-easy \
    --restart unless-stopped \
    -e INIT_ENABLED=true \
    -e INIT_USERNAME=orbit \
    -e INIT_PASSWORD=orbit-e2e-bootstrap-password \
    -e %s \
    -e INIT_PORT=51820 \
    -e INIT_DNS=10.6.0.1 \
    -e INIT_ALLOWED_IPS=10.6.0.0/24 \
    -e INSECURE=true \
    -e PORT=51821 \
    -e HOST=0.0.0.0 \
    -e DISABLE_IPV6=true \
    -p 51820:51820/udp \
    -p 127.0.0.1:51821:51821/tcp \
    --cap-add NET_ADMIN \
    --cap-add SYS_MODULE \
    --sysctl net.ipv4.conf.all.src_valid_mark=1 \
    --sysctl net.ipv4.ip_forward=1 \
    -v /home/orbit/.wg-easy:/etc/wireguard \
    -v /lib/modules:/lib/modules:ro \
    ghcr.io/wg-easy/wg-easy:15
for i in $(seq 1 30); do
    test -f /home/orbit/.wg-easy/wg-easy.db && break
    sleep 1
done
test -f /home/orbit/.wg-easy/wg-easy.db
sudo chown -R orbit:orbit /home/orbit/.wg-easy
for i in $(seq 1 30); do
    docker exec wg-easy ip link show wg0 >/dev/null 2>&1 && break
    sleep 1
done
docker exec wg-easy ip addr replace 10.6.0.1/24 dev wg0
docker exec wg-easy ip route replace 10.6.0.0/24 dev wg0
sqlite3 /home/orbit/.wg-easy/wg-easy.db "UPDATE interfaces_table SET ipv4_cidr = '10.6.0.0/24' WHERE name = 'wg0'; UPDATE user_configs_table SET host = %s, default_dns = '[\"10.6.0.1\"]', default_persistent_keepalive = 25; UPDATE general_table SET setup_step = 0;" || true
SH,
                escapeshellarg("INIT_HOST={$advertisedHost}"),
                $this->sqliteString($advertisedHost),
            ),
            "Could not start wg-easy on {$gateway->name()}",
            timeoutSeconds: 240,
        );
    }

    /**
     * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key?: string}>  $peers
     */
    public function configurePeers(E2EInstance $gateway, array $peers): void
    {
        $statements = [$this->pruneClientsStatement($peers)];
        $runtimeCommands = [
            'docker exec wg-easy sh -lc '.escapeshellarg('wg show wg0 peers | while read -r peer; do if [ -n "$peer" ]; then wg set wg0 peer "$peer" remove; fi; done'),
        ];

        foreach ($peers as $peer) {
            $name = $this->sqliteString($peer['name']);
            $address = $this->sqliteString($peer['address']);
            $ipv6 = $this->sqliteString($this->ipv6For($peer['address']));
            $privateKey = $this->sqliteString($peer['private_key']);
            $publicKey = $this->sqliteString($peer['public_key']);
            $rawPreSharedKey = $peer['pre_shared_key'] ?? $this->preSharedKeyFor($peer['public_key']);
            $preSharedKey = $this->sqliteString($rawPreSharedKey);
            $allowedIps = $this->sqliteString('["0.0.0.0/0", "::/0"]');
            $serverAllowedIps = $this->sqliteString('["'.$peer['address'].'/32"]');
            $dns = $this->sqliteString('["10.6.0.1"]');

            $statements[] = <<<SQL
DELETE FROM clients_table WHERE name = {$name} OR public_key = {$publicKey} OR ipv4_address = {$address};
INSERT INTO clients_table (
    user_id,
    interface_id,
    name,
    ipv4_address,
    ipv6_address,
    private_key,
    public_key,
    pre_shared_key,
    allowed_ips,
    server_allowed_ips,
    persistent_keepalive,
    mtu,
    dns,
    enabled
) VALUES (
    1,
    'wg0',
    {$name},
    {$address},
    {$ipv6},
    {$privateKey},
    {$publicKey},
    {$preSharedKey},
    {$allowedIps},
    {$serverAllowedIps},
    25,
    1420,
    {$dns},
    1
);
SQL;

            $runtimeCommands[] = sprintf(
                'docker exec wg-easy sh -lc %s',
                escapeshellarg(sprintf(
                    'tmp="$(mktemp)" && printf %s %s > "$tmp" && wg set wg0 peer %s preshared-key "$tmp" allowed-ips %s; status="$?"; rm -f "$tmp"; exit "$status"',
                    escapeshellarg('%s\n'),
                    escapeshellarg($rawPreSharedKey),
                    escapeshellarg($peer['public_key']),
                    escapeshellarg($peer['address'].'/32'),
                )),
            );
        }

        E2ECommand::exec(
            $gateway,
            sprintf(
                <<<'SH'
set -euo pipefail
sudo chown -R orbit:orbit /home/orbit/.wg-easy
sqlite3 /home/orbit/.wg-easy/wg-easy.db <<'ORBIT_WG_EASY_SQL'
%s
ORBIT_WG_EASY_SQL
%s
SH,
                implode("\n", $statements),
                implode("\n", $runtimeCommands),
            ),
            "Could not configure wg-easy peers on {$gateway->name()}",
            timeoutSeconds: 120,
        );
    }

    /**
     * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key?: string}>  $peers
     */
    private function pruneClientsStatement(array $peers): string
    {
        if ($peers === []) {
            return 'DELETE FROM clients_table;';
        }

        $names = array_map(
            fn (array $peer): string => $this->sqliteString($peer['name']),
            $peers,
        );

        return 'DELETE FROM clients_table WHERE name NOT IN ('.implode(', ', $names).');';
    }

    private function sqliteString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function ipv6For(string $ipv4): string
    {
        $lastOctet = (int) substr(strrchr($ipv4, '.') ?: '.0', 1);

        return 'fdcc:ad94:bacf:61a4::cafe:'.dechex($lastOctet);
    }

    private function preSharedKeyFor(string $publicKey): string
    {
        return base64_encode(hash('sha256', "orbit-e2e-{$publicKey}", binary: true));
    }
}
