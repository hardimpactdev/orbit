<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;

/** Compares a peer's saved and live DNS state with the policy, and checks its lookups, through agent reads. */
final readonly class PeerDnsInspector
{
    public function __construct(
        private PeerDnsPolicy $policy,
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
    ) {}

    /** @return array{policy: bool, uplink: bool} */
    public function inspect(Node $node): array
    {
        $address = $this->policy->server($node);
        $domains = $this->policy->domains($node);
        $observed = $this->read($node, 'wireguard.inspect-peer-dns', ['server' => $address]);
        $values = [];
        foreach (['state', 'hooks', 'dns', 'domains', 'default_route'] as $field) {
            if (! is_string($observed[$field] ?? null)) {
                throw new \RuntimeException('Peer DNS inspection returned incomplete state.');
            }
            $values[$field] = $observed[$field];
        }
        if (! is_bool($observed['uplink'] ?? null)) {
            throw new \RuntimeException('Peer DNS inspection returned invalid uplink state.');
        }
        $liveDomains = explode(' ', $this->setting($values['domains']) ?? '');
        sort($liveDomains, SORT_STRING);
        $expectedDomains = array_map(static fn (string $domain): string => '~'.$domain, $domains);
        $hook = 'PostUp = resolvectl dns %i '.$address.'; resolvectl domain %i '.implode(' ', array_map(static fn (string $domain): string => '\\~'.$domain, $domains));

        return ['policy' => $values['state'] === implode("\n", ['orbit', $address, ...$domains])
            && $this->setting($values['dns']) === $address && $liveDomains === $expectedDomains
            && $values['hooks'] === $hook && $this->setting($values['default_route']) === 'no', 'uplink' => $observed['uplink']];
    }

    /** @return array{private: ?string, public: ?string, private_valid: bool, public_valid: bool, valid: bool} */
    public function queries(Node $node, string $privateDomain, string $publicDomain): array
    {
        $domains = $this->policy->domains($node);
        $isPrivate = static fn (string $name): bool => array_any($domains, static fn (string $domain): bool => $name === $domain || str_ends_with($name, '.'.$domain));
        if (! $isPrivate($privateDomain) || $isPrivate($publicDomain)) {
            return ['private' => null, 'public' => null, 'private_valid' => false, 'public_valid' => false, 'valid' => false];
        }
        $answers = $this->read($node, 'wireguard.query-dns', ['names' => [$privateDomain, $publicDomain]])['answers'] ?? null;
        if (! is_array($answers) || count($answers) !== 2) {
            throw new \RuntimeException('Peer DNS lookups returned incomplete answers.');
        }
        [$private, $public] = array_map(static fn (mixed $answer): ?string => is_string($answer) ? $answer : null, array_values($answers));
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

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function read(Node $node, string $command, array $input): array
    {
        try {
            return $this->agents->run($node, $command, $input, $this->deadline->cap(10.0));
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new \RuntimeException('Peer DNS inspection failed.', previous: $exception);
        }
    }
}
