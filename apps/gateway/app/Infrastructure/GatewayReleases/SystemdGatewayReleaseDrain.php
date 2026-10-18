<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Read-only checks of a hold installed by the root operator, never a best-effort drain. */
final readonly class SystemdGatewayReleaseDrain implements GatewayReleaseDrain
{
    public function __construct(
        private ProcessRunner $processes,
        private GatewayReleaseLayout $layout,
        private string $manifest = '/run/orbit-gateway-drain/hold.json',
        private string $cgroups = '/sys/fs/cgroup',
        private int $ownerUid = 0,
        private string $procMounts = '/proc/mounts',
    ) {}

    public function assertHeld(string $targetSha): void
    {
        try {
            $this->check($targetSha);
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Redis errors can contain connection credentials. Never include their raw message.
            $this->refuse('A drain observation could not be read. Keep the hold and inspect locally.');
        }
    }

    private function check(string $targetSha): void
    {
        foreach ([dirname($this->manifest), $this->manifest] as $path) {
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false || $stat['uid'] !== $this->ownerUid || ($stat['mode'] & 0022) !== 0 || is_link($path)) {
                $this->refuse('The drain manifest and directory must be root-owned and not writable by group or others.');
            }
        }

        $hold = json_decode((string) file_get_contents($this->manifest), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($hold) || ! GatewayReleaseCommit::isSha($targetSha) || ($hold['target_sha'] ?? null) !== $targetSha
            || ! is_string($hold['source_sha'] ?? null) || ! GatewayReleaseCommit::isSha($hold['source_sha'])
            || ! is_array($hold['units'] ?? null) || ! array_is_list($hold['units'])) {
            $this->refuse('The drain manifest must name the full target and source SHA and its unit inventory.');
        }
        $current = $this->layout->currentReleaseId();
        if ($current === null || $this->layout->preparedCommit($current) !== $hold['source_sha']) {
            $this->refuse('The current release changed since the operator established the hold.');
        }

        $units = [];
        $installed = [];
        foreach (['list-unit-files', 'list-units'] as $verb) {
            $output = $this->read(['systemctl', $verb, '--type=service,timer', '--all', '--full', '--plain', '--no-legend', '--no-pager']);
            foreach (explode("\n", trim($output)) as $line) {
                $columns = preg_split('/\s+/', trim($line));
                $name = $columns[0] ?? '';
                if ($verb === 'list-unit-files' && $columns !== false && isset($columns[1])) {
                    $installed[$name] = $columns[1];
                }
                if ($this->writer($name)) {
                    $units[] = $name;
                }
            }
        }
        $units = array_values(array_unique($units));
        sort($units);
        $expected = $hold['units'];
        foreach ($expected as $unit) {
            if (! is_string($unit) || ! $this->writer($unit)) {
                $this->refuse('The drain manifest contains an unsupported unit.');
            }
        }
        if (count(array_unique($expected)) !== count($expected)) {
            $this->refuse('The drain manifest contains duplicate units.');
        }
        $declarations = $hold['unrelated_processes'] ?? [];
        if (! is_array($declarations) || ! array_is_list($declarations)
            || (array_key_exists('unrelated_processes', $hold) && $hold['unrelated_processes'] === null)) {
            $this->refuse('Unrelated Process declarations must be an explicit list.');
        }
        $unrelated = [];
        foreach ($declarations as $declaration) {
            if (! is_array($declaration) || ! is_int($declaration['process_id'] ?? null) || $declaration['process_id'] < 1) {
                $this->refuse('An unrelated Process declaration is malformed.');
            }
            $observed = app(GatewayReleaseProcessDeclaration::class)->observe($targetSha, $declaration['process_id']);
            ksort($declaration);
            ksort($observed);
            $unit = $observed['unit'];
            if ($declaration !== $observed || ! in_array($unit, $units, true) || in_array($unit, $unrelated, true)) {
                $this->refuse('An unrelated Process declaration is stale, duplicated or absent from the live inventory.');
            }
            $unrelated[] = $unit;
        }
        $units = array_values(array_diff($units, $unrelated));
        $expected = array_values(array_diff($expected, $unrelated));
        sort($expected);
        if ($units !== $expected || ! in_array('php8.5-fpm.service', $units, true)
            || ! in_array('orbit-gateway-release.timer', $units, true)) {
            $this->refuse('The installed or loaded writer inventory differs from the maintained hold.');
        }
        foreach ($units as $unit) {
            // A template has no runtime invocation, so systemctl show rejects its name.
            // Its installed mask prevents future instances; loaded instances are checked separately.
            if (str_contains($unit, '@.')) {
                if (($installed[$unit] ?? null) !== 'masked') {
                    $this->refuse("Writer template [{$unit}] is not masked.");
                }

                continue;
            }
            $properties = [];
            foreach (explode("\n", trim($this->read(['systemctl', 'show', $unit, '--property=LoadState,ActiveState,MainPID,ControlGroup']))) as $line) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $properties[$parts[0]] = $parts[1];
                }
            }
            if (($properties['LoadState'] ?? null) !== 'masked'
                || ! in_array($properties['ActiveState'] ?? null, ['inactive', 'failed'], true)
                || (str_ends_with($unit, '.service') && (($properties['MainPID'] ?? null) !== '0' || ! array_key_exists('ControlGroup', $properties)))) {
                $this->refuse("Writer unit [{$unit}] is not masked and completely stopped.");
            }
            $group = $properties['ControlGroup'] ?? '';
            if ($group !== '') {
                if (! str_starts_with($group, '/') || str_contains($group, '..')) {
                    $this->refuse('A writer cgroup path is invalid.');
                }
                $members = @file_get_contents($this->cgroups.$group.'/cgroup.events');
                if ($members === false || preg_match('/^populated 0$/m', $members) !== 1) {
                    $this->refuse("Writer unit [{$unit}] has an unreadable or populated cgroup.");
                }
            }
        }
        $mounts = @file_get_contents($this->procMounts);
        if ($mounts === false || preg_match('~^\\S+ /proc proc ([^ ]+) ~m', $mounts, $match) !== 1
            || preg_match('/(?:^|,)hidepid=(?!0(?:,|$))/', $match[1]) === 1) {
            $this->refuse('The proc filesystem does not prove visibility of all process owners.');
        }
        // Catch old artisan commands outside the managed units without printing their argv or env.
        foreach (explode("\n", trim($this->read(['ps', '-eo', 'pid=,comm=']))) as $line) {
            $parts = preg_split('/\s+/', trim($line), 2);
            if ($parts === false || count($parts) !== 2 || ! ctype_digit($parts[0])) {
                $this->refuse('The process inventory could not be parsed.');
            }
            if ((int) $parts[0] !== getmypid() && preg_match('/^php(?:[0-9.]|$|-fpm)/', $parts[1]) === 1) {
                $this->refuse('Another PHP process remains outside the held release operation.');
            }
        }
        if (config('queue.connections.gateway.driver') !== 'redis' || config('queue.connections.gateway.connection') !== 'gateway') {
            $this->refuse('The held release requires the supported Gateway Redis queue.');
        }
        foreach (GatewayQueue::cases() as $queue) {
            $reserved = Redis::connection('gateway')->zcard('queues:'.$queue->value.':reserved');
            if (! is_int($reserved) || $reserved !== 0) {
                $this->refuse("Queue [{$queue->value}] has reservations or could not be observed.");
            }
        }
    }

    private function writer(string $unit): bool
    {
        return $unit === 'php8.5-fpm.service'
            || preg_match('/\Aorbit-(?:process-[a-zA-Z0-9_.@-]+|schedule-[a-zA-Z0-9_.@-]+|gateway-release(?:-run@[^\s\/]*)?|fleet-converge|runtime-hibernator|agent-view)\.(?:service|timer)\z/D', $unit) === 1;
    }

    /** @param non-empty-list<string> $arguments */
    private function read(array $arguments): string
    {
        $result = $this->processes->run(new ProcessInvocation($arguments, timeout: 30.0));
        if (! $result->succeeded() || $result->truncated || trim($result->stdout) === '') {
            $this->refuse('A drain inventory command failed or returned incomplete output.');
        }

        return $result->stdout;
    }

    private function refuse(string $message): never
    {
        throw new GatewayReleaseException(step: 'drain', errorCode: 'gateway.release_not_drained', message: $message);
    }
}
