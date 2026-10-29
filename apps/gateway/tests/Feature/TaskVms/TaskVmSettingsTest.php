<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSchedule;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\WireGuard\VpnSettings;
use PHPUnit\Framework\Assert;

/** @param array<string, mixed> $overrides */
function enable_task_vms(array $overrides = []): void
{
    config(['task_vms' => array_replace_recursive(task_vm_config([
        'enabled' => true,
        'dev_cluster_id' => 4,
        'wireguard_range' => '10.44.0.128/25',
        'model_proxy_origin' => 'http://10.44.0.3:8317',
        'pi' => [
            'artifact_path' => '/home/orbit/.orbit/pi/pi-linux-x64',
            'artifact_sha256' => str_repeat('a', 64),
            'models' => ['proxy/coder-large', 'proxy/coder-large'],
        ],
        'incus' => ['hosts' => [['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]]],
    ]), $overrides)]);
    task_vm_node_hosts(config('task_vms.incus.hosts', []));
}

/** @param array<string, mixed> $host */
function task_vm_hosts(array $host): array
{
    return ['incus' => ['hosts' => [[...['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4], ...$host]]]];
}

/** @param array<string, mixed> $values */
function task_vms_off(array $values = []): void
{
    config(['task_vms' => array_replace_recursive(task_vm_config(), $values)]);
    task_vm_node_hosts(config('task_vms.incus.hosts', []));
}

/** The config file's `park_after_minutes` while its environment value is `$value`, or unset for null. */
function task_vm_park_after_minutes_from_env(?string $value): mixed
{
    $key = 'ORBIT_TASK_VMS_PARK_AFTER_MINUTES';
    if ($value !== null) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    try {
        return (require config_path('task_vms.php'))['park_after_minutes'];
    } finally {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function expect_invalid_task_vm_config(string $reason): void
{
    try {
        TaskVmSettings::fromConfig();
        Assert::fail('The invalid config was accepted.');
    } catch (TaskVmException $exception) {
        expect($exception->errorCode)->toBe('task_vm.invalid_config')
            ->and($exception->getMessage())->toContain($reason);
    }
}

describe('defaults', function (): void {
    it('keeps task VMs off with the reserved range and nothing else set', function (): void {
        $config = config_without_env('task_vms.php', [
            'ORBIT_TASK_VMS_ENABLED', 'ORBIT_TASK_VMS_DEV_CLUSTER_ID', 'ORBIT_TASK_VMS_WIREGUARD_RANGE', 'ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN',
            'ORBIT_TASK_VMS_PI_ARTIFACT_PATH', 'ORBIT_TASK_VMS_PI_ARTIFACT_SHA256', 'ORBIT_TASK_VMS_PI_MODELS', 'ORBIT_TASK_VMS_INCUS_HOSTS',
            'ORBIT_TASK_VMS_PARK_AFTER_MINUTES', 'ORBIT_TASK_VMS_SWAMP_HOST',
        ]);
        config(['task_vms' => $config]);

        $settings = TaskVmSettings::fromConfig();

        expect($config['enabled'])->toBeFalse()
            ->and($settings)->toEqual(new TaskVmSettings(false, null, '10.44.0.128/25', [], null, null, null, []))
            ->and($settings->parkAfterMinutes)->toBe(30);
    });

    it('reads park_after_minutes as a whole number of minutes, 30 by default', function (?string $value, int $minutes): void {
        task_vms_off(['park_after_minutes' => task_vm_park_after_minutes_from_env($value)]);

        expect(TaskVmSettings::fromConfig()->parkAfterMinutes)->toBe($minutes);
    })->with([
        'unset' => [null, 30],
        'empty' => ['', 30],
        'from the environment' => ['45', 45],
        'the maximum' => ['10080', 10080],
    ]);

    it('passes 0 from the environment through to validation instead of the default', function (): void {
        task_vms_off(['park_after_minutes' => task_vm_park_after_minutes_from_env('0')]);

        expect_invalid_task_vm_config('park_after_minutes must be a whole number of minutes from 1 to 10080.');
    });

    it('refuses an invalid park_after_minutes', function (mixed $value): void {
        task_vms_off(['park_after_minutes' => $value]);

        expect_invalid_task_vm_config('park_after_minutes must be a whole number of minutes from 1 to 10080.');
    })->with(['zero' => ['0'], 'too long' => [10081], 'a word' => ['soon'], 'a fraction' => ['1.5']]);

    it('stays inert on a fleet whose VPN subnet does not hold the default range', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.43.0.0/24');
        task_vms_off();

        expect(TaskVmSettings::fromConfig()->wireguardRange)->toBe('10.44.0.128/25');
    });

    it('validates the reserved range even while nothing else is set', function (): void {
        task_vms_off(['wireguard_range' => '10.44.0.129/25']);

        expect_invalid_task_vm_config('wireguard_range must be an IPv4 network');
    });
});

describe('off but configured', function (): void {
    it('reads hosts, the Cluster and the origin while task VMs are off', function (): void {
        task_vms_off([
            'dev_cluster_id' => '4',
            'model_proxy_origin' => 'http://10.44.0.3:8317/',
            'incus' => ['hosts' => [['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4]]],
        ]);

        $settings = TaskVmSettings::fromConfig();

        expect($settings->enabled)->toBeFalse()
            ->and($settings->devClusterId)->toBe(4)
            ->and($settings->modelProxyOrigin)->toBe('http://10.44.0.3:8317')
            ->and($settings->host(7)->bridgeAddress())->toBe('10.251.77.1');
    });

    it('gives Caddy the range once the Cluster, the origin, or a host is set, but not for the range or Pi alone', function (array $values, ?string $range): void {
        task_vms_off($values);

        expect(TaskVmSettings::caddyGuardRange())->toBe($range);
    })->with([
        'nothing set' => [[], null],
        'range and Pi only' => [['wireguard_range' => '10.44.0.192/26', 'pi' => ['models' => ['proxy/coder-large']]], null],
        'invalid range alone' => [['wireguard_range' => 'not a range'], null],
        'invalid Pi artifact alone' => [['pi' => ['artifact_path' => '/home/orbit/pi']], null],
        'enabled' => [['enabled' => true], '10.44.0.128/25'],
        'Cluster' => [['dev_cluster_id' => 4], '10.44.0.128/25'],
        'Swamp hostname' => [['swamp_host' => 'swamp.orbit'], '10.44.0.128/25'],
        'origin' => [['model_proxy_origin' => 'http://10.44.0.3:8317'], '10.44.0.128/25'],
        'host' => [task_vm_hosts([]), '10.44.0.128/25'],
        'invalid host setting' => [task_vm_hosts(['cidr' => 'invalid']), '10.44.0.128/25'],
        'invalid values beside the range' => [['dev_cluster_id' => '4x', 'model_proxy_origin' => 'http://10.44.0.3:8317/v1', 'pi' => ['artifact_path' => '/home/orbit/pi']], '10.44.0.128/25'],
        'range outside the VPN subnet' => [['dev_cluster_id' => 4, 'wireguard_range' => '10.45.0.128/25'], '10.45.0.128/25'],
    ]);

    it('refuses an invalid range for Caddy once task VMs are configured', function (): void {
        task_vms_off(['dev_cluster_id' => 4, 'wireguard_range' => '10.44.0.129/25']);

        try {
            TaskVmSettings::caddyGuardRange();
            Assert::fail('The invalid range was accepted.');
        } catch (TaskVmException $exception) {
            expect($exception->errorCode)->toBe('task_vm.invalid_config')
                ->and($exception->getMessage())->toContain('wireguard_range must be an IPv4 network');
        }
    });

    it('treats empty values as unset', function (): void {
        task_vms_off(['dev_cluster_id' => '', 'model_proxy_origin' => '', 'pi' => ['artifact_path' => '', 'artifact_sha256' => '']]);

        expect(TaskVmSettings::fromConfig())->toEqual(new TaskVmSettings(false, null, '10.44.0.128/25', [], null, null, null, []));
    });

    it('refuses an invalid value that is set while task VMs are off', function (array $values, string $reason): void {
        task_vms_off($values);

        expect_invalid_task_vm_config($reason);
    })->with([
        'invalid bridge type' => [task_vm_hosts(['cidr' => []]), 'incus.hosts.0.cidr'],
        'unknown host field' => [task_vm_hosts(['unknown' => true]), 'incus.hosts.0 has unknown keys'],
        'bad host' => [task_vm_hosts(['max_vms' => 0]), 'incus.hosts.0.max_vms'],
        'bad Cluster' => [['dev_cluster_id' => '4x'], 'dev_cluster_id must be a Cluster id'],
        'blank Cluster' => [['dev_cluster_id' => '  '], 'dev_cluster_id must be a Cluster id'],
        'blank origin' => [['model_proxy_origin' => ' '], 'model_proxy_origin must be an http(s) origin'],
        'bad origin' => [['model_proxy_origin' => 'http://10.44.0.3:8317/v1'], 'model_proxy_origin'],
        'malformed models JSON' => [['pi' => ['models' => null]], 'pi.models'],
        'artifact without digest' => [['pi' => ['artifact_path' => '/home/orbit/pi']], 'must be set together'],
        'range outside the VPN subnet' => [['wireguard_range' => '10.45.0.128/25', 'dev_cluster_id' => 4], 'inside the VPN subnet [10.44.0.0/24]'],
        'range as large as the VPN subnet' => [['wireguard_range' => '10.44.0.0/24', 'model_proxy_origin' => 'http://10.44.0.3:8317'], 'inside the VPN subnet'],
        'bridge overlapping the VPN subnet' => [task_vm_hosts(['cidr' => '10.44.0.0/16']), 'must not overlap the VPN subnet [10.44.0.0/24]'],
        'malformed medium projects JSON' => [['medium_projects' => null], 'medium_projects must be a JSON list of Project ids'],
        'medium project not an id' => [['medium_projects' => ['46']], 'medium_projects must be a JSON list of Project ids'],
    ]);

    it('reads the medium Projects once, without duplicates', function (): void {
        task_vms_off(['medium_projects' => [46, 46, 3]]);

        expect(TaskVmSettings::fromConfig()->mediumProjectIds)->toBe([46, 3]);
    });
});

describe('enabled', function (): void {
    it('reads a valid config with host defaults', function (): void {
        enable_task_vms();

        $settings = TaskVmSettings::fromConfig();

        expect($settings->enabled)->toBeTrue()
            ->and($settings->devClusterId)->toBe(4)
            ->and($settings->modelProxyOrigin)->toBe('http://10.44.0.3:8317')
            ->and($settings->piArtifactSha256)->toBe(str_repeat('a', 64))
            ->and($settings->piModels)->toBe(['proxy/coder-large'])
            ->and($settings->hosts)->toEqual([new TaskVmHost(7, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB', 'orbit-tasks', null)])
            ->and($settings->host(7)->bridgeAddress())->toBe('10.251.77.1');
    });

    it('reads the ZFS dataset that creates the pool', function (): void {
        enable_task_vms(task_vm_hosts(['pool' => 'orbit-p2', 'zfs_dataset' => 'fast/orbit-tasks']));

        expect(TaskVmSettings::fromConfig()->host(7))
            ->pool->toBe('orbit-p2')
            ->zfsDataset->toBe('fast/orbit-tasks');
    });

    it('checks the range against the configured VPN subnet', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.43.0.0/24');
        enable_task_vms(['wireguard_range' => '10.43.0.192/26']);

        expect(TaskVmSettings::fromConfig()->wireguardRange)->toBe('10.43.0.192/26');
    });

    it('validates settings on first use and retains them within the container scope', function (): void {
        enable_task_vms();
        $first = app(TaskVmSettings::class);

        expect($first->devClusterId)->toBe(4);
        config(['task_vms.dev_cluster_id' => 0]);

        expect(app(TaskVmSettings::class))->toBe($first);
        expect(app(TaskVmSettings::class)->devClusterId)->toBe(4);

        app()->forgetInstance(TaskVmSettings::class);
        $next = app(TaskVmSettings::class);
        expect(fn (): ?int => $next->devClusterId)->toThrow(TaskVmException::class, 'dev_cluster_id');
    });

    it('needs at least one host', function (): void {
        enable_task_vms();
        task_vm_node_hosts([]);

        expect_invalid_task_vm_config('incus.hosts needs at least one host');
    });

    it('refuses a host that is not configured', function (): void {
        enable_task_vms();

        expect(fn (): TaskVmHost => TaskVmSettings::fromConfig()->host(8))
            ->toThrow(TaskVmException::class, 'Node [8] is not a task VM host.');
    });

    it('refuses invalid config with task_vm.invalid_config', function (array $overrides, string $reason): void {
        enable_task_vms($overrides);

        expect_invalid_task_vm_config($reason);
    })->with([
        'missing Cluster' => [['dev_cluster_id' => null], 'dev_cluster_id is required'],
        'zero Cluster' => [['dev_cluster_id' => 0], 'dev_cluster_id must be a Cluster id'],
        'public reserved range' => [['wireguard_range' => '8.8.0.0/20'], 'wireguard_range must be a private'],
        'unknown host key' => [task_vm_hosts(['bridge' => 'orbittask0']), 'incus.hosts.0 has unknown keys'],
        'invalid image alias' => [task_vm_hosts(['image_alias' => '-unsafe']), 'incus.hosts.0.image_alias'],
        'missing capacity' => [task_vm_hosts(['max_vms' => 0]), 'incus.hosts.0.max_vms'],
        'bridge address instead of network' => [task_vm_hosts(['cidr' => '10.251.77.1/24']), 'incus.hosts.0.cidr must be an IPv4 network'],
        'public bridge' => [task_vm_hosts(['cidr' => '203.0.113.0/24']), 'incus.hosts.0.cidr must be a private'],
        'bridge in reserved range' => [task_vm_hosts(['cidr' => '10.44.0.128/26']), 'must not overlap the VPN subnet'],
        'bridge outside the reserved prefix' => [task_vm_hosts(['network' => 'incusbr0']), 'incus.hosts.0.network'],
        'bad memory' => [task_vm_hosts(['memory' => '4G']), 'incus.hosts.0.memory'],
        'image key, since every VM launches the base image' => [task_vm_hosts(['image' => 'ubuntu-26.04-vm']), 'incus.hosts.0 has unknown keys'],
        'whole ZFS pool as dataset' => [task_vm_hosts(['zfs_dataset' => 'fast']), 'incus.hosts.0.zfs_dataset'],
        'ZFS dataset with a space' => [task_vm_hosts(['zfs_dataset' => 'fast/orbit tasks']), 'incus.hosts.0.zfs_dataset'],
        'ZFS dataset as an option' => [task_vm_hosts(['zfs_dataset' => '-o/x']), 'incus.hosts.0.zfs_dataset'],
        'overlapping hosts' => [['incus' => ['hosts' => [
            ['node_id' => 7, 'cidr' => '10.251.77.0/24', 'max_vms' => 4],
            ['node_id' => 8, 'cidr' => '10.251.77.0/25', 'max_vms' => 4],
        ]]], 'task_vms host bridges must not overlap'],
        'origin with a path' => [['model_proxy_origin' => 'http://10.44.0.3:8317/v1'], 'model_proxy_origin'],
        'origin with credentials' => [['model_proxy_origin' => 'http://key@10.44.0.3:8317'], 'model_proxy_origin'],
        'missing origin' => [['model_proxy_origin' => null], 'model_proxy_origin is required'],
        'artifact without digest' => [['pi' => ['artifact_sha256' => null]], 'must be set together'],
        'relative artifact path' => [['pi' => ['artifact_path' => 'pi-linux-x64']], 'pi.artifact_path'],
        'uppercase digest' => [['pi' => ['artifact_sha256' => str_repeat('A', 64)]], 'pi.artifact_sha256'],
        'model with spaces' => [['pi' => ['models' => ['coder large']]], 'pi.models'],
        'models not a list' => [['pi' => ['models' => null]], 'pi.models'],
    ]);
});

describe('hosts', function (): void {
    it('defaults operator_owned to none and matches a name or a whole project', function (): void {
        enable_task_vms();
        expect(TaskVmSettings::fromConfig()->hosts[0]->operatorOwned)->toBe([]);

        enable_task_vms(task_vm_hosts(['operator_owned' => ['orbit-ci/*', 'default/ow-exp-20261006', 'orbit-ci/*']]));
        $host = TaskVmSettings::fromConfig()->hosts[0];

        expect($host->operatorOwned)->toBe(['orbit-ci/*', 'default/ow-exp-20261006'])
            ->and($host->operatorOwns('orbit-ci', 'ci-beast-1'))->toBeTrue()
            ->and($host->operatorOwns('default', 'ow-exp-20261006'))->toBeTrue()
            ->and($host->operatorOwns('default', 'renewed-ghost'))->toBeFalse()
            ->and($host->operatorOwns('orbit-tasks', 'tvm-1'))->toBeFalse();
    });

    it('refuses an operator_owned entry that names no project and resource', function (mixed $value): void {
        enable_task_vms(task_vm_hosts(['operator_owned' => $value]));

        expect_invalid_task_vm_config('incus.hosts.0.operator_owned must be a JSON list of <project>/<name> or <project>/* entries.');
    })->with([
        'not a list' => [['orbit-ci' => '*']],
        'no project' => [['ci-beast-1']],
        'empty name' => [['orbit-ci/']],
        'a pattern' => [['orbit-ci/ci-*']],
        'a number' => [[7]],
    ]);

    it('treats only non-bridge usable addresses as guest addresses', function (string $address, bool $guest): void {
        $host = new TaskVmHost(7, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB');

        expect($host->isGuestAddress($address))->toBe($guest);
    })->with([
        'guest' => ['10.251.77.32', true],
        'bridge' => ['10.251.77.1', false],
        'network' => ['10.251.77.0', false],
        'broadcast' => ['10.251.77.255', false],
        'outside' => ['10.251.78.32', false],
        'not an address' => ['10.251.77.032', false],
    ]);
});

describe('scheduler reader', function (): void {
    it('reads the switches leniently, also from an invalid config', function (): void {
        task_vms_off([
            'enabled' => true, 'dev_cluster_id' => 'not an id',
            'incus' => ['hosts' => [['node_id' => 7]]],
            'upcloud' => ['token_file' => '/home/orbit/upcloud.yaml'],
        ]);

        expect(TaskVmSettings::scheduled())->toEqual(new TaskVmSchedule(enabled: true, upcloudConfigured: true));
    });

});

describe('the Swamp hostname', function (): void {
    it('reads one hostname and uses the same normalization for Caddy', function (?string $value, ?string $expected): void {
        task_vms_off(['swamp_host' => $value]);

        expect(TaskVmSettings::fromConfig()->swampHost)->toBe($expected)
            ->and(TaskVmSettings::caddySwampHost())->toBe($expected);
    })->with([
        'unset' => [null, null], 'empty' => ['', null],
        'configured' => ['swamp.orbit', 'swamp.orbit'],
        'case insensitive DNS' => ['Swamp.Orbit', 'swamp.orbit'],
    ]);

    it('refuses an invalid hostname in full settings and Caddy even while task VMs are off', function (mixed $value): void {
        task_vms_off(['swamp_host' => $value]);

        expect_invalid_task_vm_config('swamp_host must be one DNS hostname');
        expect(fn () => TaskVmSettings::caddySwampHost())->toThrow(TaskVmException::class, 'swamp_host must be one DNS hostname');
    })->with([
        'URL' => ['https://swamp.orbit'], 'port' => ['swamp.orbit:443'],
        'path' => ['swamp.orbit/workers'], 'wildcard' => ['*.orbit'],
        'space separated list' => ['swamp.orbit executor.orbit'],
        'comma separated list' => ['swamp.orbit,executor.orbit'],
        'array' => [['swamp.orbit']], 'empty array' => [[]], 'boolean' => [true], 'integer' => [443],
        'IPv4' => ['10.44.0.3'], 'IPv6' => ['::1'], 'whitespace' => [' '], 'padding' => [' swamp.orbit'],
        'single label' => ['swamp'], 'trailing dot' => ['swamp.orbit.'], 'empty label' => ['swamp..orbit'],
        'underscore' => ['swamp_host.orbit'], 'long label' => [str_repeat('a', 64).'.orbit'],
        'long hostname' => [str_repeat(str_repeat('a', 63).'.', 4).'orbit'],
        'Caddy directive' => ["swamp.orbit\nabort"],
    ]);

    it('checks the VPN subnet when only the Swamp hostname is set', function (): void {
        task_vms_off(['swamp_host' => 'swamp.orbit', 'wireguard_range' => '10.45.0.128/25']);

        expect_invalid_task_vm_config('inside the VPN subnet');
    });
});
