<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ConfigManager
{
    protected string $configPath;

    protected array $config = [];

    public function __construct()
    {
        $this->configPath = $this->getConfigPath().'/config.json';
        $this->load();
    }

    public function getConfigPath(): string
    {
        return (getenv('HOME') ?: '/home/launchpad').'/.config/launchpad';
    }

    public function getWebAppPath(): string
    {
        return $this->getConfigPath().'/web';
    }

    public function load(): void
    {
        if (File::exists($this->configPath)) {
            $this->config = json_decode(File::get($this->configPath), true) ?? [];
        }
    }

    public function save(): void
    {
        File::put($this->configPath, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return data_get($this->config, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        data_set($this->config, $key, $value);
        $this->save();
    }

    public function getPaths(): array
    {
        return $this->get('paths', []);
    }

    public function getDefaultPhpVersion(): string
    {
        return $this->get('default_php_version', '8.3');
    }

    public function getTld(): string
    {
        return $this->get('tld', 'test');
    }

    public function getHostIp(): string
    {
        return $this->get('host_ip', '127.0.0.1');
    }

    public function getSiteOverrides(): array
    {
        return $this->get('sites', []);
    }

    public function getSitePhpVersion(string $site): ?string
    {
        return $this->get("sites.{$site}.php_version");
    }

    public function setSitePhpVersion(string $site, string $version): void
    {
        $this->set("sites.{$site}.php_version", $version);
    }

    public function removeSiteOverride(string $site): void
    {
        $sites = $this->get('sites', []);
        unset($sites[$site]);
        $this->set('sites', $sites);
    }

    public function getEnabledServices(): array
    {
        $services = $this->get('services', []);

        return array_keys(array_filter($services, fn ($s) => $s['enabled'] ?? false));
    }

    public function isServiceEnabled(string $service): bool
    {
        return $this->get("services.{$service}.enabled", false);
    }

    // Reverb-specific configuration methods

    public function getReverbConfig(): array
    {
        return [
            'enabled' => $this->isServiceEnabled('reverb'),
            'app_id' => $this->get('reverb.app_id', 'launchpad'),
            'app_key' => $this->get('reverb.app_key', 'launchpad-key'),
            'app_secret' => $this->get('reverb.app_secret', 'launchpad-secret'),
            'host' => $this->get('reverb.host', 'reverb.'.$this->get('tld', 'test')),
            'port' => $this->get('reverb.port', 443),
            'scheme' => 'https',
        ];
    }

    public function setReverbConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->set("reverb.{$key}", $value);
        }
    }

    public function enableService(string $service): void
    {
        $this->set("services.{$service}.enabled", true);
    }

    public function disableService(string $service): void
    {
        $this->set("services.{$service}.enabled", false);
    }
}
