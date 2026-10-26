<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Models\Node;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcess;

it('uses the compiled agent service and fixes the collector listener and mapping to one port', function (): void {
    $node = new Node(['name' => 'beast']);
    $node->id = 8;
    $environment = [
        'AI_ROUTER_CLIPROXY_URL' => 'http://127.0.0.1:8317',
        'AI_ROUTER_MANAGEMENT_KEY' => 'management-key',
        'AI_ROUTER_READ_TOKEN' => 'read-token',
        'AI_ROUTER_CONTROL_TOKEN' => 'control-token',
        'AI_ROUTER_CACHE_HOST' => '10.44.0.8',
        'AI_ROUTER_CACHE_PORT' => '6379',
        'AI_ROUTER_CACHE_USERNAME' => '',
        'AI_ROUTER_CACHE_PASSWORD' => '',
    ];

    $data = AiRouterProcess::data($node, $environment);

    expect($data->targetType)->toBe(ProcessTargetType::Node)
        ->and($data->targetId)->toBe(8)
        ->and($data->name)->toBe('ai-router-collector')
        ->and($data->runtime)->toBe(ProcessRuntime::Systemd)
        ->and($data->command)->toBe([AiRouterProcess::EXECUTABLE, 'service', 'ai-router-collector'])
        ->and($data->command[0])->toStartWith('/')
        ->and($data->workingDirectory)->toBe('/')
        ->and($data->environment)->toBe([...$environment, 'AI_ROUTER_PORT' => '8787'])
        ->and($data->ports)->toBe(['127.0.0.1:8787:8787/tcp']);
});

it('refuses a caller-supplied listener value', function (): void {
    expect(fn () => AiRouterProcess::data(new Node, ['AI_ROUTER_PORT' => '8899']))
        ->toThrow(LogicException::class, 'AiRouterProcess owns AI_ROUTER_PORT.');
});
