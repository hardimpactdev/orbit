<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use Illuminate\Console\Command;
use Throwable;

final class CheckNodeDnsCommand extends Command
{
    use ReadsStringInput;

    #[\Override]
    protected $signature = 'orbit:node-dns-check {name : Managed peer name} {private-domain : Private name to query} {--public-domain=example.com : Public name to query}';

    #[\Override]
    protected $description = 'Verify uncached private and public DNS selection on one managed peer.';

    public function handle(PeerDnsPolicy $policy, PeerDnsInspector $inspector): int
    {
        $name = $this->stringArgument('name');
        $private = strtolower(rtrim($this->stringArgument('private-domain'), '.'));
        $public = strtolower(rtrim(($this->stringOption('public-domain') ?? ''), '.'));
        $node = Node::query()->with('roles')->where('name', $name)->first();
        if (! $node instanceof Node || ! $policy->manages($node)
            || filter_var($private, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || filter_var($public, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            $this->error('DNS check requires an active managed Linux peer and two valid names.');

            return self::FAILURE;
        }
        try {
            $result = $inspector->queries($node, $private, $public);
        } catch (Throwable) {
            $this->error('Could not query the peer resolver.');

            return self::FAILURE;
        }
        foreach (['private', 'public'] as $kind) {
            $this->line($kind.': '.($result[$kind] ?? 'query failed'));
        }
        if (! $result['valid']) {
            $this->error('Expected private DNS on orbit and public DNS on an uplink.');

            return self::FAILURE;
        }
        $this->info('Private and public names resolve on separate links.');

        return self::SUCCESS;
    }
}
