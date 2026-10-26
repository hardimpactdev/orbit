<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use Illuminate\Support\Collection;

/**
 * Lets Caddy read a development Instance's Web roots and nothing else of its checkout, through the Node agent's
 * `instance.grant-development-caddy-access` (docs/reference/caddy.md).
 */
final readonly class NativeDevelopmentSourceAccess implements DevelopmentSourceAccess
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function grant(Instance $instance): void
    {
        $instance->loadMissing('node');
        $sites = self::walkedSites($this->sites($instance));

        if ($sites->isEmpty()) {
            return;
        }

        AgentStep::run($this->agents, $instance->node, 'instance.grant-development-caddy-access', [
            'user' => $this->accounts->resolve($instance->node)->user,
            'sites' => $sites->map(static fn (DevelopmentSite $site): array => [
                'checkout' => StoragePath::parse($site->checkoutPath)->value,
                'relative_root' => RelativeWebRoot::validate($site->documentRoot),
                'application' => $site->applicationDirectory(),
            ])->all(),
        ], 'source-access', 'app-dev.source_access_failed', 'App development');
    }

    /**
     * The development sites whose checkout and Web root the walk covers.
     *
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return Collection<int, DevelopmentSite>
     */
    public static function walkedSites(Collection $sites): Collection
    {
        return $sites
            ->reject(static fn (DevelopmentSite $site): bool => $site->isProxy() || $site->unavailable || $site->environment !== 'development' || $site->checkoutPath === '')
            ->values();
    }

    /**
     * The access walk covers the Instance's checkout and any served checkout nested in it, because
     * the recursive deny on the Instance's tree would otherwise revoke a nested worktree's Web root.
     * Every other checkout on the Node keeps the access its own convergence granted, so the walk
     * does not grow with the Node's other Instances.
     *
     * @return Collection<int, DevelopmentSite>
     */
    private function sites(Instance $instance): Collection
    {
        $checkout = rtrim($instance->checkout_path, '/');

        if ($checkout === '') {
            return collect();
        }

        return new DevelopmentSiteRepository()->forNode($instance->node)
            ->filter(static fn (DevelopmentSite $site): bool => $site->checkoutPath === $checkout
                || str_starts_with($site->checkoutPath, $checkout.'/'))
            ->values();
    }
}
