<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
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
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: $incusStoragePool,
        dockerHosts: ['local'],
        keep: false,
    );
}

function incusHostTestProcessResult(string $output = '', int $exitCode = 0): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($exitCode === 0);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');
    $result->shouldReceive('exitCode')->andReturn($exitCode);

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

it('sets the configured root disk size when launching topology instances', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->launchTopologyInstance('orbit-blank-ubuntu-26.04', 'orbit-template-control');

    expect($commands[0])->toContain("incus launch 'orbit-blank-ubuntu-26.04' 'orbit-template-control' --vm --config=limits.cpu='1' --config=limits.memory='2GiB' --device root,size='16GiB' >/dev/null");
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

it('force stops instances when graceful incus stop times out', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->stopInstance('orbit-template-control');

    expect($commands[0])->toContain("incus stop 'orbit-template-control' --timeout 120 || incus stop 'orbit-template-control' --force");
});

it('force stops reusable template instances only when they are running', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->stopInstancesIfRunning([
        'orbit-template-control',
        'orbit-template-gateway',
    ]);

    expect($commands[0])->toContain("incus stop 'orbit-template-control' --force >/dev/null 2>&1 || true")
        ->and($commands[0])->toContain("incus stop 'orbit-template-gateway' --force >/dev/null 2>&1 || true");
});

it('checks snapshots by exact Incus snapshot path', function (): void {
    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(), $commands);

    $host->snapshotExists('orbit-template-control', 'clean-operator_gateway');

    expect($commands[0])->toContain("incus query '/1.0/instances/orbit-template-control/snapshots/clean-operator_gateway' >/dev/null 2>&1")
        ->and($commands[0])->not->toContain('grep -q');
});

it('queries exact Incus instance state when resolving a provider IPv4', function (): void {
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
        ->and($commands[0])->toContain("incus query '/1.0/instances/orbit-template-control/state'")
        ->and($commands[0])->toContain('python3 -c')
        ->and($commands[0])->toContain("awk -F, -v name='orbit-template-control'");
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
        ->and($commands[0])->toContain("incus file push '/tmp/orbit-current-transfer-")
        ->and($commands[1])->toContain("rm -f '/tmp/orbit-current-transfer-");
});

it('allows remote checkout archive copies to use ssh agent identities', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'orbit-incus-source-');
    file_put_contents($source, 'archive');

    $scpCommand = null;
    Process::fake(function ($process) use (&$scpCommand) {
        $scpCommand = $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $host = recordingIncusHost(incusHostTestConfig(host: 'beast'), $commands);
    $instance = new IncusInstance($host, 'orbit-template-control');

    try {
        $instance->copyLocalFileToInstance($source, '/tmp/orbit-current.tar.gz');
    } finally {
        @unlink($source);
    }

    expect($scpCommand)->toContain('scp -o BatchMode=yes')
        ->and($scpCommand)->not->toContain('IdentitiesOnly=yes')
        ->and($scpCommand)->toContain("'beast':")
        ->and($commands[0])->toContain("incus file push '/tmp/orbit-current-transfer-")
        ->and($commands[1])->toContain("rm -f '/tmp/orbit-current-transfer-");
});

it('stages local Docker image archives in the pushed provisioning bundle when available on the Incus host', function (): void {
    $localBundle = sys_get_temp_dir().'/orbit-incus-local-bundle-'.bin2hex(random_bytes(4));
    $remoteStage = sys_get_temp_dir().'/orbit-incus-remote-stage-'.bin2hex(random_bytes(4));
    mkdir($localBundle, 0755, true);
    file_put_contents("{$localBundle}/orbit-source.tar.gz", 'source');

    $commands = [];
    $host = new class(incusHostTestConfig(host: 'localhost'), $commands, $remoteStage) extends IncusHost
    {
        /** @var list<string> */
        private array $commands;

        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, array &$commands, private string $remoteStage)
        {
            parent::__construct($config);
            $this->commands = &$commands;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'mktemp -d')) {
                mkdir($this->remoteStage, 0755, true);

                return incusHostTestProcessResult($this->remoteStage."\n");
            }

            return incusHostTestProcessResult();
        }
    };

    try {
        $remoteBundle = $host->pushBundle($localBundle);
    } finally {
        (new Symfony\Component\Process\Process(['rm', '-rf', $localBundle, $remoteStage]))->run();
    }

    expect($remoteBundle)->toBe("{$remoteStage}/orbit-e2e-bundle")
        ->and($commands)->toContain('mktemp -d /tmp/orbit-e2e-stage-XXXXXX')
        ->and(implode("\n", $commands))->toContain("docker image inspect 'orbit-runtime:current'")
        ->and(implode("\n", $commands))->toContain("docker save 'orbit-runtime:current'")
        ->and(implode("\n", $commands))->toContain("'{$remoteStage}/orbit-e2e-bundle/orbit-runtime-current.tar'")
        ->and(implode("\n", $commands))->toContain("docker image inspect 'caddy:2-alpine'")
        ->and(implode("\n", $commands))->toContain("docker save 'caddy:2-alpine'")
        ->and(implode("\n", $commands))->toContain("'{$remoteStage}/orbit-e2e-bundle/caddy-2-alpine.tar'")
        ->and(implode("\n", $commands))->toContain("docker image inspect '4km3/dnsmasq:latest'")
        ->and(implode("\n", $commands))->toContain("docker save '4km3/dnsmasq:latest'")
        ->and(implode("\n", $commands))->toContain("'{$remoteStage}/orbit-e2e-bundle/dnsmasq-latest.tar'");
});

it('passes staged Docker image archives to the in-guest provisioner when present', function (): void {
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

            if (str_contains($command, '/composer-cache')) {
                return incusHostTestProcessResult(exitCode: 1);
            }

            return incusHostTestProcessResult();
        }
    };

    $host->provisionInstance('orbit-e2e-run-control', 'control', '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle', 'orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/orbit-runtime-current.tar'")
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/caddy-2-alpine.tar'")
        ->toContain("test -f '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle/dnsmasq-latest.tar'")
        ->toContain("incus file push -r -p '/tmp/orbit-e2e-stage-test/orbit-e2e-bundle' 'orbit-e2e-run-control/var/tmp/'")
        ->toContain('--runtime-image-archive=/var/tmp/orbit-e2e-bundle/orbit-runtime-current.tar')
        ->toContain('--caddy-image-archive=/var/tmp/orbit-e2e-bundle/caddy-2-alpine.tar')
        ->toContain('--dnsmasq-image-archive=/var/tmp/orbit-e2e-bundle/dnsmasq-latest.tar')
        ->toContain('--operator-user=');
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
