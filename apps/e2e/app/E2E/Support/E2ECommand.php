<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

final readonly class E2ECommand
{
    private const string GatewayConfigRoot = '/home/orbit/.config/orbit';

    public static function exec(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $instance->exec($command, $timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException(trim($message."\n".$result->output().$result->errorOutput()));
        }

        return $result;
    }

    public static function orbit(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        return self::exec(
            $instance,
            'sudo -iu orbit env ORBIT_GATEWAY_CONTAINER="${ORBIT_GATEWAY_CONTAINER:-}" ORBIT_E2E_DOCKER_NETWORK="${ORBIT_E2E_DOCKER_NETWORK:-}" ORBIT_CONFIG_ROOT="${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}" DB_CONNECTION="${DB_CONNECTION:-sqlite}" DB_DATABASE="${DB_DATABASE:-/home/orbit/.config/orbit/gateway.sqlite}" SESSION_DRIVER="${SESSION_DRIVER:-file}" bash -lc '.escapeshellarg($command),
            $message,
            $timeoutSeconds,
        );
    }

    public static function gatewayArtisan(E2EInstance $gateway, string $arguments, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        return self::exec(
            $gateway,
            self::gatewayArtisanCommand($arguments),
            $message,
            $timeoutSeconds,
        );
    }

    public static function gatewayArtisanCommand(string $arguments): string
    {
        $configRoot = self::GatewayConfigRoot;
        $gatewayImage = DockerTopologyProvider::gatewayImage();

        $docker = implode(' ', [
            'docker run --rm --pull never',
            ...E2EGitHubAuth::dockerEnvOptions(),
            '--env '.escapeshellarg("ORBIT_CONFIG_ROOT={$configRoot}"),
            '--env '.escapeshellarg('DB_CONNECTION=sqlite'),
            '--env '.escapeshellarg("DB_DATABASE={$configRoot}/gateway.sqlite"),
            '--env '.escapeshellarg('SESSION_DRIVER=file'),
            '--mount '.escapeshellarg("type=bind,source={$configRoot},target={$configRoot}"),
            '-v '.escapeshellarg('/root/.ssh:/root/.ssh:ro'),
            '-v '.escapeshellarg('/home/orbit/.ssh:/home/orbit/.ssh:ro'),
            escapeshellarg($gatewayImage),
            'artisan',
            $arguments,
        ]);

        return "status=0; {$docker} || status=\$?; chown -R orbit:orbit ".escapeshellarg($configRoot).' 2>/dev/null || true; exit "$status"';
    }

    public static function ssh(E2EInstance $instance, string $user, SshKeyPair $key, string $command, ?int $timeoutSeconds = null, bool $allowFailure = false): ProcessResult
    {
        $result = $instance->ssh($user, $key, $command, $timeoutSeconds);

        if (! $allowFailure && ! $result->successful()) {
            throw new RuntimeException(trim("SSH command failed: {$command}\n".$result->output().$result->errorOutput()));
        }

        return $result;
    }
}
