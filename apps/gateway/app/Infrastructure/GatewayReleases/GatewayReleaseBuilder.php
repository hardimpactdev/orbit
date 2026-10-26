<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GatewayReleases\PreparedGatewayRelease;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Projects\ProjectSourceAccess;
use Closure;

/** Orders native release preparation, web installation, Caddy access, and completion. */
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
        $revision = GatewayReleaseCommit::parse($revision);
        $reserved = max(0, ($this->reservedBytes)());
        $inspection = $this->commands->inspectPreparation($this->layout, $revision, $this->minimumFreeBytes, $reserved);
        $read = $inspection['origin'] === null ? null : $this->readAccess->for($inspection['origin'], ProjectSourceAccess::GitHubApp);
        $prepared = $this->commands->prepare($this->layout, $revision, $this->minimumFreeBytes, $reserved, $read, $this->stepLock);
        $sha = $prepared['sha'];
        $id = GatewayReleaseCommit::id($sha);
        $path = $this->layout->releasePath($id);

        try {
            new GatewayReleaseAgentDelivery($this->layout, $this->commands)->install($sha, $this->stepLock);
            if ($withWeb || $prepared['reused']) {
                $this->web->install($id, $sha);
            }
            if (! $prepared['reused']) {
                $this->grantAccess($path);
                $this->commands->finish($this->layout, $sha, $this->stepLock);
            }
        } catch (GatewayReleaseException $exception) {
            throw $exception->sha === null ? $exception->withSha($sha) : $exception;
        }

        return new PreparedGatewayRelease($id, $sha, $path, $prepared['reused'], intdiv(hrtime(true) - $startedAt, 1_000_000));
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
