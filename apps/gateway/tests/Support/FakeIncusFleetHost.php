<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Processes\CommandResult;
use Closure;
use OpenSSLAsymmetricKey;
use RuntimeException;

/** One fake task VM host. */
final class FakeIncusFleetHost
{
    /** @var array<string, list<string>> fingerprint => aliases */
    public array $images = [];

    /** @var array<string, ?string> name => base image */
    public array $instances = [];

    public string $httpsAddress = '';

    /** @var array<string, array{name: string, type: string, restricted: bool, projects: list<string>, certificate: string}> */
    public array $trust = [];

    public ?string $clientCertificate = null;

    public ?string $clientKey = null;

    public ?string $remotes = null;

    /** @var array<string, string> remote => PEM */
    public array $serverCertificates = [];

    /** @var list<string> each `ufw status numbered` rule line */
    public array $ufwRules = [];

    /** An operation that fails, such as `image copy`, `launch`, or `ssh-keygen`. */
    public ?string $fail = null;

    /** The fingerprint an image copy produces instead of the source's. */
    public ?string $copyAs = null;

    public int $boots = 1;

    /** The fingerprint a build publishes. */
    public string $publishAs = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private ?string $server = null;

    private int $cloudInitPolls = 0;

    public function serverCertificate(): string
    {
        return $this->server ??= self::certificateWithKey('server')[0];
    }

    /** @return array{string, string} a self-signed PEM certificate and its key */
    public static function certificateWithKey(string $name): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($key instanceof OpenSSLAsymmetricKey);
        $csr = openssl_csr_new(['commonName' => $name.'-'.bin2hex(random_bytes(4))], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $privateKey);

        return [$pem, $privateKey];
    }

    /** @param  list<string>  $operation  the arguments after `incus --project <project>` */
    public function project(array $operation, ?string $input): CommandResult
    {
        return match (true) {
            $operation[0] === 'list' => $this->answer('list', fn (): string => (string) json_encode(array_map(
                static fn (string $name, ?string $image): array => ['name' => $name, 'config' => $image === null ? [] : ['volatile.base_image' => $image]],
                array_keys($this->instances),
                $this->instances,
            ))),
            $operation[0] === 'image' && $operation[1] === 'list' => $this->answer('image list', fn (): string => (string) json_encode(array_map(
                static fn (string $fingerprint, array $aliases): array => ['fingerprint' => $fingerprint, 'aliases' => array_map(static fn (string $name): array => ['name' => $name], $aliases)],
                array_keys($this->images),
                $this->images,
            ))),
            $operation[0] === 'launch' => $this->answer('launch', function () use ($operation): void {
                $this->instances[end($operation)] = $this->fingerprintOf($operation[count($operation) - 2]);
            }),
            $operation[0] === 'exec' => $this->exec(array_slice($operation, array_search('--', $operation, true) + 2)),
            $operation[0] === 'config' && $operation[2] === 'show' => $this->answer('metadata show', static fn (): string => "templates:\n  /etc/hostname:\n    template: hostname.tpl\n"),
            $operation[0] === 'config' && $operation[2] === 'edit' => $this->answer('metadata edit'),
            $operation[0] === 'stop' => $this->answer('stop'),
            $operation[0] === 'publish' => $this->answer('publish', function () use ($operation): void {
                $this->images[$this->publishAs] = [$operation[4]];
            }),
            $operation[0] === 'delete' => $this->answer('delete', function () use ($operation): void {
                unset($this->instances[end($operation)]);
            }),
            $operation[0] === 'image' && $operation[1] === 'alias' => $this->answer('image alias', function () use ($operation): void {
                $this->images[$operation[5]][] = $operation[4];
            }),
            $operation[0] === 'image' && $operation[1] === 'delete' => $this->answer('image delete', function () use ($operation): void {
                unset($this->images[end($operation)]);
            }),
            default => throw new RuntimeException('Unexpected incus command: '.implode(' ', $operation)),
        };
    }

    /** @param  list<string>  $arguments */
    public function ufw(array $arguments): CommandResult
    {
        if ($arguments === ['status', 'numbered']) {
            $lines = array_map(static fn (int $index, string $line): string => sprintf('[%2d] %s', $index + 1, $line), array_keys($this->ufwRules), $this->ufwRules);

            return new CommandResult(0, "Status: active\n\n     To                         Action      From\n     --                         ------      ----\n".implode("\n", $lines)."\n", '', 1, false);
        }
        if ($arguments[0] === '--force' && $arguments[1] === 'delete') {
            array_splice($this->ufwRules, (int) $arguments[2] - 1, 1);

            return new CommandResult(0, "Rule deleted\n", '', 1, false);
        }
        if ($this->fail === 'ufw allow') {
            return new CommandResult(1, '', "ERROR: ufw failed\n", 1, false);
        }
        // allow in [on <interface>] proto tcp from <source> to <destination> port <port> comment <comment>
        $on = $arguments[2] === 'on' ? $arguments[3] : null;
        $rest = array_slice($arguments, $on === null ? 2 : 4);
        $this->ufwRules[] = sprintf('%s %s/tcp%s ALLOW IN %s # %s', $rest[5], $rest[7], $on === null ? '' : " on {$on}", $rest[3], $rest[9]);

        return new CommandResult(0, "Rule added\n", '', 1, false);
    }

    /** @param  Closure(): mixed|null  $effect */
    public function answer(string $operation, ?Closure $effect = null): CommandResult
    {
        if ($this->fail === $operation) {
            return new CommandResult(1, '', "Error: {$operation} failed\n", 1, false);
        }
        $output = $effect instanceof Closure ? $effect() : null;

        return new CommandResult(0, is_string($output) ? $output : '', '', 1, false);
    }

    public function retarget(string $fingerprint): void
    {
        foreach ($this->images as $image => $aliases) {
            $this->images[$image] = array_values(array_diff($aliases, [TaskVmHost::BaseImage]));
        }
        $this->images[$fingerprint][] = TaskVmHost::BaseImage;
    }

    /** @param  list<string>  $command */
    private function exec(array $command): CommandResult
    {
        return match ($command[0]) {
            // The guest agent is not up on the first poll.
            'cloud-init' => $this->cloudInitPolls++ % 2 === 0
                ? new CommandResult(1, '', 'Error: VM agent isn\'t currently running', 1, false)
                : new CommandResult(0, '{"status":"done","errors":[]}', '', 1, false),
            'uname' => new CommandResult(0, "x86_64\n", '', 1, false),
            'bash' => ($command[1] ?? null) === '-s' ? new CommandResult(0, "{\"ok\":true}\n", '', 1, false) : $this->answer('exec'),
            'journalctl' => new CommandResult(0, implode('', array_map(static fn (int $boot): string => "{$boot} 0f1e2d3c4b5a69788796a5b4c3d2e1f0 Sat 2026-10-10 03:01:00 UTC\n", range(1 - $this->boots, 0))), '', 1, false),
            'test' => $this->answer('authorized key'),
            'ssh-keygen' => $this->answer('ssh-keygen', static fn (): string => "256 SHA256:tHGyu/bpS80ShKGdwihLDLg9TzFRZCdsWqnuyInfRsg root@smoke (ED25519)\n"),
            default => $this->answer('exec'),
        };
    }

    private function fingerprintOf(string $alias): ?string
    {
        foreach ($this->images as $fingerprint => $aliases) {
            if (in_array($alias, $aliases, true)) {
                return $fingerprint;
            }
        }

        return null;
    }
}
