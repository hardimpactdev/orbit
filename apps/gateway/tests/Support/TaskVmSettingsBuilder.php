<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;

/**
 * Builds `TaskVmSettings` and `TaskVmHost` for tests from named defaults, so a test names only the values it cares
 * about, and a new settings field touches only this class.
 */
final class TaskVmSettingsBuilder
{
    public const string Origin = 'http://10.44.0.3:8317';

    /** @var array<string, mixed> named `TaskVmSettings` arguments */
    private const array Defaults = [
        'enabled' => true,
        'devClusterId' => 4,
        'wireguardRange' => '10.44.0.128/25',
        'hosts' => [],
        'modelProxyOrigin' => self::Origin,
        'piArtifactPath' => null,
        'piArtifactSha256' => null,
        'piModels' => [],
    ];

    /** @var array<string, mixed> named `TaskVmHost` arguments after `nodeId` */
    private const array HostDefaults = [
        'project' => 'orbit-tasks',
        'network' => 'orbittask0',
        'cidr' => '10.251.77.0/24',
        'maxVms' => 2,
        'cpus' => 2,
        'memory' => '4GiB',
        'disk' => '20GiB',
    ];

    /** @param  array<string, mixed>  $overrides  named `TaskVmSettings` arguments */
    public static function make(array $overrides = []): TaskVmSettings
    {
        return new TaskVmSettings(...[...self::Defaults, ...$overrides]);
    }

    /**
     * Builds the settings and binds them in the container.
     *
     * @param  array<string, mixed>  $overrides  named `TaskVmSettings` arguments
     */
    public static function bind(array $overrides = []): TaskVmSettings
    {
        app()->scoped(TaskVmSettings::class, static fn (): TaskVmSettings => self::make($overrides));

        return app(TaskVmSettings::class);
    }

    /** Settings with every value unset, as a Gateway without task VMs reads them. */
    public static function off(array $overrides = []): TaskVmSettings
    {
        return self::make(['enabled' => false, 'devClusterId' => null, 'modelProxyOrigin' => null, ...$overrides]);
    }

    /** @param  array<string, mixed>  $overrides  named `TaskVmHost` arguments */
    public static function host(int $nodeId, array $overrides = []): TaskVmHost
    {
        return new TaskVmHost(...['nodeId' => $nodeId, ...self::HostDefaults, ...$overrides]);
    }
}
