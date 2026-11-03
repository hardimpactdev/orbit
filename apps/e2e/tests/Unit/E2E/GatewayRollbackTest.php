<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;

/** A release layout with releases a and b, b current, and a fake systemctl that records restarts. */
function gatewayRollbackFixture(bool $web = true): array
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
    // Two Processes run from the application, one from elsewhere.
    file_put_contents($tools.'/systemctl', <<<BASH
        #!/usr/bin/env bash
        case "\$1" in
            list-units) printf '%s\n' 'orbit-process-1-horizon.service loaded active running' 'orbit-process-2-schedule-work.service loaded active running' 'orbit-process-3-other.service loaded active running' ;;
            show) case "\$5" in *other*) echo /srv/other ;; *horizon*) echo {$base}/orbit/apps/gateway ;; *) echo {$base}/orbit/apps/gateway/ ;; esac ;;
            restart) echo "\$2" >> {$root}/restarted ;;
        esac
        BASH);
    chmod($tools.'/systemctl', 0755);

    return ['root' => $root, 'base' => $base, 'environment' => ['PATH' => $tools.':'.getenv('PATH'), 'ORBIT_RELEASE_BASE' => $base]];
}

function gatewayRollback(array $fixture, string $id): ProcessResult
{
    return new ProcessFactory()->env($fixture['environment'])->run([dirname(__DIR__, 5).'/bin/gateway-rollback', $id]);
}

it('switches the application and web links to a retained release and restarts the Processes that run from it', function (): void {
    $fixture = gatewayRollbackFixture();
    $result = gatewayRollback($fixture, 'aaaaaaaaaaaa');

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(readlink($fixture['base'].'/orbit'))->toBe('releases/aaaaaaaaaaaa')
        ->and(readlink($fixture['base'].'/web/current'))->toBe('releases/aaaaaaaaaaaa')
        ->and(file($fixture['root'].'/restarted', FILE_IGNORE_NEW_LINES))->toBe(['orbit-process-1-horizon.service', 'orbit-process-2-schedule-work.service'])
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
