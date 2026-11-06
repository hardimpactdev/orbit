<?php

declare(strict_types=1);

use App\Domain\Projects\ScheduledProjectMaintenance;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ImageReaper;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampSchedulePublisher;

/** A serve that confirms a `workflow.trigger.set` frame with the entry it holds, or that never answers. */
function triggerSocket(bool $answers = true): WebSocketClient
{
    $socket = new class($answers) implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

        private bool $open = false;

        public function __construct(private readonly bool $answers) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);
            if (! $this->answers || $frame === false) {
                $this->open = false;

                return [];
            }
            $payload = $frame['payload'];

            return [['id' => $frame['id'], 'type' => 'workflow.trigger.set', 'payload' => ['data' => [
                'workflowName' => $payload['workflowName'], 'entry' => ['schedule' => $payload['schedule'], 'inputs' => json_decode(json_encode($payload['inputs']), true)],
            ]]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token', 'swamp.address' => '10.44.0.15', 'swamp.ca_path' => __FILE__]);

    return $socket;
}

it('sends the definition of a schedule once, and again only when its cadence or inputs change', function (): void {
    $socket = triggerSocket();
    $publisher = app(SwampSchedulePublisher::class);
    $workflow = ScheduledProjectMaintenance::Workflow;

    $publisher->publish($workflow, '40 17 * * * *', []);
    $publisher->publish($workflow, '40 17 * * * *', []);
    $publisher->publish($workflow, '40 17 * * * *', []);

    expect($socket->frames)->toHaveCount(1)
        ->and($socket->frames[0]['type'])->toBe('workflow.trigger.set')
        ->and($socket->frames[0]['payload']['workflowName'])->toBe('maintenance-projects')
        ->and($socket->frames[0]['payload']['inputs'])->toEqual((object) []);

    $publisher->publish($workflow, '0 17 * * * *', []);
    $publisher->publish($workflow, '0 17 * * * *', ['revision' => 2]);
    $publisher->publish($workflow, '0 17 * * * *', ['revision' => 2]);

    expect($socket->frames)->toHaveCount(3)
        ->and($socket->frames[2]['payload']['inputs'])->toBe(['revision' => 2]);
});

it('does not remember a definition that serve did not confirm, so the next call sends it again', function (): void {
    $silent = triggerSocket(answers: false);
    $publisher = app(SwampSchedulePublisher::class);

    expect(fn () => $publisher->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []))
        ->toThrow(ResourceOperationException::class, 'not confirmed');

    $answering = triggerSocket();
    app()->forgetInstance(SwampSchedulePublisher::class);
    app(SwampSchedulePublisher::class)->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []);

    expect($silent->frames)->toHaveCount(1)
        ->and($answering->frames)->toHaveCount(1);
});

it('remembers each workflow and each Swamp address on its own', function (): void {
    $socket = triggerSocket();
    $publisher = app(SwampSchedulePublisher::class);

    $publisher->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []);
    $publisher->publish(ImageReaper::Workflow, '40 17 * * * *', []);
    config(['swamp.address' => '10.44.0.16']);
    $publisher->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []);

    expect($socket->frames)->toHaveCount(3);
});

it('sends the definition again after a day, in case serve lost it', function (): void {
    $socket = triggerSocket();
    $publisher = app(SwampSchedulePublisher::class);

    $publisher->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []);
    $this->travel(23)->hours();
    $publisher->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []);
    $this->travel(2)->hours();
    $publisher->publish(ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []);

    expect($socket->frames)->toHaveCount(2);
});
