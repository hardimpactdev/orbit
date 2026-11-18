<?php

declare(strict_types=1);

namespace App\Infrastructure\Engine;

/**
 * The machine bus's server on the Gateway host (ADR 0271; docs/reference/gateway-engine.md#the-server): `nats-server` with
 * JetStream, trusting the Gateway's operator and its two accounts. Its configuration holds only signed public JWTs; the seeds
 * stay in the Gateway's environment. Only a Gateway with the engine's keys runs it.
 */
final readonly class GatewayBus
{
    public const string ConfigPath = '/etc/nats/nats.conf';

    public const string Binary = '/usr/local/bin/nats-server';

    public const string Version = '2.15.2';

    /** The release tarball's SHA-256, as nats-io publishes it in the release's SHA256SUMS. */
    public const string Sha256 = '67cb8e10bc6f0c4b47f75a728529a20953dbae2a4f9a4a52ae8517d65ae303ff';

    /** The configuration, or null when the Gateway has no engine keys. */
    public static function config(): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $operator = (string) config('orbit.engine.nats.operator_seed');
        $account = (string) config('orbit.engine.nats.account_seed');
        $system = (string) config('orbit.engine.nats.system_seed');
        $port = config('orbit.engine.nats.port');
        $accountKey = NatsCredentials::publicKey($account);
        $systemKey = NatsCredentials::publicKey($system);

        return "# Managed by Orbit: the machine bus (ADR 0271). Signed public JWTs only; the seeds stay in the Gateway's environment.\n"
            .'listen: 0.0.0.0:'.(is_int($port) ? $port : 4222)."\nserver_name: orbit-gateway\nmax_payload: 1MB\n"
            ."jetstream { store_dir: /var/lib/nats, max_memory_store: 64MB, max_file_store: 4GB }\n"
            .'operator: '.NatsCredentials::operatorJwt($operator, $systemKey)."\nsystem_account: {$systemKey}\nresolver: MEMORY\n"
            ."resolver_preload: {\n  {$accountKey}: ".NatsCredentials::accountJwt($operator, $account, 'ORBIT', true)
            ."\n  {$systemKey}: ".NatsCredentials::accountJwt($operator, $system, 'SYS', false)."\n}\n";
    }

    /** The server's unit. It names the configuration's digest, so a changed configuration restarts the server. */
    /** The Gateway runs the machine bus only once it holds all three keys (`orbit:engine-keys`). */
    public static function enabled(): bool
    {
        foreach (['operator_seed', 'account_seed', 'system_seed'] as $key) {
            $seed = config("orbit.engine.nats.{$key}");
            if (! is_string($seed) || $seed === '') {
                return false;
            }
        }

        return true;
    }

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
