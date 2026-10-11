<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use RuntimeException;

/**
 * Task VM hosts that answer the Gateway's SSH commands by argv, keyed by WireGuard address. Each host keeps
 * its images, instances, Incus listen address, trust store, client directory, and ufw rules. An image copy
 * succeeds only as Incus would allow it: the source listens on the remote's address, trusts the target's
 * client certificate for the source project, and has the pinned server certificate.
 */
final class FakeIncusFleet implements SshExecutor
{
    /** @var array<string, FakeIncusFleetHost> */
    public array $hosts = [];

    /** @var list<array{string, list<string>, ?string}> each command as host, argv, stdin */
    public array $commands = [];

    /** The setup script's answer. */
    public string $setupOutput = "{\"ok\":true}\n";

    public function host(string $address): FakeIncusFleetHost
    {
        return $this->hosts[$address] ??= new FakeIncusFleetHost;
    }

    /** @return list<string> the commands that ran on `$address`, without `sudo -n` */
    public function argv(string $address): array
    {
        return array_values(array_map(
            static fn (array $command): string => implode(' ', array_slice($command[1], 2)),
            array_filter($this->commands, static fn (array $command): bool => $command[0] === $address),
        ));
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $argv = $command->arguments;
        $this->commands[] = [$connection->host, $argv, $command->input];
        $host = $this->host($connection->host);
        if ($argv[0] === 'sudo' && $argv[1] === 'ufw') {
            return $host->ufw(array_slice($argv, 2));
        }
        if ($argv[0] !== 'sudo' || $argv[1] !== '-n') {
            throw new RuntimeException('Unexpected command: '.implode(' ', $argv));
        }

        return match ($argv[2]) {
            'incus' => $argv[3] === '--project'
                ? $host->project(array_slice($argv, 5), $command->input)
                : $this->incus($connection->host, array_slice($argv, 3), $command->input),
            'bash' => $argv[3] === '-s' ? new CommandResult(0, $this->setupOutput, '', 1, false) : $this->bash($host, $argv[4], array_slice($argv, 6), (string) $command->input),
            'env' => $this->copy($connection->host, array_slice($argv, 5)),
            'rm' => $host->answer('rm', function () use ($host): void {
                $host->clientCertificate = $host->clientKey = $host->remotes = null;
                $host->serverCertificates = [];
            }),
            default => throw new RuntimeException('Unexpected command: '.implode(' ', $argv)),
        };
    }

