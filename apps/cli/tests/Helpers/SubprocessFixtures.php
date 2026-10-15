<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function subprocess_fixture_address(Process $server): string
{
    subprocess_fixture_wait_until($server, static fn (): bool => str_contains($server->getOutput(), "\n"));
    $address = trim(explode("\n", $server->getOutput(), 2)[0]);

    if (! $server->isRunning()) {
        throw new RuntimeException('Fixture server exited before accepting a connection: '.$server->getErrorOutput());
    }

    if ($address === '') {
        throw new RuntimeException('Fixture server printed an empty address: '.$server->getErrorOutput());
    }

    return $address;
}

function subprocess_fixture_wait_until(Process $server, Closure $condition): void
{
    while (true) {
        $server->checkTimeout();
        $running = $server->isRunning();

        if ($condition()) {
            return;
        }

        if (! $running) {
            throw new RuntimeException('Fixture server exited before the expected output: '.$server->getErrorOutput());
        }

        usleep(10_000);
    }
}
