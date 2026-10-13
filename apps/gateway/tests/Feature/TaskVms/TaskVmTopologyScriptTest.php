<?php

declare(strict_types=1);

use App\Infrastructure\TaskVms\TaskVmScripts;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

/**
 * Runs `topology.sh` with bash under a temporary HOME, with `sudo` and `systemctl` stubbed, and a checkout whose
 * harness commands are stubs that log their arguments.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 * @return array{Process, string} the finished process and its work directory
 */
function topology_script(array $arguments, array $environment = []): array
{
    $work = sys_get_temp_dir().'/orbit-topology-script-'.bin2hex(random_bytes(6));
    foreach (["{$work}/bin", "{$work}/home/.local/state/orbit/task-topology", "{$work}/checkout/bin"] as $directory) {
        mkdir($directory, 0700, true);
    }
    $stubs = [
        "{$work}/bin/sudo" => '[ "$1" = -n ] && shift; exec "$@"',
        "{$work}/bin/systemctl" => 'exit "${SYSTEMCTL_EXIT:-3}"',
        "{$work}/checkout/bin/e2e-topology-host-setup" => 'echo "host-setup $*" >> "$WORK/harness.log"',
        "{$work}/checkout/bin/e2e-topology-images" => 'echo "images $*" >> "$WORK/harness.log"; printf "%b" "${IMAGES_OUTPUT:-}"',
        "{$work}/checkout/bin/e2e-topology" => <<<'SH'
            echo "acquire $*" >> "$WORK/harness.log"
            count=$(grep -c '^acquire ' "$WORK/harness.log")
            [ "$count" -gt "${ACQUIRE_FAILURES:-0}" ]
            SH,
    ];
    foreach ($stubs as $path => $body) {
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);
    }
    $bash = new ExecutableFinder()->find('bash') ?? '/bin/bash';
    $process = new Process([$bash, TaskVmScripts::path('topology.sh'), ...str_replace('__CHECKOUT__', "{$work}/checkout", $arguments)]);
    $process->setEnv(['PATH' => "{$work}/bin:".getenv('PATH'), 'HOME' => "{$work}/home", 'WORK' => $work, ...$environment]);
    $process->run();

    return [$process, $work];
}

/** @return array<string, mixed> */
function topology_result(string $work, string $run): array
{
    return json_decode((string) file_get_contents("{$work}/home/.local/state/orbit/task-topology/{$run}.json"), true, 8, JSON_THROW_ON_ERROR);
}

function topology_boot_id(): string
{
    return trim((string) file_get_contents('/proc/sys/kernel/random/boot_id'));
}

$runArguments = ['run', 'abcdef0123456789', '__CHECKOUT__', 'TASK-7', 'app-prod+ingress', '25GiB', '10.44.0.0/24'];

beforeEach(function (): void {
    if (! is_readable('/proc/sys/kernel/random/boot_id')) {
        $this->markTestSkipped('topology.sh reads the Linux boot ID.');
    }
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/orbit-topology-script-*') ?: [] as $work) {
        new Process(['rm', '-rf', '--', $work])->run();
    }
});

describe('the topology run', function () use ($runArguments): void {
    it('lets the harness ensure the images, acquires with --replace, and writes a ready result', function () use ($runArguments): void {
        [$process, $work] = topology_script($runArguments);
        $result = topology_result($work, 'abcdef0123456789');

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file("{$work}/harness.log", FILE_IGNORE_NEW_LINES))->toBe([
                'host-setup --pool-size=25GiB --wireguard-subnet=10.44.0.0/24',
                'images ensure --nodes=app-prod+ingress',
                "acquire acquire TASK-7 {$work}/checkout --nodes=app-prod+ingress --replace",
            ])
            ->and(array_keys($result))->toBe(['state', 'step', 'seconds', 'retries', 'boot_id', 'log'])
            ->and($result)->toMatchArray(['state' => 'ready', 'step' => 'acquire', 'retries' => 0, 'boot_id' => topology_boot_id()]);
    });

    it('retries a failed acquire once and counts the retry', function () use ($runArguments): void {
        [$process, $work] = topology_script($runArguments, ['ACQUIRE_FAILURES' => '1']);
        $result = topology_result($work, 'abcdef0123456789');

        expect($process->getExitCode())->toBe(0)
            ->and($result)->toMatchArray(['state' => 'ready', 'retries' => 1])
            ->and($result['log'])->toContain('topology: warning: acquire failed; retry 1 of 1.');
    });

    it('fails at the step that failed after one retry', function () use ($runArguments): void {
        [$process, $work] = topology_script($runArguments, ['ACQUIRE_FAILURES' => '2']);

        expect($process->getExitCode())->not->toBe(0)
            ->and(topology_result($work, 'abcdef0123456789'))->toMatchArray(['state' => 'failed', 'step' => 'acquire', 'retries' => 1]);
    });

    it('keeps the log tail as one valid JSON string, whatever the harness printed', function () use ($runArguments): void {
        [, $work] = topology_script($runArguments, ['IMAGES_OUTPUT' => 'quote " back \\\\ tab \t bell \0007 bad \0377 end\n']);

        expect(topology_result($work, 'abcdef0123456789')['log'])->toContain("quote \" back \\ tab \t bell  bad  end");
    });
});

describe('the topology status', function () use ($runArguments): void {
    it('reports a ready run of this boot, and a ready run of an earlier boot as stale', function () use ($runArguments): void {
        [, $work] = topology_script($runArguments);
        $state = "{$work}/home/.local/state/orbit/task-topology/abcdef0123456789.json";
        $ready = (string) file_get_contents($state);

        [$current] = topology_script(['status', 'abcdef0123456789'], ['HOME' => "{$work}/home"]);
        file_put_contents($state, str_replace(topology_boot_id(), '00000000-0000-4000-8000-000000000000', $ready));
        [$stale] = topology_script(['status', 'abcdef0123456789'], ['HOME' => "{$work}/home"]);

        expect($current->getOutput())->toBe($ready)
            ->and($stale->getOutput())->toBe("{\"state\":\"stale\"}\n");
    });

    it('reports a run without a result as running while its unit is active, else as lost', function (): void {
        [$running] = topology_script(['status', 'abcdef0123456789'], ['SYSTEMCTL_EXIT' => '0']);
        [$lost] = topology_script(['status', 'abcdef0123456789']);

        expect($running->getOutput())->toBe("{\"state\":\"running\"}\n")
            ->and($lost->getOutput())->toBe("{\"state\":\"lost\"}\n");
    });
});
