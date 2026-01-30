<?php

declare(strict_types=1);

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
        return (getenv('HOME') ?: '/home/orbit').'/.config/orbit';
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
        // Internal port comes from services.yaml - this is the actual port Reverb listens on
        $internalPort = $this->get('services.reverb.port', 8080);

        return [
            'enabled' => $this->isServiceEnabled('reverb'),
            'app_id' => $this->get('reverb.app_id', 'orbit'),
            'app_key' => $this->get('reverb.app_key', 'orbit-key'),
            'app_secret' => $this->get('reverb.app_secret', 'orbit-secret'),
            'host' => $this->get('reverb.host', 'reverb.'.$this->get('tld', 'test')),
            'port' => $this->get('reverb.port', 443),
            'scheme' => 'https',
            'internal_port' => $internalPort,
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

    // DNS Mappings Management

    public function getDnsMappings(): array
    {
        return $this->get('dns_mappings', [
            ['type' => 'address', 'tld' => 'test', 'value' => '127.0.0.1'],
            ['type' => 'server', 'value' => '8.8.8.8'],
            ['type' => 'server', 'value' => '8.8.4.4'],
        ]);
    }

    public function setDnsMappings(array $mappings): void
    {
        $this->set('dns_mappings', $mappings);
    }

    public function addDnsMapping(string $type, string $value, ?string $tld = null): void
    {
        $mappings = $this->getDnsMappings();

        $mapping = ['type' => $type, 'value' => $value];
        if ($tld !== null) {
            $mapping['tld'] = $tld;
        }

        $mappings[] = $mapping;
        $this->setDnsMappings($mappings);
    }

    public function removeDnsMapping(int $index): void
    {
        $mappings = $this->getDnsMappings();
        if (isset($mappings[$index])) {
            array_splice($mappings, $index, 1);
            $this->setDnsMappings($mappings);
        }
    }

    public function generateDnsmasqConf(): string
    {
        $content = "# Orbit DNS configuration\n";
        $content .= "# This file is auto-generated from config.json\n";
        $content .= "# Do not edit manually - use the Orbit UI or CLI to manage DNS mappings\n\n";

        $mappings = $this->getDnsMappings();

        foreach ($mappings as $mapping) {
            if ($mapping['type'] === 'address') {
                $tld = $mapping['tld'] ?? 'test';
                $value = $mapping['value'];
                $content .= "address=/{$tld}/{$value}\n";
            } elseif ($mapping['type'] === 'server') {
                $tld = $mapping['tld'] ?? null;
                $value = $mapping['value'];
                if ($tld) {
                    $content .= "server=/{$tld}/{$value}\n";
                } else {
                    $content .= "server={$value}\n";
                }
            }
        }

        $content .= "\nlog-queries\n";
        $content .= "log-facility=-\n";

        return $content;
    }

    public function writeDnsmasqConf(): void
    {
        $path = $this->getConfigPath().'/dnsmasq.conf';
        File::put($path, $this->generateDnsmasqConf());
    }
}
