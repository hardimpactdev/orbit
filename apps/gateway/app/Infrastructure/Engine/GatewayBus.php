<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

/**
 * The machine bus's server on the Gateway host (ADR 0271; docs/reference/gateway-engine.md#the-machine-bus): `nats-server` with
 * JetStream and one account, `ORBIT`. The Gateway joins as its nkey user. Every other connection goes to the Gateway's auth
 * callout, which admits only an active Node's WireGuard address. The configuration holds only public keys; the seed stays in
 * the Gateway's environment. Only a Gateway with the engine's key runs it.
 */
final readonly class GatewayBus
{
    public const string ConfigPath = '/etc/nats/nats.conf';

    public const string Binary = '/usr/local/bin/nats-server';

    public const string Version = '2.15.2';

    /** The release tarball's SHA-256, as nats-io publishes it in the release's SHA256SUMS. */
    public const string Sha256 = '67cb8e10bc6f0c4b47f75a728529a20953dbae2a4f9a4a52ae8517d65ae303ff';

    /** The configuration, or null when the Gateway has no engine key. */
    public static function config(): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $port = config('orbit.engine.nats.port');
        $gateway = self::gatewayKey();

        return "# Managed by Orbit: the machine bus (ADR 0271). Public keys only; the seed stays in the Gateway's environment.\n"
            .'listen: 0.0.0.0:'.(is_int($port) ? $port : 4222)."\nserver_name: orbit-gateway\nmax_payload: 1MB\n"
            ."jetstream { store_dir: /var/lib/nats, max_memory_store: 64MB, max_file_store: 4GB }\n"
            ."accounts {\n  ORBIT { jetstream: enabled, users: [ { nkey: {$gateway} } ] }\n}\n"
            ."# Every connection but the Gateway's asks the Gateway, which answers for an active Node's WireGuard address.\n"
            .'authorization { auth_callout { issuer: '.NatsCredentials::publicKey(self::seed())
            .", account: ORBIT, auth_users: [ {$gateway} ] } }\n";
    }

    /** The Gateway runs the machine bus only once it holds the engine's key (`orbit:engine-keys`). */
    public static function enabled(): bool
    {
        return self::seed() !== '';
    }

    /** The account seed `ORBIT_NATS_SEED`, or '' when it is not set. */
    public static function seed(): string
    {
        $seed = config('orbit.engine.nats.seed');

        return is_string($seed) ? $seed : '';
    }

    /** The Gateway's own user seed, derived from the account seed. */
    public static function gatewaySeed(): string
    {
        return NatsCredentials::userSeed(self::seed(), 'gateway');
    }

    private static function gatewayKey(): string
    {
        return NatsCredentials::publicKey(self::gatewaySeed());
    }

    /** The server's unit. It names the configuration's digest, so a changed configuration restarts the server. */
    public static function serverUnit(string $config): string
    {
        return "[Unit]\nDescription=NATS JetStream: the Orbit machine bus (ADR 0271)\nAfter=network-online.target\nWants=network-online.target\n"
            .'# configuration sha256 '.hash('sha256', $config)."\n\n[Service]\nType=simple\nUser=nats\nGroup=nats\nStateDirectory=nats\nStateDirectoryMode=0700\n"
            .'ExecStart='.self::Binary.' -c '.self::ConfigPath."\nExecReload=/bin/kill -HUP \$MAINPID\nRestart=always\nRestartSec=2\nLimitNOFILE=65536\n\n"
            ."[Install]\nWantedBy=multi-user.target\n";
    }

    /** @return array{version: string, urls: list<string>, sha256: string, path: string, binary: string} */
    public static function artifact(): array
    {
        $tarball = 'nats-server-v'.self::Version.'-linux-amd64.tar.gz';

        return ['version' => self::Version, 'urls' => ['https://github.com/nats-io/nats-server/releases/download/v'.self::Version.'/'.$tarball],
            'sha256' => self::Sha256, 'path' => '/var/cache/orbit/'.$tarball, 'binary' => self::Binary];
    }
}
