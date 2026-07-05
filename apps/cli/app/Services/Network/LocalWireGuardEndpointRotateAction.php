<?php

declare(strict_types=1);

namespace App\Services\Network;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final readonly class LocalWireGuardEndpointRotateAction
{
    private const array ConfigCandidates = [
        '/etc/wireguard/wg-orbit.conf',
        '/etc/wireguard/wg0.conf',
    ];

    private const string HostPattern = '/\A([A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?|[0-9a-fA-F:.]+)\z/';

    /**
     * @return array<string, mixed>
     */
    public function run(mixed $endpoint): array
    {
        $endpoint = $this->endpoint($endpoint);
        $config = $this->configPath();

        $this->ensureEndpointLineExists($config);

        $timestamp = gmdate('YmdHis');
        $backup = "{$config}.before-gateway-endpoint-{$timestamp}";
        $this->mustRun(['sudo', 'cp', '-a', $config, $backup], 'copy_failed');
        $this->mustRun([
            'sudo',
            'sed',
            '-i',
            '-E',
            "s#^Endpoint[[:space:]]*=.*#Endpoint = {$endpoint}#",
            $config,
        ], 'replace_failed');

        $peersUpdated = $this->rotateLivePeers($config, $endpoint);

        return [
            'endpoint' => $endpoint,
            'config_path' => $config,
            'backup_path' => $backup,
            'interface' => basename($config, '.conf'),
            'peers_updated' => $peersUpdated,
        ];
    }

    private function endpoint(mixed $value): string
    {
        if (! is_string($value)) {
            throw new LocalWireGuardEndpointRotateFailure(
                errorCode: 'validation_failed',
                message: 'WireGuard endpoint must be a host and port.',
                meta: ['field' => 'endpoint'],
            );
        }

        $endpoint = trim($value);
        $separator = strrpos($endpoint, ':');

        if ($separator === false) {
            throw new LocalWireGuardEndpointRotateFailure(
                errorCode: 'validation_failed',
                message: 'WireGuard endpoint must include a port.',
                meta: ['field' => 'endpoint'],
            );
        }

        $host = substr($endpoint, 0, $separator);
        $port = substr($endpoint, $separator + 1);

        if (
            $host === ''
            || preg_match(self::HostPattern, $host) !== 1
            || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false
        ) {
            throw new LocalWireGuardEndpointRotateFailure(
                errorCode: 'validation_failed',
                message: 'WireGuard endpoint must be a valid host and port.',
                meta: ['field' => 'endpoint'],
            );
        }

        return "{$host}:{$port}";
    }

    private function configPath(): string
    {
        foreach (self::ConfigCandidates as $candidate) {
            $result = $this->runProcess(['sudo', 'test', '-f', $candidate]);

            if ($result['exit_code'] === 0) {
                return $candidate;
            }
        }

        throw new LocalWireGuardEndpointRotateFailure(
            errorCode: 'wireguard_config_missing',
            message: 'No WireGuard config file found for endpoint rotation.',
        );
    }

    private function ensureEndpointLineExists(string $config): void
    {
        $result = $this->runProcess(['sudo', 'grep', '-qE', '^Endpoint[[:space:]]*=', $config]);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalWireGuardEndpointRotateFailure(
            errorCode: 'wireguard_endpoint_missing',
            message: 'WireGuard config does not contain an Endpoint line.',
            meta: ['config_path' => $config],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command, string $errorCode): void
    {
        $result = $this->runProcess($command);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalWireGuardEndpointRotateFailure(
            errorCode: $errorCode,
            message: 'WireGuard endpoint rotation command failed.',
            meta: [
                'command' => implode(' ', $command),
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    private function rotateLivePeers(string $config, string $endpoint): int
    {
        $interface = basename($config, '.conf');
        $result = $this->runProcess(['sudo', 'wg', 'show', $interface, 'peers']);

        if ($result['exit_code'] !== 0) {
            return 0;
        }

        $peersUpdated = 0;

        foreach (preg_split('/\R/', $result['output']) ?: [] as $peer) {
            $peer = trim($peer);

            if ($peer === '') {
                continue;
            }

            $this->mustRun([
                'sudo',
                'wg',
                'set',
                $interface,
                'peer',
                $peer,
                'endpoint',
                $endpoint,
            ], 'peer_update_failed');
            $peersUpdated++;
        }

        return $peersUpdated;
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout(15);
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => trim($process->getOutput().$process->getErrorOutput()),
            ];
        } catch (ProcessStartFailedException $exception) {
            return [
                'exit_code' => 127,
                'output' => $exception->getMessage(),
            ];
        }
    }
}
