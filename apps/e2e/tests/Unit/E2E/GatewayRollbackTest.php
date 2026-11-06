<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;

/** A release layout with releases a and b, b current, and a fake systemctl that records restarts. */
function gatewayRollbackFixture(bool $web = true, bool $horizon = true): array
{
    $root = temporaryPath('orbit-gateway-rollback-', 6);
    $base = $root.'/home';
    $tools = $root.'/tools';
    foreach (['aaaaaaaaaaaa', 'bbbbbbbbbbbb'] as $id) {
        mkdir($base.'/releases/'.$id.'/apps/gateway', 0700, true);
        file_put_contents($base.'/releases/'.$id.'/REVISION', $id.str_repeat('0', 28)."\n");
        if ($web) {
            mkdir($base.'/web/releases/'.$id, 0700, true);
            file_put_contents($base.'/web/releases/'.$id.'/index.html', $id);
        }
    }
    mkdir($tools, 0700, true);
    if (! $web) {
        mkdir($base.'/web/releases', 0700, true);
    }
    symlink('releases/bbbbbbbbbbbb', $base.'/orbit');
    symlink('releases/bbbbbbbbbbbb', $base.'/web/current');
    // Two Processes and agent-view run from the application; another Process and PHP-FPM do not. Horizon is the first Process, running or stopped.
    $horizonActive = $horizon ? 'true' : 'false';
    $horizonListed = $horizon ? "'orbit-process-1-horizon.service loaded active running' " : '';
    file_put_contents($tools.'/systemctl', <<<BASH
        #!/usr/bin/env bash
        case "\$1" in
            list-unit-files) printf '%s\n' 'getty@.service enabled enabled' 'orbit-process-1-horizon.service enabled enabled' 'orbit-process-2-schedule-work.service enabled enabled' 'orbit-process-3-other.service enabled enabled' ;;
            is-active) [ "\$3" = orbit-process-1-horizon.service ] && {$horizonActive} ;;
            start) echo "\$1 \$2" >> {$root}/restarted ;;
            list-units) [ "\$3" = --state=active ] && printf '%s\n' {$horizonListed}'orbit-process-2-schedule-work.service loaded active running' 'orbit-agent-view.service loaded active running' 'orbit-process-3-other.service loaded active running' 'php8.5-fpm.service loaded active running' ;;
            show) case "\$5" in *@.service) echo 'Failed to get properties: Unit name is not valid' >&2; exit 1 ;; esac
                [ "\$3" = ExecStart ] && { case "\$5" in *horizon*) echo '{ path=/usr/bin/php8.5 ; argv[]=/usr/bin/php8.5 artisan horizon ; ignore_errors=no }' ;; *) echo '{ path=/usr/bin/php8.5 ; argv[]=/usr/bin/php8.5 artisan schedule:work ; ignore_errors=no }' ;; esac; exit 0; }
                case "\$5" in *other*) echo /srv/other ;; php*) echo ;; *horizon*) echo {$base}/orbit/apps/gateway ;; *) echo {$base}/orbit/apps/gateway/ ;; esac ;;
            restart | reload) echo "\$1 \$2" >> {$root}/restarted ;;
        esac
        BASH);
    chmod($tools.'/systemctl', 0755);

    return ['root' => $root, 'base' => $base, 'environment' => ['PATH' => $tools.':'.getenv('PATH'), 'ORBIT_RELEASE_BASE' => $base]];
}

function gatewayRollback(array $fixture, string $id): ProcessResult
{
    return new ProcessFactory()->env($fixture['environment'])->run([dirname(__DIR__, 5).'/bin/gateway-rollback', $id]);
}

it('switches the application and web links, reloads PHP-FPM and restarts the running services that run from the release', function (): void {
    $fixture = gatewayRollbackFixture();
    $result = gatewayRollback($fixture, 'aaaaaaaaaaaa');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(readlink($fixture['base'].'/orbit'))->toBe('releases/aaaaaaaaaaaa')
        ->and(readlink($fixture['base'].'/web/current'))->toBe('releases/aaaaaaaaaaaa')
        ->and(file($fixture['root'].'/restarted', FILE_IGNORE_NEW_LINES))->toBe(['reload php8.5-fpm', 'restart orbit-process-1-horizon.service', 'restart orbit-process-2-schedule-work.service', 'restart orbit-agent-view.service'])
        ->and(file_exists($fixture['base'].'/orbit.next'))->toBeFalse();
});

it('leaves the web app as it is when the release keeps no web build', function (): void {
    $fixture = gatewayRollbackFixture(web: false);
    $result = gatewayRollback($fixture, 'aaaaaaaaaaaa');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(readlink($fixture['base'].'/orbit'))->toBe('releases/aaaaaaaaaaaa')
        ->and(readlink($fixture['base'].'/web/current'))->toBe('releases/bbbbbbbbbbbb')
        ->and($result->output())->toContain('keeps no web build');
});

it('refuses an unknown or unsealed release and changes nothing', function (string $id, int $exit): void {
    $fixture = gatewayRollbackFixture();
    $result = gatewayRollback($fixture, $id);

    expect($result->exitCode())->toBe($exit)
        ->and(readlink($fixture['base'].'/orbit'))->toBe('releases/bbbbbbbbbbbb')
        ->and(file_exists($fixture['root'].'/restarted'))->toBeFalse();
})->with([
    'not an id' => ['main', 64],
    'not retained' => ['cccccccccccc', 1],
]);

it('starts a stopped Horizon, which is what a failed release leaves, and starts nothing when Horizon runs', function (): void {
    $stopped = gatewayRollbackFixture(horizon: false);
    $result = gatewayRollback($stopped, 'aaaaaaaaaaaa');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(file($stopped['root'].'/restarted', FILE_IGNORE_NEW_LINES))->toBe(['reload php8.5-fpm', 'restart orbit-process-2-schedule-work.service', 'restart orbit-agent-view.service', 'start orbit-process-1-horizon.service'])
        ->and($result->output())->toContain('started orbit-process-1-horizon.service');

    $running = gatewayRollbackFixture();
    gatewayRollback($running, 'aaaaaaaaaaaa');

    expect(array_filter(file($running['root'].'/restarted', FILE_IGNORE_NEW_LINES), static fn (string $line): bool => str_starts_with($line, 'start ')))->toBe([]);
});
