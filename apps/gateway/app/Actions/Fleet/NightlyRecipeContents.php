<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\NightlyRecipePendingDeployment;
use App\Infrastructure\MachineSoftware\AuthenticatedUbuntuSnapshot;
use App\Infrastructure\MachineSoftware\SnapshotAvailability;
use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use UnexpectedValueException;

/** Shared strict reads and authenticated snapshot-and-Ubuntu-pin bumps for nightly recipe PRs. */
final readonly class NightlyRecipeContents
{
    public function __construct(private SnapshotAvailability $snapshots = new AuthenticatedUbuntuSnapshot) {}

    public function requireDeployed(string $content): void
    {
        $serving = file_get_contents(resource_path('machine-software/recipe.json'));
        if ($serving === false) {
            throw new RuntimeException('The serving recipe is missing.');
        }
        $main = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $deployed = json_decode($serving, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($main) || ! is_array($deployed) || ! is_array($main['ubuntu'] ?? null) || ! is_array($deployed['ubuntu'] ?? null)) {
            throw new UnexpectedValueException('The recipe document is invalid.');
        }
        unset($main['ubuntu']['snapshot'], $deployed['ubuntu']['snapshot']);
        if ($main !== $deployed) {
            throw new NightlyRecipePendingDeployment('Nightly recipe maintenance is waiting for the serving release to carry main’s recipe definitions.');
        }
    }

    /** GitHub records App API commits with the App author and its signed web-flow committer. */
    public function appCommit(mixed $commits, string $head, string $author): bool
    {
        return is_array($commits) && array_is_list($commits) && count($commits) === 1
            && data_get($commits, '0.sha') === $head
            && data_get($commits, '0.author.login') === $author && data_get($commits, '0.author.type') === 'Bot'
            && data_get($commits, '0.committer.login') === 'web-flow' && data_get($commits, '0.committer.type') === 'User'
            && data_get($commits, '0.commit.verification.verified') === true
            && data_get($commits, '0.commit.verification.reason') === 'valid';
    }

    /** @phpstan-assert-if-true string $value */
    public function isSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1;
    }

    public function read(PendingRequest $http, string $ref): string
    {
        $encoded = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $ref])->throw()->json('content');
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($content)) {
            throw new UnexpectedValueException('GitHub omitted the recipe contents.');
        }

        return $content;
    }

    /** Snapshot PR evidence must not depend on the serving release's tool catalog. */
    public function snapshot(string $content): string
    {
        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $snapshot = data_get($data, 'ubuntu.snapshot');
        $date = is_string($snapshot) ? DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $snapshot) : false;
        if ($date === false || $date->format('Ymd\THis\Z') !== $snapshot) {
            throw new UnexpectedValueException('The recipe snapshot is invalid.');
        }

        return $snapshot;
    }

    public function replace(string $content, string $old, string $snapshot): string
    {
        $updated = preg_replace('/("snapshot"\s*:\s*")'.preg_quote($old, '/').'(")/', '${1}'.$snapshot.'${2}', $content, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new UnexpectedValueException('The recipe snapshot could not be replaced.');
        }

        $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['ubuntu'] ?? null) || ! is_array($data['tools'] ?? null) || ! is_array($data['architectures'] ?? null)
            || ! array_is_list($data['architectures']) || ! array_all($data['architectures'], static fn (mixed $architecture): bool => is_string($architecture))) {
            throw new UnexpectedValueException('The recipe tools or architectures are invalid.');
        }
        $packages = [];
        foreach ($data['tools'] as $name => $tool) {
            if (! is_string($name) || ! is_array($tool) || ! is_string($tool['source'] ?? null)) {
                throw new UnexpectedValueException('The recipe tool is invalid.');
            }
            if ($tool['source'] === 'ubuntu') {
                $packages[] = $name;
            }
        }
        $versions = $this->snapshots->latest($snapshot, $data['architectures'], $packages);
        $data['ubuntu']['snapshot'] = $snapshot;
        foreach ($versions as $name => $version) {
            if (! in_array($name, $packages, true)) {
                throw new UnexpectedValueException("Snapshot resolution returned a non-Ubuntu tool [{$name}].");
            }
            $data['tools'][$name]['version'] = $version;
            $updated = preg_replace_callback('/("'.preg_quote($name, '/').'"\s*:\s*\{[^{}]*?"version"\s*:\s*")[^"]+(")/',
                static fn (array $match): string => $match[1].$version.$match[2], $updated, 1, $count);
            if (! is_string($updated) || $count !== 1) {
                throw new UnexpectedValueException("Ubuntu pin [{$name}] could not be replaced.");
            }
        }

        if (json_decode($updated, true, flags: JSON_THROW_ON_ERROR) !== $data) {
            throw new UnexpectedValueException('Recipe rewrite changed fields outside the snapshot and Ubuntu versions.');
        }

        return $updated;
    }
}
