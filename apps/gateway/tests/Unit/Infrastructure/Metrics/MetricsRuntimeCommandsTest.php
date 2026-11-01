<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsRuntimeCommands;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('retains only the observer health query and treats unknown health as false', function (): void {
    $runner = new FakeAgentCommandRunner()->answer('metrics.runtime-health', ['healthy' => true]);
    $reader = new MetricsRuntimeCommands($runner, new CommandDeadline);
    $node = new Node(['name' => 'metrics']);
    expect($reader->health($node, 'prometheus'))->toBeTrue();
    expect($runner->calls[0]['input'])->toBe(['service' => 'prometheus']);
    $runner->answerMalformed('metrics.runtime-health', ['healthy' => 'unknown']);
    expect($reader->health($node, 'grafana'))->toBeFalse();
});
