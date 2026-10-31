<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Rules\SupportedPhpVersion;
use Closure;

/** Pure stored-state render. An empty map still withdraws stale pools on the Node. */
final readonly class SharedPhpPoolInput
{
    public function __construct(private DevelopmentSiteRepository $sites, private DevelopmentPhpFpmConfigRenderer $renderer, private ManagedUserAccountResolver $accounts) {}

    /** @return array{configurations: object} */
    public function forNode(Node $node): array
    {
        return $this->render($node, fn (): ManagedUserAccount => $this->accounts->resolve($node));
    }

    /** Pure render with an already admitted account; no Node lookup.
     * @return array{configurations: object}
     */
    public function forAccount(Node $node, ManagedUserAccount $account): array
    {
        return $this->render($node, static fn (): ManagedUserAccount => $account);
    }

    /** @param Closure(): ManagedUserAccount $account
     * @return array{configurations: object}
     */
    private function render(Node $node, Closure $account): array
    {
        $sites = $this->sites->forNode($node)->filter(static fn (DevelopmentSite $site): bool => $site->phpVersion !== null && ! $site->isProxy() && ! $site->usesDedicatedPhpRuntime());
        $configurations = [];
        foreach ($sites->groupBy('phpVersion') as $version => $members) {
            if (! SupportedPhpVersion::isSupported((string) $version)) {
                throw new ResourceOperationException('app-dev.php_version_unsupported', 'A stored pool uses an unsupported PHP version.', 409);
            }
            $configurations[(string) $version] = $this->renderer->render($members->values(), $account());
        }

        return ['configurations' => (object) $configurations];
    }
}
