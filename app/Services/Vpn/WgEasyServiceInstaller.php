<?php

declare(strict_types=1);

namespace App\Services\Vpn;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WgEasyServiceInstaller
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ?string $statePath = null,
    ) {}

    public function install(string $publicHost, string $username, string $password): void
    {
        if ($publicHost === '') {
            throw new RuntimeException('INIT_HOST is required to install wg-easy.');
        }

        if ($username === '') {
            throw new RuntimeException('A wg-easy admin username is required.');
        }

        if ($password === '') {
            throw new RuntimeException('A wg-easy admin password is required.');
        }

        $directory = $this->rootPath.'/wg-easy';
        File::ensureDirectoryExists($directory);
        File::ensureDirectoryExists($this->statePath());
        $composePath = $directory.'/docker-compose.yaml';

        $compose = $this->renderCompose($publicHost, $username, $password);
        $existing = File::exists($composePath) ? File::get($composePath) : null;

        if ($existing !== $compose) {
            File::put($composePath, $compose);
        }

        $result = Process::timeout(180)->run(sprintf(
            'docker compose -f %s up -d',
            escapeshellarg($composePath),
        ));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to start wg-easy: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $this->waitUntilReady();
        $this->convergeServerAddress($publicHost);
    }

    public function publicKey(): string
    {
        $this->waitUntilReady();

        $result = Process::timeout(30)->run('docker exec wg-easy wg show wg0 public-key');

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to read wg-easy WireGuard public key: '.trim($result->errorOutput().' '.$result->output())
            );
        }

        $publicKey = trim($result->output());

        if ($publicKey === '') {
            throw new RuntimeException('wg-easy WireGuard public key is empty.');
        }

        return $publicKey;
    }

    /**
     * @param  list<array{name: string, private_key: string, public_key: string, address: string, pre_shared_key: string}>  $peers
     */
    public function configurePeers(array $peers): void
    {
        if ($peers === []) {
            return;
        }

        $this->waitUntilReady();

        $statements = [];
        $runtimeCommands = [];

        foreach ($peers as $peer) {
            $name = $this->sqliteString($peer['name']);
            $address = $this->sqliteString($peer['address']);
            $ipv6 = $this->sqliteString($this->ipv6For($peer['address']));
            $privateKey = $this->sqliteString($peer['private_key']);
            $publicKey = $this->sqliteString($peer['public_key']);
            $preSharedKey = $this->sqliteString($peer['pre_shared_key']);
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
                    escapeshellarg($peer['pre_shared_key']),
                    escapeshellarg($peer['public_key']),
                    escapeshellarg($peer['address'].'/32'),
                )),
            );
        }

        $script = sprintf(
            <<<'SH'
set -euo pipefail
sqlite3 %s/wg-easy.db <<'ORBIT_WG_EASY_SQL'
%s
ORBIT_WG_EASY_SQL
%s
SH,
            $this->statePathForShell(),
            implode("\n", $statements),
            implode("\n", $runtimeCommands),
        );

        $result = Process::timeout(120)->run($script);

        if (! $result->successful()) {
            throw new RuntimeException(
                'Failed to configure wg-easy peers: '.trim($result->errorOutput().' '.$result->output())
            );
        }
    }

    private function waitUntilReady(): void
    {
        $result = Process::timeout(75)->run(sprintf(
            <<<'SH'
for i in $(seq 1 60); do
    test -f %s/wg-easy.db && docker exec wg-easy ip link show wg0 >/dev/null 2>&1 && exit 0
    sleep 1
done
exit 1
SH,
            $this->statePathForShell(),
        ));

        if ($result->successful()) {
            $this->ensureStateWritable();

            return;
        }

        throw new RuntimeException(
            'wg-easy did not become ready: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function convergeServerAddress(string $publicHost): void
    {
        $result = Process::timeout(30)->run(sprintf(
            <<<'SH'
docker exec wg-easy ip addr replace 10.6.0.1/24 dev wg0
docker exec wg-easy ip route replace 10.6.0.0/24 dev wg0
sqlite3 %s/wg-easy.db "UPDATE interfaces_table SET ipv4_cidr = '10.6.0.0/24' WHERE name = 'wg0'; UPDATE user_configs_table SET host = %s, default_dns = '[\"10.6.0.1\"]', default_persistent_keepalive = 25; UPDATE general_table SET setup_step = 0;" || true
SH,
            $this->statePathForShell(),
            $this->sqliteString($publicHost),
        ));

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException(
            'Failed to converge wg-easy server address: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function ensureStateWritable(): void
    {
        $result = Process::timeout(30)->run(sprintf(
            <<<'SH'
set -e
if command -v sudo >/dev/null 2>&1; then
    sudo chown -R "$(id -u):$(id -g)" %s
else
    chown -R "$(id -u):$(id -g)" %s
fi
SH,
            $this->statePathForShell(),
            $this->statePathForShell(),
        ));

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException(
            'Failed to make wg-easy state writable: '.trim($result->errorOutput().' '.$result->output())
        );
    }

    private function renderCompose(string $publicHost, string $username, string $password): string
    {
        return <<<YAML
services:
  wg-easy:
    image: ghcr.io/wg-easy/wg-easy:15
    container_name: wg-easy
    restart: unless-stopped
    environment:
{$this->composeEnvironmentLine('INIT_ENABLED', 'true')}
{$this->composeEnvironmentLine('INIT_USERNAME', $username)}
{$this->composeEnvironmentLine('INIT_PASSWORD', $password)}
{$this->composeEnvironmentLine('INIT_HOST', $publicHost)}
{$this->composeEnvironmentLine('INIT_PORT', '51820')}
{$this->composeEnvironmentLine('INIT_DNS', '10.6.0.1')}
{$this->composeEnvironmentLine('INIT_ALLOWED_IPS', '10.6.0.0/24')}
{$this->composeEnvironmentLine('INSECURE', 'true')}
{$this->composeEnvironmentLine('PORT', '51821')}
{$this->composeEnvironmentLine('HOST', '0.0.0.0')}
{$this->composeEnvironmentLine('DISABLE_IPV6', 'true')}
    ports:
      - "51820:51820/udp"
      - "127.0.0.1:51821:51821/tcp"
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    sysctls:
      - net.ipv4.conf.all.src_valid_mark=1
      - net.ipv4.ip_forward=1
    volumes:
      - {$this->statePath()}:/etc/wireguard
      - /lib/modules:/lib/modules:ro

YAML;
    }

    private function composeEnvironmentLine(string $key, string $value): string
    {
        return "      - '".$key.'='.str_replace("'", "''", $value)."'\n";
    }

    private function statePath(): string
    {
        if ($this->statePath !== null) {
            return $this->statePath;
        }

        return rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '/home/orbit'), '/').'/.wg-easy';
    }

    private function statePathForShell(): string
    {
        return escapeshellarg($this->statePath());
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
}
