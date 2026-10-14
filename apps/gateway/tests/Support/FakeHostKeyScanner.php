<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshHostKeyScanException;

/** Records scans and fails without starting ssh-keyscan or reaching a jump host. */
final class FakeHostKeyScanner implements HostKeyScanner
{
    /** @var list<array{host: string, port: int, via: SshConnection|null}> */
    public array $calls = [];

    public function scan(string $host, int $port, ?SshConnection $via = null): HostKey
    {
        $this->calls[] = ['host' => $host, 'port' => $port, 'via' => $via];

        throw new SshHostKeyScanException(
            "Could not scan the SSH host key for [{$host}:{$port}]. Feature tests fake host key scans; bind a scanner for the expected result.",
        );
    }
}
