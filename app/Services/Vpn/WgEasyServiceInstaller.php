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
    ) {}

    public function install(string $publicHost, string $passwordHash): void
    {
        if ($publicHost === '') {
            throw new RuntimeException('WG_HOST is required to install wg-easy.');
        }

        if ($passwordHash === '') {
            throw new RuntimeException('A wg-easy admin password hash is required.');
        }

        $directory = $this->rootPath.'/wg-easy';
        File::ensureDirectoryExists($directory);
        $composePath = $directory.'/docker-compose.yaml';

        $compose = $this->renderCompose($publicHost, $passwordHash);
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
    }

    private function renderCompose(string $publicHost, string $passwordHash): string
    {
        $escapedHash = str_replace('$', '$$', $passwordHash);

        return <<<YAML
services:
  wg-easy:
    image: ghcr.io/wg-easy/wg-easy:15
    container_name: wg-easy
    restart: unless-stopped
    environment:
      - WG_HOST={$publicHost}
      - PASSWORD_HASH={$escapedHash}
      - WG_DEFAULT_ADDRESS=10.6.0.x
      - WG_DEFAULT_DNS=10.6.0.1
      - WG_ALLOWED_IPS=10.6.0.0/24
      - WG_PERSISTENT_KEEPALIVE=25
    ports:
      - "51820:51820/udp"
      - "51821:51821/tcp"
    cap_add:
      - NET_ADMIN
      - SYS_MODULE
    sysctls:
      - net.ipv4.conf.all.src_valid_mark=1
      - net.ipv4.ip_forward=1
    volumes:
      - ~/.wg-easy:/etc/wireguard
      - /lib/modules:/lib/modules:ro

YAML;
    }
}
