<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GatewayReleases\PreparedGatewayRelease;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Projects\ProjectSourceAccess;
use App\Infrastructure\Gateway\GatewayCommandNode;
use App\Models\Node;
use Closure;

/**
 * Orders release preparation: the release agent, the native worktree, the web build, Caddy access, and completion.
 * The agent is fetched before any agent command, so a commit without a release refuses before anything changes. It
 * is installed once the release directory exists; for a release the Gateway already holds, such as one an older
 * Gateway prepared, that is before any agent command too, so an agent older than this code cannot block it.
 */
final readonly class GatewayReleaseBuilder
{
    public const int MinimumFreeBytes = 1_073_741_824;

    /** @var Closure(): int */
    private Closure $reservedBytes;

    /**
     * @param  Closure(string): void  $checkoutAccess  Grants Caddy access to the release's Gateway application.
     * @param  (Closure(): int)|null  $reservedBytes  Room reserved for the database snapshot, above the free-space floor.
     */
    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseCommands $commands,
        private RepositoryReadAccess $readAccess,
        private GatewayReleaseWebBuild $web,
        private GatewayReleaseAgent $agent,
        private Closure $checkoutAccess,
        private string $stepLock,
        private int $minimumFreeBytes = self::MinimumFreeBytes,
        ?Closure $reservedBytes = null,
    ) {
        $this->reservedBytes = $reservedBytes ?? static fn (): int => 0;
    }

    /** New adoption releases can skip the web artifact; reused releases always restore their web build. */
    public function prepare(string $revision, bool $withWeb = true): PreparedGatewayRelease
    {
        $startedAt = hrtime(true);
        $gateway = GatewayCommandNode::find();
        if (! $gateway instanceof Node) {
            throw new GatewayReleaseException('agent-delivery', 'gateway.release_gateway_node_missing', 'No active Gateway Node exists for release commands.', 500);
        }
        $delivery = $this->agent->fetch($revision, $gateway);
        $sha = $delivery['sha'];
        $id = GatewayReleaseCommit::id($sha);
        $path = $this->layout->releasePath($id);
        $reused = $this->layout->preparedCommit($id) === $sha;

        try {
            if (! $reused) {
                $reserved = max(0, ($this->reservedBytes)());
                $inspection = $this->commands->inspectPreparation($this->layout, $sha, $this->minimumFreeBytes, $reserved);
                $read = $inspection['origin'] === null ? null : $this->readAccess->for($inspection['origin'], ProjectSourceAccess::GitHubApp);
                $this->commands->prepare($this->layout, $sha, $this->minimumFreeBytes, $reserved, $read, $this->stepLock);
            }
            $this->agent->install($delivery, $this->layout, $gateway);
            if ($withWeb || $reused) {
                $this->web->install($id, $sha);
            }
            if (! $reused) {
                $this->grantAccess($path);
                $this->commands->finish($this->layout, $sha, $this->stepLock);
            }
        } catch (GatewayReleaseException $exception) {
            throw $exception->sha === null ? $exception->withSha($sha) : $exception;
        }

        return new PreparedGatewayRelease($id, $sha, $path, $reused, intdiv(hrtime(true) - $startedAt, 1_000_000));
    }

    public function refreshConfiguration(string $id): void
    {
        $this->commands->refresh($this->layout, $id, $this->stepLock);
    }

    /** Remove the web build only after native removal succeeds. */
    public function remove(string $id): void
    {
        $this->commands->remove($this->layout, $id, $this->stepLock);
        $this->web->remove($id);
    }

    private function grantAccess(string $path): void
    {
        try {
            ($this->checkoutAccess)($path.'/apps/gateway');
        } catch (NodeProvisioningException $exception) {
            throw new GatewayReleaseException(
                step: 'access',
                errorCode: 'gateway.release_access_failed',
                message: $exception->getMessage(),
                previous: $exception,
                result: $exception->result,
            );
        }
    }
}
