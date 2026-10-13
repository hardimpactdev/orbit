<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

/**
 * The state of one custom volume of a task VM host, as its `user.orbit.*` config records it. A workspace template is
 * the volume `tvm-template-<project id>-<time>`; its name gives the Project. A claim clone `tvm-<id>-workspace`
 * carries the config of the template it was copied from, so it reads the same way, but it is no template.
 *
 * {@see IncusTaskVmWorkspaceTemplateBuilder} writes the config last, with `user.orbit.ready=true`
 * ({@see self::metadata()}). A template without it is still building, or its build was killed.
 */
final readonly class TaskVmWorkspaceTemplate
{
    public const string VolumePrefix = 'tvm-template-';

    /**
     * The layout of a template: `workspace` while the checkout lives at {@see TaskVmWorkspaceTemplates::CheckoutDirectory}.
     * A template without it, from before the fixed checkout path, serves no claim, so a claim queues a new build.
     */
    public const string Layout = 'workspace';

    public const string ReadyKey = 'user.orbit.ready';

    private const string ProjectKey = 'user.orbit.project';

    private const string CommitKey = 'user.orbit.commit';

    /** The repository URL the template was cloned from. */
    private const string RepositoryKey = 'user.orbit.repository';

    /** The base image the builder VM booted. */
    private const string ImageKey = 'user.orbit.image';

    private const string LayoutKey = 'user.orbit.layout';

    /** The managed user's `uid:gid` on the builder, which owns the files. */
    private const string OwnerKey = 'user.orbit.owner';

    /** How many updates after a merge the template had since its last full build. */
    public const string UpdatesKey = 'user.orbit.updates';

    /**
     * @param  int|null  $projectId  the Project of a template, from its name; null for another volume
     */
    public function __construct(
        public string $name,
        public ?int $projectId,
        public bool $ready = false,
        public ?string $repository = null,
        public ?string $image = null,
        public ?string $owner = null,
        public ?string $commit = null,
        public ?string $layout = null,
        public int $updates = 0,
    ) {}

    /** The volume name of a template of `$projectId` built at `$stamp` (`YmdHis`). */
    public static function name(int $projectId, string $stamp): string
    {
        return self::VolumePrefix.$projectId.'-'.$stamp;
    }

    /** @param  array<string, string>  $config  the volume's string config */
    public static function fromVolume(string $name, array $config): self
    {
        return new self(
            $name,
            preg_match('/\A'.preg_quote(self::VolumePrefix, '/').'([1-9][0-9]*)-[0-9]{14}\z/D', $name, $match) === 1 ? (int) $match[1] : null,
            ($config[self::ReadyKey] ?? null) === 'true',
            $config[self::RepositoryKey] ?? null,
            $config[self::ImageKey] ?? null,
            $config[self::OwnerKey] ?? null,
            $config[self::CommitKey] ?? null,
            $config[self::LayoutKey] ?? null,
            ctype_digit($config[self::UpdatesKey] ?? '') ? (int) $config[self::UpdatesKey] : 0,
        );
    }

    /** A finished template, as a build publishes it: `$updates` is 0 after a full build, and one more than its source after an update. */
    public static function built(string $name, int $projectId, string $commit, string $repository, string $image, string $owner, int $updates = 0): self
    {
        return new self($name, $projectId, true, $repository, $image, $owner, $commit, self::Layout, $updates);
    }

    /** Whether a build has not finished this template: it is still running, or it was killed. */
    public function isBuilding(): bool
    {
        return $this->projectId !== null && ! $this->ready;
    }

    public function isReady(): bool
    {
        return $this->projectId !== null && $this->ready;
    }

    /**
     * Whether a claim of `$projectId` can take this template: it is ready, was cloned from `$repository`, has the
     * current layout, was built on `$image` when one is given, and its files belong to the base image's user
     * ({@see TaskVmCloudInit::Owner}), which every task VM from that image has.
     */
    public function serves(int $projectId, string $repository, ?string $image = null): bool
    {
        return $this->isReady()
            && $this->projectId === $projectId
            && $this->repository === $repository
            && $this->layout === self::Layout
            && ($image === null || $this->image === $image)
            && $this->owner === TaskVmCloudInit::Owner;
    }

    /**
     * The newest template among `$volumes` that serves a claim of `$projectId` ({@see self::serves()}).
     *
     * @param  iterable<self>  $volumes
     */
    public static function newest(iterable $volumes, int $projectId, string $repository, ?string $image = null): ?self
    {
        $newest = null;
        foreach ($volumes as $volume) {
            if ($volume->serves($projectId, $repository, $image) && ($newest === null || strcmp($volume->name, $newest->name) > 0)) {
                $newest = $volume;
            }
        }

        return $newest;
    }

    /**
     * The config that publishes this template, as `key=value` pairs for `incus storage volume set`. `ready` comes
     * last, so a claim never takes a template whose other keys are missing.
     *
     * @return list<string>
     */
    public function metadata(): array
    {
        return [
            self::ProjectKey.'='.$this->projectId,
            self::CommitKey.'='.$this->commit,
            self::RepositoryKey.'='.$this->repository,
            self::ImageKey.'='.$this->image,
            self::OwnerKey.'='.$this->owner,
            self::LayoutKey.'='.$this->layout,
            self::UpdatesKey.'='.$this->updates,
            self::ReadyKey.'='.($this->ready ? 'true' : 'false'),
        ];
    }
}
