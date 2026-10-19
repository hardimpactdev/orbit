<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\RoleName;
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

    /** @return array{policy: bool, answers: bool} */
    public function inspect(Node $node): array
    {
        $gateway = Node::query()->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway->value))->first();
        $address = $this->policy->server($node);
        if (! is_string($gateway?->wireguard_ip)) {
            return ['policy' => false, 'answers' => false];
        }
        $domains = $this->policy->domains($node);
        $link = 'orbit';
        $state = $this->read($node, ['sudo', 'cat', '--', '/etc/wireguard/orbit.dns-link']);
        $configuration = $this->read($node, ['sudo', 'cat', '--', '/etc/wireguard/orbit.conf']);
        $serverMatches = $this->setting($node, 'dns', $link) === $address;
        $liveDomains = explode(' ', $this->setting($node, 'domain', $link) ?? '');
        sort($liveDomains, SORT_STRING);
        $expectedDomains = array_map(static fn (string $domain): string => '~'.$domain, $domains);
        $hook = 'PostUp = resolvectl dns %i '.$address.'; resolvectl domain %i '.implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $domains));
        preg_match_all('/^PostUp = resolvectl[^\r\n]*$/m', $configuration ?? '', $hooks);
        $persistentMatches = $hooks[0] === [$hook];
        $defaultRoute = $this->setting($node, 'default-route', $link);
        $answer = $this->read($node, ['dig', '+time=2', '+tries=1', '+short', '@'.$address, 'gateway.orbit', 'A']);

        return [
            'policy' => $state === implode("\n", [$link, $address, ...$domains])
                && $serverMatches && $liveDomains === $expectedDomains && $persistentMatches
                && $defaultRoute === 'no',
            'answers' => $answer !== null && in_array($gateway->wireguard_ip, explode("\n", $answer), true),
        ];
    }

    /** @return array{private: ?string, public: ?string, valid: bool} */
    public function queries(Node $node, string $privateDomain, string $publicDomain): array
    {
        $domains = $this->policy->domains($node);
        $isPrivate = static fn (string $name): bool => array_any($domains, static fn (string $domain): bool => $name === $domain || str_ends_with($name, '.'.$domain));
        if (! $isPrivate($privateDomain) || $isPrivate($publicDomain)) {
            return ['private' => null, 'public' => null, 'valid' => false];
        }
        $private = $this->read($node, ['resolvectl', 'query', '--cache=no', '--type=A', $privateDomain]);
        $public = $this->read($node, ['resolvectl', 'query', '--cache=no', '--type=A', $publicDomain]);
        preg_match('/-- link: ([^\s]+)/', $private ?? '', $privateLink);
        preg_match('/-- link: ([^\s]+)/', $public ?? '', $publicLink);

        return ['private' => $private, 'public' => $public, 'valid' => ($privateLink[1] ?? '') === 'orbit' && isset($publicLink[1]) && $publicLink[1] !== 'orbit'];
    }

    private function setting(Node $node, string $setting, string $link): ?string
    {
        $output = $this->read($node, ['resolvectl', $setting, $link]);
        if ($output === null || ! str_contains($output, ':')) {
            return null;
        }

        return trim(explode(':', $output, 2)[1]);
    }

    /** @param non-empty-list<string> $arguments */
    private function read(Node $node, array $arguments): ?string
    {
        $result = $this->ssh->execute(
            new SshConnection((string) $node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path(), commandTimeout: $this->deadline->cap(10.0)),
            new RemoteCommand($arguments),
        );

        return $result->succeeded() && ! $result->truncated ? trim($result->stdout) : null;
    }
}
