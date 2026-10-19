<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class PeerDnsInspector
{
    public function __construct(
        private PeerDnsPolicy $policy,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private CommandDeadline $deadline,
    ) {}

    /** @return array{policy: bool} */
    public function inspect(Node $node): array
    {
        $address = $this->policy->server($node);
        $domains = $this->policy->domains($node);
        $output = $this->execute($node, new RemoteCommand(
            ['sudo', 'bash', '-seu', '--', 'inspect'],
            input: PeerDnsProgram::source(),
            maxOutputBytes: 1_048_576,
        ));
        if ($output === null) {
            throw new \RuntimeException('Peer DNS inspection failed.');
        }
        $values = explode("\n", rtrim($output, "\n"));
        if (count($values) !== 5) {
            throw new \RuntimeException('Peer DNS inspection returned incomplete state.');
        }
        $decoded = array_map(static fn (string $value): string|false => base64_decode($value, true), $values);
        if (in_array(false, $decoded, true)) {
            throw new \RuntimeException('Peer DNS inspection returned invalid state.');
        }
        [$state, $hooks, $dns, $live, $default] = $decoded;
        $liveDomains = explode(' ', $this->setting($live) ?? '');
        sort($liveDomains, SORT_STRING);
        $expectedDomains = array_map(static fn (string $domain): string => '~'.$domain, $domains);
        $hook = 'PostUp = resolvectl dns %i '.$address.'; resolvectl domain %i '.implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $domains));

        return ['policy' => $state === implode("\n", ['orbit', $address, ...$domains])
            && $this->setting($dns) === $address && $liveDomains === $expectedDomains
            && $hooks === $hook && $this->setting($default) === 'no'];
    }

    /** @return array{private: ?string, public: ?string, private_valid: bool, public_valid: bool, valid: bool} */
    public function queries(Node $node, string $privateDomain, string $publicDomain): array
    {
        $domains = $this->policy->domains($node);
        $isPrivate = static fn (string $name): bool => array_any($domains, static fn (string $domain): bool => $name === $domain || str_ends_with($name, '.'.$domain));
        if (! $isPrivate($privateDomain) || $isPrivate($publicDomain)) {
            return ['private' => null, 'public' => null, 'private_valid' => false, 'public_valid' => false, 'valid' => false];
        }
        $private = $this->read($node, ['resolvectl', 'query', '--cache=no', '--type=A', $privateDomain]);
        $public = $this->read($node, ['resolvectl', 'query', '--cache=no', '--type=A', $publicDomain]);
        preg_match('/-- link: ([^\s]+)/', $private ?? '', $privateLink);
        preg_match('/-- link: ([^\s]+)/', $public ?? '', $publicLink);

        $privateValid = $private !== null && ($privateLink[1] ?? '') === 'orbit';
        // Successful global DNS answers carry no link attribution.
        $publicValid = $public !== null && $public !== '' && ($publicLink[1] ?? '') !== 'orbit';

        return ['private' => $private, 'public' => $public, 'private_valid' => $privateValid, 'public_valid' => $publicValid, 'valid' => $privateValid && $publicValid];
    }

    private function setting(string $output): ?string
    {
        return str_contains($output, ':') ? trim(explode(':', $output, 2)[1]) : null;
    }

    /** @param non-empty-list<string> $arguments */
    private function read(Node $node, array $arguments): ?string
    {
        return $this->execute($node, new RemoteCommand($arguments));
    }

    private function execute(Node $node, RemoteCommand $command): ?string
    {
        $result = $this->ssh->execute(
            new SshConnection((string) $node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path(), commandTimeout: $this->deadline->cap(10.0)),
            $command,
        );

        if ($result->exitCode === 255) {
            throw new \RuntimeException('Peer DNS SSH inspection failed.');
        }

        return $result->succeeded() && ! $result->truncated ? rtrim($result->stdout, "\r\n") : null;
    }
}
