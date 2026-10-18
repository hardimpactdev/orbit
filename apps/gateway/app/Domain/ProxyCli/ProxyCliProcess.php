<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Node;

final readonly class ProxyCliProcess
{
    public const string NAME = 'cli-proxy-api-collector';

    public const int PORT = 8787;

    public const string EXECUTABLE = '/usr/bin/python3';

    /**
     * @param  array<string, string>  $environment
     */
    public static function data(Node $node, array $environment): AddProcessData
    {
        return new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $node->id,
            name: self::NAME,
            runtime: ProcessRuntime::Systemd,
            command: [self::EXECUTABLE, '/var/lib/orbit/proxycli/server.py'],
            image: null,
            workingDirectory: '/var/lib/orbit/proxycli',
            environment: [...$environment, 'PROXYCLI_PORT' => (string) self::PORT],
            ports: ['127.0.0.1:'.self::PORT.':'.self::PORT.'/tcp'],
            volumes: [],
            restartPolicy: 'unless-stopped',
            start: true,
        );
    }
}
