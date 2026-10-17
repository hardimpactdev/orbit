<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

pest()->group('subprocess');

/** How the script derives the seed disk's udev path; the Gateway's TopologySeedContractTest checks the result. */
const HOST_SETUP_SEED_DEVICE = '/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_${seed_device//-/--}';

function hostSetupScript(): string
{
    return dirname(__DIR__, 5).'/bin/e2e-topology-host-setup';
}

/** @param list<string> $arguments */
function runHostSetup(array $arguments): Process
{
    $process = new Process([hostSetupScript(), ...$arguments], timeout: 10);
    $process->run();

    return $process;
}

/**
 * A copy of the script that runs unprivileged against stubs. The copy writes under a temporary root instead of `/etc`,
 * checks a temporary `/dev/kvm`, and takes a plain file for the seed disk, because a test cannot create a block device.
 * Every other line is the script's own. `id`, `incus`, `systemctl`, `ufw`, and `nft` are stubs that record their
 * arguments; `incus` answers from the `FAKE_*` environment.
 *
 * @return array{root:string,script:string,stubs:string,log:string,device:string}
 */
function hostSetupSandbox(bool $kvm = true, bool $device = true): array
{
    $root = temporaryPath('orbit-host-setup-', 5);
    foreach (['bin', 'dev', 'etc/systemd/system'] as $directory) {
        mkdir($root.'/'.$directory, 0o755, true);
    }
    if ($kvm) {
        touch($root.'/dev/kvm');
    }
    if ($device) {
        touch($root.'/seed-disk');
    }

    $replacements = [
        HOST_SETUP_SEED_DEVICE => $root.'/seed-disk',
        '[[ -b $device ]]' => '[[ -e $device ]]',
        '/dev/kvm' => $root.'/dev/kvm',
        '/etc/' => $root.'/etc/',
    ];
    $source = (string) file_get_contents(hostSetupScript());
    foreach ($replacements as $search => $replacement) {
        expect(substr_count($source, $search))->toBeGreaterThan(0, "The script no longer contains [{$search}].");
        $source = str_replace($search, $replacement, $source);
    }
    file_put_contents($root.'/e2e-topology-host-setup', $source);
    chmod($root.'/e2e-topology-host-setup', 0o755);

    $stub = <<<'BASH'
        #!/usr/bin/env bash
        name=$(basename "$0")
        { printf '%s' "$name"; for argument in "$@"; do printf '\x1f%s' "$argument"; done; printf '\n'; } >>"$HOST_SETUP_LOG"
        case "$name $1" in
            'id -u') echo "${FAKE_UID:-0}" ;;
            'incus storage') exit "${FAKE_POOL_MISSING:-0}" ;;
            'incus query')
                case "${!#}" in
                    /internal/recover/validate)
                        valid='{"DependencyErrors":null}'
                        printf '%s\n' "${FAKE_VALIDATION:-$valid}"
                        ;;
                    /internal/recover/import) exit "${FAKE_IMPORT_STATUS:-0}" ;;
                esac
                ;;
            'incus list') echo "${FAKE_TEMPLATE-node-template,STOPPED}" ;;
            'ufw status') [[ ${FAKE_UFW_ACTIVE:-0} == 1 ]] && echo 'Status: active' || echo 'Status: inactive' ;;
        esac
        exit 0
        BASH;
    foreach (['id', 'incus', 'systemctl', 'ufw', 'nft'] as $command) {
        file_put_contents($root.'/bin/'.$command, $stub."\n");
        chmod($root.'/bin/'.$command, 0o755);
    }

    return [
        'root' => $root,
        'script' => $root.'/e2e-topology-host-setup',
        'stubs' => $root.'/bin',
        'log' => $root.'/calls.log',
        'device' => $root.'/seed-disk',
    ];
}

/**
 * @param  array{root:string,script:string,stubs:string,log:string,device:string}  $sandbox
 * @param  array<string, string>  $fakes
 * @param  list<string>  $arguments
 */
function runHostSetupSandbox(array $sandbox, array $fakes = [], array $arguments = []): Process
{
    $process = new Process([$sandbox['script'], ...$arguments], env: [
        'PATH' => $sandbox['stubs'].':'.getenv('PATH'),
        'HOST_SETUP_LOG' => $sandbox['log'],
        ...$fakes,
    ], timeout: 20);
    $process->run();

    return $process;
}

