<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

final readonly class IncusWorkerNetwork
{
    public function __construct(
        public string $name,
        public string $ipv4Address,
    ) {}

    public static function forSlot(E2EConfig $config, int $slot): self
    {
        if ($slot < 1 || $slot > 200) {
            throw new RuntimeException("Incus worker network slot [{$slot}] is outside the supported range 1-200.");
        }

        return new self(
            name: self::networkName($config->instancePrefix, $slot),
            ipv4Address: "10.232.{$slot}.1/24",
        );
    }

    private static function networkName(string $instancePrefix, int $slot): string
    {
        $suffix = "-n-{$slot}";
        $prefix = strtolower(preg_replace('/[^a-zA-Z0-9-]+/', '-', $instancePrefix) ?? '');
        $prefix = trim($prefix, '-');

        if ($prefix === '') {
            $prefix = 'orbit-e2e';
        }

        return rtrim(substr($prefix, 0, 15 - strlen($suffix)), '-').$suffix;
    }

    public function ensureOn(IncusHost $host): void
    {
        $result = $host->run(sprintf(
            <<<'BASH'
                if incus network show %1$s >/dev/null 2>&1; then
                    incus network set %1$s ipv4.address %2$s
                    incus network set %1$s ipv4.nat true
                    incus network set %1$s ipv6.address none
                    incus network set %1$s raw.dnsmasq port=0
                else
                    incus network create %1$s ipv4.address=%2$s ipv4.nat=true ipv6.address=none raw.dnsmasq=port=0
                fi

                if command -v iptables >/dev/null 2>&1; then
                    sudo_prefix=
                    if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then sudo_prefix=sudo; fi
                    while $sudo_prefix iptables -D FORWARD -i %1$s -j ACCEPT 2>/dev/null; do :; done
                    while $sudo_prefix iptables -D FORWARD -o %1$s -j ACCEPT 2>/dev/null; do :; done
                    $sudo_prefix iptables -I FORWARD 1 -o %1$s -j ACCEPT
                    $sudo_prefix iptables -I FORWARD 1 -i %1$s -j ACCEPT
                fi
                BASH,
            escapeshellarg($this->name),
            escapeshellarg($this->ipv4Address),
        ), timeoutSeconds: 120);

        if (! $result->successful()) {
            throw new RuntimeException(
                "Could not ensure Incus worker network [{$this->name}] on {$host->config->host}: {$result->errorOutput()}{$result->output()}",
            );
        }
    }

    public function attachCommand(string $instance): string
    {
        return sprintf(
            <<<'BASH'
                if incus config device get %1$s eth0 network >/dev/null 2>&1; then
                    incus config device set %1$s eth0 network %2$s
                else
                    incus config device add %1$s eth0 nic network=%2$s name=eth0
                fi
                BASH,
            escapeshellarg($instance),
            escapeshellarg($this->name),
        );
    }

    public static function ensureResultSuccessful(ProcessResult $result, string $message): void
    {
        if (! $result->successful()) {
            throw new RuntimeException("{$message}: {$result->errorOutput()}{$result->output()}");
        }
    }
}
