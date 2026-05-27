<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EWgEasyGateway
{
    private const string WG_EASY_DATABASE_PATH = '/home/orbit/.wg-easy/wg-easy.db';

    public function __construct(
        private string $databasePath = self::WG_EASY_DATABASE_PATH,
    ) {}

    public function start(E2EInstance $gateway, string $advertisedHost): void
    {
        E2ECommand::exec(
            $gateway,
            sprintf(
                <<<'SH'
set -euo pipefail
command -v docker >/dev/null 2>&1 || { echo 'docker is missing from the prepared Incus gateway artifact. Rebuild the prepared topology.' >&2; exit 1; }
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
%s
SH,
                escapeshellarg("INIT_HOST={$advertisedHost}"),
                $this->phpCommand(
                    marker: 'ORBIT_WG_EASY_START_PHP',
                    environment: [
                        'ORBIT_WG_EASY_ADVERTISED_HOST' => $advertisedHost,
                    ],
                    script: $this->startStateScript(),
                ),
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
        $runtimeCommands = [];

        foreach ($peers as $peer) {
            $rawPreSharedKey = $peer['pre_shared_key'] ?? $this->preSharedKeyFor($peer['public_key']);

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
%s
%s
SH,
                $this->phpCommand(
                    marker: 'ORBIT_WG_EASY_PEERS_PHP',
                    environment: [
                        'ORBIT_WG_EASY_PEERS' => $this->encodedPeers($peers),
                    ],
                    script: $this->peersStateScript(),
                ),
                implode("\n", $runtimeCommands),
            ),
            "Could not configure wg-easy peers on {$gateway->name()}",
            timeoutSeconds: 120,
        );
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function phpCommand(string $marker, array $environment, string $script): string
    {
        $environment = [
            'ORBIT_WG_EASY_DB_PATH' => $this->databasePath,
            ...$environment,
        ];

        return sprintf(
            "sudo -u orbit env %s php <<'%s'\n%s\n%s",
            $this->shellEnvironment($environment),
            $marker,
            $script,
            $marker,
        );
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function shellEnvironment(array $environment): string
    {
        $assignments = [];

        foreach ($environment as $key => $value) {
            $assignments[] = "{$key}=".escapeshellarg($value);
        }

        return implode(' ', $assignments);
    }

    /**
     * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key?: string}>  $peers
     */
    private function encodedPeers(array $peers): string
    {
        $json = json_encode($peers, JSON_THROW_ON_ERROR);

        if (! is_string($json)) {
            throw new \JsonException('Could not encode wg-easy peers.');
        }

        return base64_encode($json);
    }

    private function startStateScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$databasePath = getenv('ORBIT_WG_EASY_DB_PATH');
$advertisedHost = getenv('ORBIT_WG_EASY_ADVERTISED_HOST');

if (! is_string($databasePath) || trim($databasePath) === '') {
    fwrite(STDERR, "ORBIT_WG_EASY_DB_PATH is required.\n");
    exit(1);
}

if (! is_string($advertisedHost) || trim($advertisedHost) === '') {
    fwrite(STDERR, "ORBIT_WG_EASY_ADVERTISED_HOST is required.\n");
    exit(1);
}

$database = new PDO('sqlite:'.$databasePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE,
]);

$database->beginTransaction();

try {
    $database->prepare('UPDATE interfaces_table SET ipv4_cidr = :ipv4_cidr WHERE name = :name')->execute([
        'ipv4_cidr' => '10.6.0.0/24',
        'name' => 'wg0',
    ]);

    $database->prepare(<<<'SQL'
        UPDATE user_configs_table
        SET host = :host,
            default_dns = :default_dns,
            default_persistent_keepalive = :default_persistent_keepalive
        SQL)->execute([
        'host' => $advertisedHost,
        'default_dns' => '["10.6.0.1"]',
        'default_persistent_keepalive' => 25,
    ]);

    $database->prepare('UPDATE general_table SET setup_step = :setup_step')->execute([
        'setup_step' => 0,
    ]);

    $database->commit();
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }

    throw $exception;
}
PHP;
    }

    private function peersStateScript(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$databasePath = getenv('ORBIT_WG_EASY_DB_PATH');
$encodedPeers = getenv('ORBIT_WG_EASY_PEERS');

if (! is_string($databasePath) || trim($databasePath) === '') {
    fwrite(STDERR, "ORBIT_WG_EASY_DB_PATH is required.\n");
    exit(1);
}

if (! is_string($encodedPeers) || trim($encodedPeers) === '') {
    fwrite(STDERR, "ORBIT_WG_EASY_PEERS is required.\n");
    exit(1);
}

$json = base64_decode($encodedPeers, true);

if (! is_string($json)) {
    fwrite(STDERR, "ORBIT_WG_EASY_PEERS is invalid.\n");
    exit(1);
}

$peers = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($peers)) {
    fwrite(STDERR, "ORBIT_WG_EASY_PEERS must decode to an array.\n");
    exit(1);
}

$database = new PDO('sqlite:'.$databasePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE,
]);

$deletePeer = $database->prepare('DELETE FROM clients_table WHERE name = :name OR public_key = :public_key OR ipv4_address = :ipv4_address');
$insertPeer = $database->prepare(<<<'SQL'
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
        :user_id,
        :interface_id,
        :name,
        :ipv4_address,
        :ipv6_address,
        :private_key,
        :public_key,
        :pre_shared_key,
        :allowed_ips,
        :server_allowed_ips,
        :persistent_keepalive,
        :mtu,
        :dns,
        :enabled
    )
    SQL);

$database->beginTransaction();

try {
    foreach ($peers as $peer) {
        if (! is_array($peer)) {
            throw new RuntimeException('Each wg-easy peer must be an object.');
        }

        $name = peerString($peer, 'name');
        $address = peerString($peer, 'address');
        $publicKey = peerString($peer, 'public_key');
        $preSharedKey = array_key_exists('pre_shared_key', $peer)
            ? peerString($peer, 'pre_shared_key')
            : base64_encode(hash('sha256', "orbit-e2e-{$publicKey}", binary: true));

        $deletePeer->execute([
            'name' => $name,
            'public_key' => $publicKey,
            'ipv4_address' => $address,
        ]);

        $insertPeer->execute([
            'user_id' => 1,
            'interface_id' => 'wg0',
            'name' => $name,
            'ipv4_address' => $address,
            'ipv6_address' => ipv6For($address),
            'private_key' => peerString($peer, 'private_key'),
            'public_key' => $publicKey,
            'pre_shared_key' => $preSharedKey,
            'allowed_ips' => '["0.0.0.0/0", "::/0"]',
            'server_allowed_ips' => '["'.$address.'/32"]',
            'persistent_keepalive' => 25,
            'mtu' => 1420,
            'dns' => '["10.6.0.1"]',
            'enabled' => 1,
        ]);
    }

    $database->commit();
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }

    throw $exception;
}

function peerString(array $peer, string $key): string
{
    $value = $peer[$key] ?? null;

    if (! is_string($value) || $value === '') {
        throw new RuntimeException("Peer {$key} is required.");
    }

    return $value;
}

function ipv6For(string $ipv4): string
{
    $lastOctet = (int) substr(strrchr($ipv4, '.') ?: '.0', 1);

    return 'fdcc:ad94:bacf:61a4::cafe:'.dechex($lastOctet);
}
PHP;
    }

    private function preSharedKeyFor(string $publicKey): string
    {
        return base64_encode(hash('sha256', "orbit-e2e-{$publicKey}", binary: true));
    }
}
