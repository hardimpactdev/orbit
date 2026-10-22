<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\PrivateDnsPublishHarness;

pest()->group('subprocess');

it('keeps scripted DNS command variables and shell options private', function (): void {
    $harness = new PrivateDnsPublishHarness;
    $harness->putCatalog("{\"requesters\":{},\"records\":{},\"suffixes\":{},\"overrides\":{}}\n");
    try {
        $process = new Process($harness->shell());
        $process->setInput(<<<'BASH'
            set +e
            set +u
            set +o pipefail
            unit=parent-unit
            verb=parent-verb
            each=parent-each
            state=parent-state
            dnsmasq --test
            systemctl enable fixture-one fixture-two
            systemctl restart orbit-private-dns.service
            systemctl show -p MainPID --value orbit-private-dns.service >/dev/null
            ss -4 -ulpnH src 10.44.0.1:53 >/dev/null
            sleep 0.1
            systemd-analyze verify fixture.service
            systemctl is-active --quiet dnsmasq
            status=$?
            if [[ $- != *e* && $- != *u* ]] && ! shopt -qo pipefail; then
                printf 'options-safe\n'
            else
                exit 97
            fi
            printf '%s|%s|%s|%s|%s\n' "$unit" "$verb" "$each" "$state" "$status"
            BASH);
        $process->run();
        expect($process->getExitCode())->toBe(0)
            ->and($process->getErrorOutput())->toBe('')
            ->and($process->getOutput())->toBe("options-safe\nparent-unit|parent-verb|parent-each|parent-state|3\n")
            ->and($harness->serviceCalls())->toBe([
                'enable fixture-one fixture-two',
                'enable fixture-one',
                'enable fixture-two',
                'restart orbit-private-dns.service',
                'show -p MainPID --value orbit-private-dns.service',
                'is-active --quiet dnsmasq',
            ])
            ->and(trim((string) file_get_contents($harness->loadedPath())))->toBe(hash_file('sha256', $harness->catalogPath()));
    } finally {
        $harness->cleanup();
    }
});

it('sources DNS commands and their recursive calls without using their executable interpreters', function (): void {
    $harness = new PrivateDnsPublishHarness;
    $files = new Filesystem;
    $root = dirname($harness->recordsPath(), 3);
    $commands = ['dnsmasq', 'systemctl', 'ss', 'grep', 'sleep', 'systemd-analyze'];
    try {
        foreach ($commands as $name) {
            $path = $root.'/bin/'.$name;
            $body = substr($files->get($path), strlen("#!/bin/bash\n"));
            $files->put($path, "#!/usr/bin/false\n".$body);
        }
        $disabled = new Process([$root.'/bin/systemctl', 'enable', 'fixture-one', 'fixture-two']);
        $disabled->run();
        expect($disabled->getExitCode())->toBe(1);

        $process = new Process($harness->shell());
        $process->setInput('rm -f -- '.implode(' ', array_map(static fn (string $name): string => escapeshellarg($root.'/bin/'.$name.'.bash'), $commands))."\n".<<<'BASH'
            dnsmasq --test
            systemctl enable fixture-one fixture-two
            ss -4 -ulpnH src 10.44.0.1:53 >/dev/null
            sleep 0.1
            systemd-analyze verify fixture.service
            BASH);
        $process->run();
        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toBe('')
            ->and($harness->serviceCalls())->toBe([
                'enable fixture-one fixture-two',
                'enable fixture-one',
                'enable fixture-two',
            ]);
    } finally {
        $harness->cleanup();
    }
});

it('matches only the queried PID in scripted socket output without an executable grep', function (string $output, int $status): void {
    $harness = new PrivateDnsPublishHarness;
    $root = dirname($harness->recordsPath(), 3);
    try {
        file_put_contents($root.'/bin/grep', "#!/usr/bin/false\n");
        $process = new Process($harness->shell());
        $process->setInput('printf %s '.escapeshellarg($output).' | grep -q pid=4242,');
        $process->run();
        expect($process->getExitCode())->toBe($status)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toBe('');
    } finally {
        $harness->cleanup();
    }
})->with([
    'no listener' => ['', 1],
    'listener owns socket' => ["UNCONN users:((\"php8.5\",pid=4242,fd=5))\n", 0],
    'different PID prefix' => ["UNCONN users:((\"php8.5\",pid=14242,fd=5))\n", 1],
    'different PID suffix' => ["UNCONN users:((\"php8.5\",pid=42420,fd=5))\n", 1],
    'another line without a final newline' => ["unrelated\nLISTEN users:((\"php8.5\",pid=4242,fd=6))", 0],
]);

/** @param list<string> $arguments */
it('delegates non-PID queries to real grep with their file arguments unchanged', function (array $arguments, string $contents, int $status, string $output): void {
    $harness = new PrivateDnsPublishHarness;
    $root = dirname($harness->recordsPath(), 3);
    try {
        $file = $root.'/grep-input';
        file_put_contents($file, $contents);
        $process = new Process($harness->shell());
        $process->setInput('grep '.implode(' ', array_map(escapeshellarg(...), [...$arguments, $file])));
        $process->run();
        expect($process->getExitCode())->toBe($status)
            ->and($process->getOutput())->toBe($output)
            ->and($process->getErrorOutput())->toBe('');
    } finally {
        $harness->cleanup();
    }
})->with([
    'retained release reference' => [['-qF', '--', '/release/keep/'], "ExecStart=/release/keep/serve.php\n", 0, ''],
    'unreferenced release' => [['-qF', '--', '/release/prune/'], "ExecStart=/release/keep/serve.php\n", 1, ''],
    'non-numeric PID expression' => [['-q', 'pid=.*,'], "users:((\"php8.5\",pid=4242,fd=6))\n", 0, ''],
    'different option and preserved output' => [['-v', 'pid=4242,'], "unrelated\npid=4242,\n", 0, "unrelated\n"],
]);

it('keeps strict executable DNS wrappers backed by the same command definitions', function (): void {
    $harness = new PrivateDnsPublishHarness;
    $root = dirname($harness->recordsPath(), 3);
    try {
        foreach ([
            ['dnsmasq', '--test'],
            ['systemctl', 'enable', 'fixture-one', 'fixture-two'],
            ['ss', '-4', '-ulpnH', 'src', '10.44.0.1:53'],
            ['sleep', '0.1'],
            ['systemd-analyze', 'verify', 'fixture.service'],
        ] as $arguments) {
            $process = new Process([$root.'/bin/'.$arguments[0], ...array_slice($arguments, 1)]);
            $process->run();
            expect($process->getExitCode())->toBe(0)
                ->and($process->getOutput())->toBe('')
                ->and($process->getErrorOutput())->toBe('');
        }
        expect($harness->serviceCalls())->toBe([
            'enable fixture-one fixture-two',
            'enable fixture-one',
            'enable fixture-two',
        ]);
        $strict = new Process([$root.'/bin/systemctl']);
        $strict->run();
        expect($strict->getExitCode())->toBe(1)
            ->and($strict->getErrorOutput())->toContain('unbound variable');
    } finally {
        $harness->cleanup();
    }
});