/**
 * @param  array{root:string,script:string,stubs:string,log:string,device:string}  $sandbox
 * @return list<list<string>> every stub call, as its command and arguments
 */
function hostSetupCalls(array $sandbox, string $command): array
{
    if (! is_file($sandbox['log'])) {
        return [];
    }
    $calls = array_map(
        static fn (string $line): array => explode("\x1f", $line),
        array_filter(explode("\n", (string) file_get_contents($sandbox['log']))),
    );

    return array_values(array_map(
        static fn (array $call): array => array_slice($call, 1),
        array_filter($calls, static fn (array $call): bool => $call[0] === $command),
    ));
}

/** @return array<string, mixed> */
function hostSetupRecovery(string $device): array
{
    return ['pools' => [['name' => 'topology-seed', 'driver' => 'btrfs', 'config' => ['source' => $device]]]];
}

describe('bin/e2e-topology-host-setup', function () {
    it('is valid Bash', function () {
        expect(new Process(['bash', '-n', hostSetupScript()], timeout: 5)->run())->toBe(0);
    });

    it('refuses invalid options with its usage before it checks for root', function (array $arguments) {
        $process = runHostSetup($arguments);

        expect($process->getExitCode())->toBe(64)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toStartWith('usage: sudo bin/e2e-topology-host-setup [--wireguard-interface=NAME] [--wireguard-subnet=CIDR]');
    })->with([
        'interface name too long' => [['--wireguard-interface=orbit-wireguard0']],
        'interface name with a space' => [['--wireguard-interface=orbit 0']],
        'subnet without a prefix' => [['--wireguard-subnet=10.44.0.0']],
        'IPv6 subnet' => [['--wireguard-subnet=fd00::/64']],
        'unknown option' => [['--pool=topology-seed']],
        'positional argument' => [['topology-seed']],
        'help' => [['--help']],
    ]);

    it('refuses to run without root after valid options and touches nothing', function () {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, ['FAKE_UID' => '1000'], ['--wireguard-interface=wg0', '--wireguard-subnet=10.45.0.0/24']);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toBe("e2e-topology-host-setup: run it with sudo.\n")
            ->and(hostSetupCalls($sandbox, 'incus'))->toBe([])
            ->and(is_dir($sandbox['root'].'/etc/orbit-e2e'))->toBeFalse();
    });

    it('adopts the seed disk as the pool through recovery validate, then import', function () {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, ['FAKE_POOL_MISSING' => '1']);
        $recovery = json_encode(hostSetupRecovery($sandbox['device']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(hostSetupCalls($sandbox, 'incus'))->toBe([
                ['storage', 'show', 'topology-seed'],
                ['query', '--request', 'POST', '--data', $recovery, '/internal/recover/validate'],
                ['query', '--request', 'POST', '--data', $recovery, '/internal/recover/import'],
                ['list', '--format', 'csv', '--columns', 'ns', '--', 'node-template'],
            ])
            ->and(json_decode($process->getOutput(), true, 4, JSON_THROW_ON_ERROR))->toBe([
                'state' => 'ready',
                'pool' => 'topology-seed',
                'template' => 'node-template',
                'isolation' => 'inet orbit-e2e-isolation',
            ]);
    });

    it('skips adoption when the pool already exists', function () {
        $sandbox = hostSetupSandbox(device: false);

        $process = runHostSetupSandbox($sandbox);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(hostSetupCalls($sandbox, 'incus'))->toBe([
                ['storage', 'show', 'topology-seed'],
                ['list', '--format', 'csv', '--columns', 'ns', '--', 'node-template'],
            ]);
    });

    it('fails without a seed disk before it asks Incus to recover anything', function () {
        $sandbox = hostSetupSandbox(device: false);

        $process = runHostSetupSandbox($sandbox, ['FAKE_POOL_MISSING' => '1']);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain("e2e-topology-host-setup: this machine has no topology seed disk; only a task VM of a size that runs a topology boots with one.\n")
            ->and(hostSetupCalls($sandbox, 'incus'))->toBe([['storage', 'show', 'topology-seed']])
            ->and(is_dir($sandbox['root'].'/etc/orbit-e2e'))->toBeFalse();
    });

    it('names the dependency errors of the recovery and does not import', function () {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, [
            'FAKE_POOL_MISSING' => '1',
            'FAKE_VALIDATION' => '{"UnknownVolumes":[],"DependencyErrors":["Instance node-template: network incusbr0 is missing","Profile default is missing"]}',
        ]);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain("e2e-topology-host-setup: the topology seed cannot be adopted: Instance node-template: network incusbr0 is missing; Profile default is missing\n")
            ->and(array_column(hostSetupCalls($sandbox, 'incus'), 5))->toBe(['/internal/recover/validate'])
            ->and(is_dir($sandbox['root'].'/etc/orbit-e2e'))->toBeFalse();
    });

    it('fails when the import fails', function () {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, ['FAKE_POOL_MISSING' => '1', 'FAKE_IMPORT_STATUS' => '1']);

        expect($process->getExitCode())->toBe(1)
            ->and(array_column(hostSetupCalls($sandbox, 'incus'), 5))->toBe(['/internal/recover/validate', '/internal/recover/import'])
            ->and(is_dir($sandbox['root'].'/etc/orbit-e2e'))->toBeFalse();
    });

    it('requires the template to be stopped on the pool', function (string $template) {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, ['FAKE_TEMPLATE' => $template]);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain("e2e-topology-host-setup: the pool topology-seed holds no stopped node-template.\n")
            ->and(is_dir($sandbox['root'].'/etc/orbit-e2e'))->toBeFalse();
    })->with([
        'running' => ['node-template,RUNNING'],
        'absent' => [''],
    ]);

    it('requires KVM before it touches Incus', function () {
        $sandbox = hostSetupSandbox(kvm: false);

        $process = runHostSetupSandbox($sandbox);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('/dev/kvm is missing; topology VMs need (nested) KVM.')
            ->and(hostSetupCalls($sandbox, 'incus'))->toBe([]);
    });

    it('tests the seed device as a block device at its udev path', function () {
        $source = (string) file_get_contents(hostSetupScript());

        expect($source)->toContain("seed_device=topology-seed\n", 'device='.HOST_SETUP_SEED_DEVICE."\n")
            ->toContain('[[ -b $device ]] || {');
    });

    it('writes an isolation table that only names topology networks and drops their traffic to WireGuard', function () {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, arguments: ['--wireguard-interface=wg0', '--wireguard-subnet=10.45.0.0/24']);
        $table = (string) file_get_contents($sandbox['root'].'/etc/orbit-e2e/isolation.nft');
        $rules = array_values(array_filter(
            array_map(trim(...), explode("\n", $table)),
            static fn (string $line): bool => str_starts_with($line, 'iifname '),
        ));

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($table)->toStartWith("table inet orbit-e2e-isolation\ndelete table inet orbit-e2e-isolation\n")
            ->and($rules)->toBe([
                'iifname "oe*" oifname "wg0" drop',
                'iifname "oe*" ip daddr 10.45.0.0/24 drop',
            ])
            ->and($table)->toContain('type filter hook forward priority -10; policy accept;')
            ->and($table)->toContain('type filter hook input priority -10; policy accept;')
            ->and($table)->not->toContain('incusbr0')
            ->and((string) file_get_contents($sandbox['root'].'/etc/systemd/system/orbit-e2e-isolation.service'))
            ->toContain('ExecStart=/usr/sbin/nft -f '.$sandbox['root'].'/etc/orbit-e2e/isolation.nft')
            ->and(hostSetupCalls($sandbox, 'systemctl'))->toBe([
                ['daemon-reload'],
                ['enable', 'orbit-e2e-isolation.service'],
                ['restart', 'orbit-e2e-isolation.service'],
            ])
            ->and(hostSetupCalls($sandbox, 'nft'))->toBe([['list', 'table', 'inet', 'orbit-e2e-isolation']]);
    });

    it('lets topology guests get DHCP and DNS and route out through an active UFW, and only them', function (bool $active, array $rules) {
        $sandbox = hostSetupSandbox();

        $process = runHostSetupSandbox($sandbox, ['FAKE_UFW_ACTIVE' => $active ? '1' : '0']);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(hostSetupCalls($sandbox, 'ufw'))->toBe([['status'], ...$rules]);
    })->with([
        'active' => [true, [
            ['allow', 'in', 'on', 'oe+', 'to', 'any', 'port', '67', 'proto', 'udp', 'comment', 'orbit-e2e dhcp'],
            ['allow', 'in', 'on', 'oe+', 'to', 'any', 'port', '53', 'proto', 'udp', 'comment', 'orbit-e2e dns'],
            ['route', 'allow', 'in', 'on', 'oe+', 'comment', 'orbit-e2e guests'],
        ]],
        'inactive' => [false, []],
    ]);
});
