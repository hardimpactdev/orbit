<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\Processes\NativeProcessRunner;

/** A private release whose smoke command records arguments and follows its smoke-mode file. */
function smoke_release(string $base, string $id): string
{
    $release = $base.'/releases/'.$id;
    mkdir($release.'/bin', 0755, true);
    file_put_contents($release.'/bin/gateway-smoke', <<<'BASH'
        #!/usr/bin/env bash
        root=$(dirname "$0")/..
        printf '%s\n' "$@" > "$root/smoke-args"
        printf '%s' "${SSL_CERT_FILE:-}" > "$root/smoke-ssl"
        mode=$(cat "$root/smoke-mode" 2>/dev/null || echo pass)
        case $mode in
            pass) printf '{"schema":1,"passed":true,"expected_sha":"%s","summary":{"passed":7,"failed":0,"timeout":0,"skipped":1},"checks":{"web":{"status":"passed","error":null}}}\n' "$2" ;;
            fail) printf '{"schema":1,"passed":false,"error":"checks_failed","failed_checks":["web"],"message":"1 of 7 checks did not pass: web.","checks":{"web":{"status":"failed","error":"web_release_mismatch"}}}\n'; exit 1 ;;
            garbage) echo 'Traceback (most recent call last)' >&2; exit 1 ;;
            hang)
                # Reap the child before exiting: kill(pid, 0) also sees an orphaned zombie.
                trap 'wait "$child"; exit 143' TERM
                sleep 60 & child=$!
                echo "$child" > "$root/smoke-child"
                wait "$child"
                ;;
            killed) kill -9 $$ ;;
            terminated) trap 'printf "{\"schema\":1,\"passed\":false,\"error\":\"terminated\"}\n"; exit 143' TERM; sleep 60 & wait ;;
        esac
        BASH);
    chmod($release.'/bin/gateway-smoke', 0755);

    return $release;
}

function smoke_runner(GatewayReleaseLayout $layout, ?string $project = null, int $timeout = 90, int $grace = 15, string $caFile = '/nonexistent/root-ca.pem'): ScriptGatewayReleaseSmoke
{
    return new ScriptGatewayReleaseSmoke(
        layout: $layout,
        processes: new NativeProcessRunner(maxOutputBytes: 1_048_576),
        origin: 'https://gateway.orbit',
        webRoot: '/home/orbit/web',
        timeoutSeconds: $timeout,
        writeCheckProject: $project,
        caFile: $caFile,
        graceSeconds: $grace,
    );
}
