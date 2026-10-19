<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Data\Processes\AddProcessData;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Node;
use LogicException;

final readonly class AiRouterProcess
{
    public const string NAME = 'ai-router-collector';

    public const int PORT = 8787;

    public const string EXECUTABLE = '/usr/bin/python3';

    /**
     * @param  array<string, string>  $environment
     */
    public static function data(Node $node, array $environment): AddProcessData
    {
        if (array_key_exists('AI_ROUTER_PORT', $environment)) {
            throw new LogicException('AiRouterProcess owns AI_ROUTER_PORT.');
        }

        return new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $node->id,
            name: self::NAME,
            runtime: ProcessRuntime::Systemd,
            command: [self::EXECUTABLE, '/var/lib/orbit/ai-router/collector.py'],
            image: null,
            workingDirectory: '/var/lib/orbit/ai-router',
            environment: [...$environment, 'AI_ROUTER_PORT' => (string) self::PORT],
            ports: ['127.0.0.1:'.self::PORT.':'.self::PORT.'/tcp'],
            volumes: [],
            restartPolicy: 'unless-stopped',
            start: true,
        );
    }
}
