<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use Illuminate\Support\Facades\Process;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Orbit\Sdk\Responses\Gateway\FleetReleaseAssetResponse;

/**
 * The two steps of `orbit self-update` (ADR 0202): on a managed Linux Node replace `orbit-agent` with the
 * Gateway's pin, and replace this CLI with the Gateway's CLI release. Each step reports a verified outcome and
 * never throws.
 *
 * The Gateway names the release; it never names where to download it. The CLI builds every URL from its own
 * release location, reads the checksum from the release's own `SHA256SUMS`, and requires it to equal the
 * Gateway's checksum.
 */
final readonly class SelfUpdater
{
    /** The installer's bound: the update lock wait, the copy, and the observer's health window. */
    public const int AgentInstallSeconds = 180;

    private const string ReleaseVersion = '/\A0\.([1-9][0-9]{0,9})\.0\z/D';

    private const string VersionedBinary = '/\Aorbit-0\.[1-9][0-9]{0,9}\.0\z/D';

    /**
     * A pre-release build of Orbit, which printed its full 40-character commit instead of `0.N.0`. Every
     * release is newer, so it updates without `--allow-downgrade`, and its file is kept as {@see self::LegacyBackup}.
     * Any other version that is not a release still needs the downgrade consent.
     */
    private const string LegacyVersion = '/\A[0-9a-f]{40}\z/D';

    public const string LegacyBackup = 'orbit.orbit-previous';

    public function __construct(
        private SelfUpdateHost $host,
        private BinaryInstaller $installer,
        private ReleaseDownloader $downloader,
        private ReleaseLocation $location,
    ) {}

    public function updateCli(DesiredFleetStateResponse $state, string $currentVersion, bool $allowDowngrade): SelfUpdateStep
    {
        $release = $state->cli;
        $binary = $this->host->runningBinary();

        if ($binary->kind === RunningBinaryKind::Source || $binary->path === null) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, reason: 'source_checkout');
        }

        if ($binary->kind === RunningBinaryKind::Phar) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, $binary->path, reason: 'not_a_release_binary');
        }

        $running = $binary->path;
        $link = $this->linkPath($running);

        if ($release->isPending()) {
            return new SelfUpdateStep(
                'cli',
                SelfUpdateOutcome::Pending,
                $link,
                new InstalledVersion($currentVersion, $this->sha256($running)),
                new InstalledVersion($release->version, null),
                reason: $release->reason ?? 'release_missing',
            );
        }

        if (! $release->isAvailable() || $release->version === null) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, $link, reason: $release->reason ?? 'release_unavailable');
        }

        $platform = $this->host->platform();
        $asset = $platform === null ? null : $release->asset($platform);

        if ($platform === null || $asset === null) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Skipped, $link, reason: 'platform_unsupported');
        }

        $version = $release->version;
        $installed = $this->sha256($link) ?? $this->sha256($running);
        $before = new InstalledVersion($currentVersion, $installed);
        $after = new InstalledVersion($version, $asset->sha256);

        try {
            $url = $this->expectedAsset($asset, $version, $this->location->cliAssetName($version, $platform), $this->location->cliAssetUrl($version, $platform), 'self_update');

            if ($installed === $asset->sha256) {
                return new SelfUpdateStep('cli', SelfUpdateOutcome::Unchanged, $link, $before, $after);
            }

            $this->guardVersion($currentVersion, $version, $allowDowngrade);

            $this->confirmChecksum($this->location->cliChecksumsUrl($version), $asset, 'self_update');
            $target = dirname($link).'/orbit-'.$version;

            if ($this->sha256($target) !== $asset->sha256) {
                $this->installer->install(
                    target: $target,
                    url: $url,
                    sha256: $asset->sha256,
                    errorPrefix: 'self_update',
                    mode: $this->mode($running),
                    owner: $this->host->isRoot() ? $this->owner($running) : null,
                    verify: fn (string $candidate) => $this->verifyCandidate($candidate, $version),
                );
            }

            $previous = basename($running);

            // The first self-update turns a plain binary into the link; it keeps that binary for a rollback. A
            // pre-release build has no release version to name it by, so it is kept under a fixed name.
            if ($link === $running && preg_match(self::ReleaseVersion, $currentVersion) === 1 && 'orbit-'.$currentVersion !== basename($target)) {
                $previous = 'orbit-'.$currentVersion;
                $this->installer->keep($running, dirname($link).'/'.$previous);
            } elseif ($link === $running && preg_match(self::LegacyVersion, $currentVersion) === 1) {
                $previous = self::LegacyBackup;
                $this->installer->keep($running, dirname($link).'/'.$previous);
            }

            $this->installer->link($link, basename($target), 'self_update');
            $this->prune(dirname($link), [basename($target), $previous]);
        } catch (SelfUpdateFailure $failure) {
            return new SelfUpdateStep('cli', SelfUpdateOutcome::Failed, $link, $before, $before, failure: $failure);
        }

        return new SelfUpdateStep('cli', SelfUpdateOutcome::Updated, $link, $before, $after);
    }

    public function updateAgent(DesiredFleetStateResponse $state): SelfUpdateStep
    {
        $path = $this->host->agentBinaryPath();

        if (! $this->host->isManagedNode()) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Skipped, reason: 'not_managed_node');
        }

        $platform = $this->host->platform();
        $asset = $platform === null ? null : $state->agent->asset($platform);

        if ($platform === null || $asset === null) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Skipped, $path, reason: 'platform_unsupported');
        }

        $version = $state->agent->version;
        $installed = $this->sha256($path);
        $before = new InstalledVersion($installed === $asset->sha256 ? $version : null, $installed);
        $after = new InstalledVersion($version, $asset->sha256);

        if ($installed === $asset->sha256) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Unchanged, $path, $before, $after);
        }

        if (! $this->host->isRoot()) {
            return new SelfUpdateStep('agent', SelfUpdateOutcome::Skipped, $path, $before, $before, reason: 'root_required');
        }

        $commit = $state->agent->commit;
        // The verified candidate waits beside the installed binary, in a directory only root can write.
        $candidate = dirname($path).'/orbit-agent-candidate-'.bin2hex(random_bytes(8));

        try {
            $url = $this->expectedAsset($asset, $commit, $this->location->agentAssetName($platform), $this->location->agentAssetUrl($commit, $platform), 'agent');
            $this->confirmChecksum($this->location->agentChecksumsUrl($commit), $asset, 'agent');
            $this->installer->install(target: $candidate, url: $url, sha256: $asset->sha256, errorPrefix: 'agent', mode: 0700, owner: $this->host->agentOwner());
            $replaced = $this->installAgent($candidate, $asset->sha256);
        } catch (SelfUpdateFailure $failure) {
            @unlink($candidate);

            return new SelfUpdateStep('agent', SelfUpdateOutcome::Failed, $path, $before, $before, failure: $failure);
        }

        return new SelfUpdateStep('agent', $replaced ? SelfUpdateOutcome::Updated : SelfUpdateOutcome::Unchanged, $path, $before, $after);
    }

    /**
     * Runs the agent's own installer from the verified candidate (ADR 0225): it replaces the binary under the
     * update lock, restarts the observer, and restores the kept binary when the observer does not stay up.
     *
     * @throws SelfUpdateFailure
     */
    private function installAgent(string $candidate, string $sha256): bool
    {
        $envelope = json_encode([
            'agent_sha256' => $sha256,
            'deadline_ms' => self::AgentInstallSeconds * 1000,
            'input' => ['candidate' => $candidate, 'sha256' => $sha256],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $result = Process::timeout(self::AgentInstallSeconds + 30)->input($envelope)->run([$candidate, 'command', 'agent.install']);
        $lines = array_values(array_filter(explode("\n", $result->output()), static fn (string $line): bool => trim($line) !== ''));
        $answer = json_decode((string) end($lines), true);

        if (is_array($answer) && ($answer['ok'] ?? null) === true && is_array($answer['data'] ?? null)) {
            return ($answer['data']['replaced'] ?? null) === true;
        }

        $error = is_array($answer) && is_array($answer['error'] ?? null) ? $answer['error'] : null;
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'agent.install_failed';
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'The orbit-agent installer returned no result.';

        throw new SelfUpdateFailure($code, $message);
    }

    /**
     * The Gateway's asset must be the Orbit release the CLI would name itself, with a well-formed version.
     *
     * @throws SelfUpdateFailure
     */
    private function expectedAsset(FleetReleaseAssetResponse $asset, string $version, string $name, string $url, string $errorPrefix): string
    {
        if ($asset->name !== $name || $asset->url !== $url) {
            throw new SelfUpdateFailure($errorPrefix.'.release_mismatch', "The Gateway names {$asset->url}, not the Orbit release asset {$url}.");
        }

        return $url;
    }

    /**
     * Release versions order by their commit count. A build that is not a release has no place in that order,
     * so replacing it needs the same consent as a downgrade.
     *
     * @throws SelfUpdateFailure
     */
    private function guardVersion(string $current, string $target, bool $allowDowngrade): void
    {
        if (preg_match(self::ReleaseVersion, $target, $targetMatch) !== 1) {
            throw new SelfUpdateFailure('self_update.release_mismatch', "The Gateway names CLI version {$target}, which is not a release.");
        }

        if ($allowDowngrade || preg_match(self::LegacyVersion, $current) === 1) {
            return;
        }

        if (preg_match(self::ReleaseVersion, $current, $currentMatch) !== 1) {
            throw new SelfUpdateFailure('self_update.version_unknown', "This orbit reports version {$current}, which is not a release. Pass --allow-downgrade to replace it with {$target}.");
        }

        if ((int) $targetMatch[1] < (int) $currentMatch[1]) {
            throw new SelfUpdateFailure('self_update.downgrade_refused', "The Gateway's CLI release {$target} is older than this orbit, {$current}. Pass --allow-downgrade to install it.");
        }
    }

    /**
     * Reads the binary's checksum from the release's own `SHA256SUMS`, which this CLI downloads itself, and
     * requires it to equal the Gateway's checksum.
     *
     * @throws SelfUpdateFailure
     */
    private function confirmChecksum(string $url, FleetReleaseAssetResponse $asset, string $errorPrefix): void
    {
        $directory = sys_get_temp_dir().'/orbit-self-update-'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0700) || is_link($directory)) {
            throw new SelfUpdateFailure($errorPrefix.'.download_failed', 'Could not prepare a private directory for SHA256SUMS.');
        }

        $path = $directory.'/SHA256SUMS';

        try {
            $this->downloader->download($url, $path, ReleaseDownloader::ChecksumsMaxBytes, $errorPrefix);
            $contents = (string) @file_get_contents($path, length: ReleaseDownloader::ChecksumsMaxBytes);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/\A([0-9a-f]{64})  (\S+)\z/D', rtrim($line, "\r"), $matches) === 1 && $matches[2] === $asset->name) {
                if (hash_equals($matches[1], $asset->sha256)) {
                    return;
                }

                break;
            }
        }

        throw new SelfUpdateFailure($errorPrefix.'.checksum_mismatch', "The release SHA256SUMS does not confirm the Gateway's checksum for {$asset->name}.");
    }

    /** @throws SelfUpdateFailure */
    private function verifyCandidate(string $candidate, string $version): void
    {
        $home = sys_get_temp_dir().'/orbit-self-update-home-'.bin2hex(random_bytes(8));
        @mkdir($home, 0700);

        try {
            $result = Process::timeout(60)->env(['ORBIT_HOME' => $home])->run([$candidate, '--version']);
        } finally {
            @rmdir($home);
        }

        if (! $result->successful() || trim($result->output()) !== 'Orbit '.$version) {
            throw new SelfUpdateFailure('self_update.candidate_invalid', "The downloaded orbit does not run here or does not report version {$version}.");
        }
    }

    /**
     * The path people run: the `orbit` link next to a versioned binary, or the binary itself before its first
     * self-update turns it into that link.
     */
    private function linkPath(string $running): string
    {
        return preg_match(self::VersionedBinary, basename($running)) === 1 ? dirname($running).'/orbit' : $running;
    }

    /**
     * Keeps the installed release and the one it replaced, and removes older versioned binaries.
     *
     * @param  list<string>  $keep
     */
    private function prune(string $directory, array $keep): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.'/'.$name;

            if (preg_match(self::VersionedBinary, $name) === 1 && ! in_array($name, $keep, true) && is_file($path) && ! is_link($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * The SHA-256 of a file on disk. A standalone binary reads its own path as the PHAR appended to it, not as
     * the whole file, so the digest reads through an equivalent path that the binary does not intercept.
     */
    private function sha256(string $path): ?string
    {
        $path = dirname($path).'/./'.basename($path);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $digest = hash_file('sha256', $path);

        return is_string($digest) ? $digest : null;
    }

    private function mode(string $path): int
    {
        $permissions = @fileperms($path);

        return is_int($permissions) ? ($permissions & 0777) | 0111 : 0755;
    }

    /** @return array{int, int}|null */
    private function owner(string $path): ?array
    {
        $user = @fileowner($path);
        $group = @filegroup($path);

        return is_int($user) && is_int($group) ? [$user, $group] : null;
    }
}
