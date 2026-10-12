<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

/** One owned UpCloud server, as {@see UpCloudServers} validated it. `address` is its public IPv4 address, `disk` its one disk. */
final readonly class UpCloudServer
{
    public function __construct(
        public string $uuid,
        public string $title,
        public string $state,
        public ?string $address,
        public string $disk,
    ) {}
}