    /** @param  list<string>  $argv */
    private function incus(string $address, array $argv, ?string $input): CommandResult
    {
        $host = $this->host($address);

        return match (true) {
            $argv[0] === 'project' && $argv[1] === 'set' => $host->answer('project set', function () use ($host, $argv): void {
                foreach (array_slice($argv, 3) as $setting) {
                    [$key, $value] = explode('=', $setting, 2);
                    $host->projects[$argv[2]][$key] = $value;
                }
            }),
            $argv[0] === 'project' && $argv[1] === 'get' => $host->answer('project get', fn (): string => ($host->projects[$argv[2]][$argv[3]] ?? '')."\n"),
            $argv === ['config', 'get', 'core.https_address'] => $host->answer('config get', fn (): string => $host->httpsAddress."\n"),
            $argv[0] === 'config' && $argv[1] === 'set' => $host->answer('config set', function () use ($host, $argv): void {
                $host->httpsAddress = $argv[3];
            }),
            $argv === ['config', 'unset', 'core.https_address'] => $host->answer('config unset', function () use ($host): void {
                $host->httpsAddress = '';
            }),
            $argv === ['config', 'trust', 'list', '--format', 'json'] => $host->answer('trust list', fn (): string => (string) json_encode(array_values(array_map(
                static fn (string $fingerprint, array $entry): array => ['fingerprint' => $fingerprint, ...$entry],
                array_keys($host->trust),
                $host->trust,
            )))),
            $argv[0] === 'config' && $argv[2] === 'remove' => $host->answer('trust remove', function () use ($host, $argv): void {
                unset($host->trust[$argv[3]]);
            }),
            $argv === ['query', '/1.0'] => $host->answer('query /1.0', fn (): string => (string) json_encode(['environment' => ['certificate' => $host->serverCertificate()]])),
            $argv[0] === 'query' && $host->fail === 'query reply' => (function () use ($host, $argv): CommandResult {
                $host->retarget(json_decode($argv[4], true)['target']);

                return new CommandResult(255, '', "Connection reset by peer\n", 1, false);
            })(),
            $argv[0] === 'query' => $host->answer('query', fn () => $host->retarget(json_decode($argv[4], true)['target'])),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $argv)),
        };
    }

    /** @param  list<string>  $arguments */
    private function bash(FakeIncusFleetHost $host, string $script, array $arguments, string $input): CommandResult
    {
        return match (true) {
            str_contains($script, 'generate-certificate') => $host->answer('generate-certificate', static function () use ($host): string {
                if ($host->clientCertificate === null) {
                    [$host->clientCertificate, $host->clientKey] = FakeIncusFleetHost::certificateWithKey('client');
                }

                return $host->clientCertificate;
            }),
            str_contains($script, 'add-certificate') => $host->answer('add-certificate', function () use ($host, $arguments, $input): void {
                $fingerprint = (string) openssl_x509_fingerprint($input, 'sha256');
                if (isset($host->trust[$fingerprint])) {
                    throw new RuntimeException('Certificate already in trust store');
                }
                $host->trust[$fingerprint] = ['name' => $arguments[0], 'type' => 'client', 'restricted' => true, 'projects' => [$arguments[1]], 'certificate' => $input];
            }),
            str_contains($script, 'servercerts') => $host->answer('remotes', function () use ($host, $arguments, $input): void {
                $host->remotes = $input;
                $host->serverCertificates = [];
                for ($index = 1; $index < count($arguments); $index += 2) {
                    $host->serverCertificates[$arguments[$index]] = (string) base64_decode($arguments[$index + 1], true);
                }
            }),
            default => throw new RuntimeException('Unexpected script: '.$script),
        };
    }

    /**
     * `incus image copy --quiet --project <p> --target-project <t> <remote>:<fingerprint> local: --alias <a>`,
     * run on the target as `env INCUS_CONF=… incus …`.
     *
     * @param  list<string>  $argv
     */
    private function copy(string $address, array $argv): CommandResult
    {
        $target = $this->host($address);
        if ($target->fail === 'image copy') {
            return new CommandResult(1, '', "Error: image copy failed\n", 1, false);
        }
        [$remote, $fingerprint] = explode(':', $argv[7]);
        $alias = $argv[10];
        preg_match('/  '.preg_quote($remote, '/').":\n    addr: https:\/\/([0-9.]+:8443)\n    auth_type: tls\n    project: ([a-z0-9-]+)\n/", (string) $target->remotes, $matches);
        $sources = array_filter($this->hosts, static fn (FakeIncusFleetHost $host): bool => ($matches[1] ?? null) !== null && $host->httpsAddress === $matches[1]);
        $source = array_values($sources)[0] ?? null;
        if (! $source instanceof FakeIncusFleetHost) {
            return new CommandResult(1, '', "Error: Get \"https://{$remote}\": connection refused\n", 1, false);
        }
        if (($target->serverCertificates[$remote] ?? null) !== $source->serverCertificate()) {
            return new CommandResult(1, '', "Error: x509: certificate signed by unknown authority\n", 1, false);
        }
        $trusted = $source->trust[(string) openssl_x509_fingerprint((string) $target->clientCertificate, 'sha256')] ?? null;
        if ($trusted === null || ! in_array($argv[4], $trusted['projects'], true)) {
            return new CommandResult(1, '', "Error: not authorized\n", 1, false);
        }
        if (! isset($source->images[$fingerprint])) {
            return new CommandResult(1, '', "Error: Image not found\n", 1, false);
        }
        $copied = $target->copyAs ?? $fingerprint;
        $target->images[$copied] = [$alias];

        return new CommandResult(0, '', '', 1, false);
    }
}
