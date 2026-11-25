<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConnServerReleases
{
    public function find(?string $version): ?ConnServerRelease
    {
        $checkout = config()->string('orbit.gateway_checkout');
        $file = 'machine-software/conn-server-releases.yaml';
        try {
            $document = Yaml::parseFile(is_link(dirname($checkout, 2)) ? $checkout.'/resources/'.$file : resource_path($file));
        } catch (ParseException $failure) {
            throw new InvalidArgumentException('The serving Conn Server release catalog is unreadable or invalid.', previous: $failure);
        }

        return $this->select($document, $version);
    }

    public function select(mixed $document, ?string $version): ?ConnServerRelease
    {
        if (! is_array($document) || ! is_string($default = $document['default'] ?? null)
            || ! is_array($releases = $document['releases'] ?? null) || ! isset($releases[$default])) {
            throw new InvalidArgumentException('Expected a Conn Server release catalog with a default release.');
        }

        $version ??= $default;
        if (! array_key_exists($version, $releases)) {
            return null;
        }
        if (! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $version) || ! is_array($release = $releases[$version])) {
            throw new InvalidArgumentException('Expected an exact Conn Server release.');
        }
        if (array_key_exists('release_canary', $release) && ! is_bool($release['release_canary'])) {
            throw new InvalidArgumentException('Expected a boolean Conn Server release canary flag.');
        }
        $mac = $release['macos_arm64'] ?? null;
        if (! is_array($mac) || ! is_string($bundle = $mac['bundle_id'] ?? null) || $bundle !== 'com.nckrtl.conn-server'
            || ! is_string($team = $mac['team_id'] ?? null) || ! preg_match('/\A[A-Z0-9]{10}\z/', $team)) {
            throw new InvalidArgumentException('Expected the Conn Server bundle and its Developer ID team.');
        }

        return new ConnServerRelease($version, $this->artifact($version, 'linux-x64.tar.gz', $release['linux_x64'] ?? null),
            new MacAppPin($this->artifact($version, 'macos-arm64.zip', $mac), $bundle, $team), $release['release_canary'] ?? false);
    }

    private function artifact(string $version, string $suffix, mixed $data): ArtifactPin
    {
        $url = "https://github.com/nckrtl/conn/releases/download/conn-server-v{$version}/conn-server-{$version}-{$suffix}";
        if (! is_array($data) || ($data['url'] ?? null) !== $url || ! is_string($digest = $data['sha256'] ?? null)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $digest)) {
            throw new InvalidArgumentException("Expected the Conn Server {$suffix} asset for {$version} and its SHA-256 digest.");
        }

        return new ArtifactPin($url, 'sha256', $digest, $version);
    }
}
