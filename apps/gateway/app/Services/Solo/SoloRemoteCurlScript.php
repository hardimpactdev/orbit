<?php

declare(strict_types=1);

namespace App\Services\Solo;

final class SoloRemoteCurlScript
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function forRequest(
        SoloUpstreamTarget $target,
        string $method,
        string $url,
        array $payload,
    ): string {
        $command = [
            'curl',
            '-sS',
            '-X',
            $method,
            '--connect-timeout',
            '2',
            '--max-time',
            '5',
            '-H',
            'Accept: application/json',
            '-H',
            "X-Orbit-Node: {$target->identity}",
        ];

        if ($target->bearerToken !== null && $target->bearerToken !== '') {
            $command[] = '-H';
            $command[] = "Authorization: Bearer {$target->bearerToken}";
        }

        if ($payload !== []) {
            $command[] = '-H';
            $command[] = 'Content-Type: application/json';
            $command[] = '--data-binary';
            $command[] = '@-';
        }

        $command[] = '-o';
        $command[] = '$body';
        $command[] = '-w';
        $command[] = '%{http_code}';
        $command[] = $url;

        return implode("\n", [
            'set -euo pipefail',
            'body="$(mktemp)"',
            'trap \'rm -f "$body"\' EXIT',
            'status="$('.$this->shellCommand($command).')"',
            'printf "%s\n" "$status"',
            'cat "$body"',
        ]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function shellCommand(array $arguments): string
    {
        return implode(' ', array_map(
            static fn (string $argument): string => $argument === '$body' ? '"$body"' : escapeshellarg($argument),
            $arguments,
        ));
    }
}
