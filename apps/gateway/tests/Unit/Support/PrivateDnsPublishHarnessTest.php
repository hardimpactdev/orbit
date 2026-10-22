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
    $commands = ['dnsmasq', 'systemctl', 'ss', 'sleep', 'systemd-analyze'];
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
