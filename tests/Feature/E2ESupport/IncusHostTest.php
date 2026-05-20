<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function incusHostTestConfig(string $incusStoragePool = '', string $host = 'beast'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: $host,
        sourceImage: 'images:ubuntu/26.04/cloud',
        blankImage: 'orbit-blank-ubuntu-26.04',
        baseImage: 'orbit-base-ubuntu-26.04',
        hcloudServerType: 'cpx11',
        hcloudLocation: 'ash',
        hcloudBlankImage: 'ubuntu-24.04',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: $incusStoragePool,
        incusMaxVmsPerHost: 4,
        dockerHosts: ['local'],
        dockerMaxContainersPerHost: 8,
        keep: false,
    );
}

function incusHostTestProcessResult(string $output = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

function recordingIncusHost(E2EConfig $config, array &$commands): IncusHost
{
    return new class($config, $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostTestProcessResult();
        }
    };
}

it('adds configured storage pool to launch and copy commands', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig('orbit-e2e'), $commands);

    $host->launchInstance('orbit-base-ubuntu-26.04', 'orbit-template-control');
    $host->copyInstance('orbit-template-control/clean-control', 'orbit-e2e-run-control');

    expect($commands[0])->toContain("incus launch 'orbit-base-ubuntu-26.04' 'orbit-template-control' --vm --storage 'orbit-e2e' >/dev/null")
        ->and($commands[1])->toContain("incus copy 'orbit-template-control/clean-control' 'orbit-e2e-run-control' --storage 'orbit-e2e'");
});

it('uses incus snapshot restore and supports stateful restore', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->restoreSnapshot('orbit-e2e-run-control', 'lease-clean');
    $host->restoreSnapshot('orbit-e2e-run-control', 'lease-warm', stateful: true);

    expect($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-control' 'lease-clean'")
        ->and($commands[1])->toContain("incus snapshot restore 'orbit-e2e-run-control' 'lease-warm' --stateful");
});

it('uses reusable stateful snapshots for warm topology reset points', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->snapshotStatefulInstance('orbit-e2e-run-control', 'lease-warm');

    expect($commands[0])->toContain("incus snapshot create 'orbit-e2e-run-control' 'lease-warm' --stateful --reuse");
});

it('ignores topology WireGuard addresses when resolving an Incus provider IPv4', function (): void {
    $commands = [];

    $host = new class(incusHostTestConfig(), $commands) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostTestProcessResult("10.231.0.10\n");
        }
    };

    $instance = new IncusInstance($host, 'orbit-template-control');

    expect($instance->waitForIpv4())->toBe('10.231.0.10')
        ->and($commands[0])->toContain("grep -Ev '\\((wg-orbit|docker0|br-|veth|wg0)'");
});

it('restarts journald after refreshing cloned instance network identity', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);
    $instance = new IncusInstance($host, 'orbit-e2e-run-dev');

    $instance->refreshNetworkIdentity();

    expect($commands[0])->toContain('systemd-machine-id-setup')
        ->and($commands[0])->toContain('systemctl restart systemd-journald')
        ->and($commands[0])->toContain('systemctl restart systemd-networkd');
});

it('keeps locally staged files readable before pushing them into an incus instance', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-incus-source-');
    file_put_contents($source, 'archive');
    chmod($source, 0644);

    $pushedMode = null;
    $commands = [];
    $host = new class(incusHostTestConfig(host: 'localhost'), $commands, $pushedMode) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands, private ?string &$pushedMode)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (preg_match("/^incus file push '([^']+)' /", $command, $matches) === 1) {
                $this->pushedMode = decoct(fileperms($matches[1]) & 0777);
            }

            return incusHostTestProcessResult();
        }
    };
    $instance = new IncusInstance($host, 'orbit-template-control');
    $previousUmask = umask(0077);

    try {
        $instance->copyLocalFileToInstance($source, '/tmp/orbit-current.tar.gz');
    } finally {
        umask($previousUmask);
        @unlink($source);
    }

    expect($pushedMode)->toBe('644')
        ->and($commands)->toContain("rm -f '/tmp/".basename($source)."'");
});

it('can restore snapshots concurrently', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->restoreSnapshotsConcurrently([
        'orbit-e2e-run-control',
        'orbit-e2e-run-gateway',
    ], 'lease-warm', stateful: true);

    expect($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-control' 'lease-warm' --stateful & PID_RESTORE_0=$!")
        ->and($commands[0])->toContain("incus snapshot restore 'orbit-e2e-run-gateway' 'lease-warm' --stateful & PID_RESTORE_1=$!")
        ->and($commands[0])->toContain('wait $PID_RESTORE_0')
        ->and($commands[0])->toContain('wait $PID_RESTORE_1');
});
