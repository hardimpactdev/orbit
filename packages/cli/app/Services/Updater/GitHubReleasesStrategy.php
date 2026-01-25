<?php

declare(strict_types=1);

namespace App\Services\Updater;

use Humbug\SelfUpdate\Exception\HttpRequestException;
use Humbug\SelfUpdate\Strategy\StrategyInterface;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface as LaravelZeroStrategyInterface;

/**
 * Strategy that fetches releases directly from GitHub API.
 *
 * Unlike the default Packagist-based strategy, this queries GitHub releases
 * directly and downloads the PHAR from release assets.
 */
final class GitHubReleasesStrategy implements LaravelZeroStrategyInterface, StrategyInterface
{
    private const GITHUB_API_URL = 'https://api.github.com/repos/%s/releases/latest';

    private string $localVersion = '';

    private string $remoteVersion = '';

    private string $remoteUrl = '';

    private string $packageName = '';

    private string $pharName = 'orbit.phar';

    public function download(Updater $updater): void
    {
        set_error_handler($updater->throwHttpRequestException(...));
        $result = file_get_contents($this->remoteUrl, false, $this->createStreamContext());
        restore_error_handler();

        if ($result === false) {
            throw new HttpRequestException(sprintf('Request to URL failed: %s', $this->remoteUrl));
        }

        file_put_contents($updater->getTempPharFile(), $result);
    }

    public function getCurrentRemoteVersion(Updater $updater): string
    {
        $release = $this->fetchLatestRelease();

        if ($release === null) {
            return '';
        }

        $this->remoteVersion = ltrim($release['tag_name'] ?? '', 'v');
        $this->remoteUrl = $this->findPharDownloadUrl($release);

        return $this->remoteVersion;
    }

    public function getCurrentLocalVersion(Updater $updater): string
    {
        return $this->localVersion;
    }

    public function setCurrentLocalVersion($version): void
    {
        $this->localVersion = ltrim((string) $version, 'v');
    }

    public function setPackageName($name): void
    {
        // Convert composer package name to GitHub repo format
        // e.g., "hardimpactdev/orbit-cli" stays as-is for GitHub
        $this->packageName = (string) $name;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function setPharName(string $name): void
    {
        $this->pharName = $name;
    }

    public function getPharName(): string
    {
        return $this->pharName;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLatestRelease(): ?array
    {
        $url = sprintf(self::GITHUB_API_URL, $this->packageName);

        $response = @file_get_contents($url, false, $this->createStreamContext());

        if ($response === false) {
            return null;
        }

        /** @var array<string, mixed>|null */
        $data = json_decode($response, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $release
     */
    private function findPharDownloadUrl(array $release): string
    {
        /** @var array<int, array<string, mixed>> $assets */
        $assets = $release['assets'] ?? [];

        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if ($name === $this->pharName) {
                return $asset['browser_download_url'] ?? '';
            }
        }

        return '';
    }

    /**
     * @return resource
     */
    private function createStreamContext()
    {
        return stream_context_create([
            'http' => [
                'header' => "User-Agent: orbit-cli\r\n",
                'timeout' => 30,
                'follow_location' => true,
            ],
        ]);
    }
}
