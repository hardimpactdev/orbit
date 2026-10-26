<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use Illuminate\Support\Facades\Config;

/**
 * The paths of the Gateway release layout, derived from the stable Gateway checkout path
 * (`orbit.gateway_checkout`, `/home/orbit/orbit/apps/gateway`):
 *
 * - `/home/orbit/orbit` links to the current release, so every path below it stays stable.
 * - `/home/orbit/releases/<id>` holds one immutable release, a linked worktree of one commit.
 * - `/home/orbit/shared` holds what every release shares: the Git repository, the Gateway env
 *   file, and the Gateway storage directory.
 */
final readonly class GatewayReleaseLayout
{
    private const string ApplicationSuffix = '/apps/gateway';

    public function __construct(public string $applicationPath, private ?GatewayReleaseObserver $observer = null)
    {
        if (
            ! str_ends_with($applicationPath, self::ApplicationSuffix)
            || ! str_starts_with($applicationPath, '/')
            || str_contains($applicationPath, "\0")
            || str_contains($applicationPath, "\n")
            || in_array('..', explode('/', $applicationPath), true)
            || dirname($applicationPath, 3) === '/'
        ) {
            throw new GatewayReleaseException(
                step: 'layout',
                errorCode: 'gateway.release_layout_invalid',
                message: "The Gateway checkout [{$applicationPath}] must be an absolute <base>/<checkout>/apps/gateway path.",
            );
        }
    }

    public static function fromConfig(): self
    {
        return new self(rtrim(Config::string('orbit.gateway_checkout'), '/'));
    }

    /** The stable path that links to the current release, `/home/orbit/orbit`. */
    public function currentPath(): string
    {
        return dirname($this->applicationPath, 2);
    }

    public function basePath(): string
    {
        return dirname($this->currentPath());
    }

    public function releasesPath(): string
    {
        return $this->basePath().'/releases';
    }

    public function releasePath(string $id): string
    {
        return $this->releasesPath().'/'.GatewayReleaseCommit::assertId($id);
    }

    public function releaseApplicationPath(string $id): string
    {
        return $this->releasePath($id).self::ApplicationSuffix;
    }

    public function sharedPath(): string
    {
        return $this->basePath().'/shared';
    }

    /** The bare repository every release is a linked worktree of. */
    public function repositoryPath(): string
    {
        return $this->sharedPath().'/orbit.git';
    }

    /** The one Gateway env file that `apps/gateway/.env` links to in every release. */
    public function environmentPath(): string
    {
        return $this->sharedPath().'/gateway.env';
    }

    /** The Gateway storage directory, shared so caches, locks, and logs survive a release switch. */
    public function storagePath(): string
    {
        return $this->sharedPath().'/gateway-storage';
    }

    /** The relative link target that names one release from the current path's directory. */
    public function linkTarget(string $id): string
    {
        return 'releases/'.GatewayReleaseCommit::assertId($id);
    }

    /** Reads live state from the layout itself; path construction needs no observer. */
    public function observe(?string $runningApplication = null): GatewayReleaseInventory
    {
        return ($this->observer ?? app(GatewayReleaseObserver::class))->inspect($this, $runningApplication);
    }

    public function currentReleaseId(): ?string
    {
        return $this->observe()->current;
    }

    public function isAdopted(): bool
    {
        return $this->currentReleaseId() !== null;
    }

    public function preparedCommit(string $id): ?string
    {
        $id = GatewayReleaseCommit::assertId($id);

        return $this->observe()->commits[$id] ?? null;
    }

    /** @return list<string> */
    public function incompleteReleaseIds(): array
    {
        return array_map(strval(...), array_keys($this->observe()->incomplete));
    }

    /** @return list<string> */
    public function retainedReleaseIds(): array
    {
        return $this->observe()->retained;
    }
}
