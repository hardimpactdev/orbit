<?php

declare(strict_types=1);

use App\Domain\AppDev\DnsPublishProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

afterEach(fn () => app(SwampConnection::class)->release());

const DNS_PUBLISH_RUN = '5b1c3d7e-2f4a-4c8e-9a10-6d2e8f0b1a43';

/** A running dns.publish Operation that carries the entry its Swamp run publishes. */
function dnsPublishOperation(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.1',
        'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $entry = ['node_id' => $node->id, 'connection' => ['nodeHost' => '10.44.0.1'], 'host_keys' => null,
        'dns' => ['revision' => '1790000000000001', 'listen' => ['10.44.0.1'], 'tlds' => [], 'records' => []]];

    return Operation::query()->create(['kind' => 'dns.publish', 'target_type' => 'private_dns', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => ['intent' => $entry], 'state' => ['swamp_run_id' => DNS_PUBLISH_RUN]]);
}

/**
 * Swamp's run history of a dns-publish run, as the shared terminal reader gets it: the phase step failed or passed, and the step
 * "Report the result" printed the phase's result.json on stdout.
 *
 * @param  array<string, mixed>  $printed  What result.json holds.
 * @return array<string, mixed>
 */
function dnsPublishHistory(Operation $operation, string $status, array $printed): array
{
    return [
        'id' => DNS_PUBLISH_RUN, 'workflowName' => 'dns-publish', 'status' => $status,
        'startedAt' => now()->subSeconds(5)->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'dns.publish'],
        'jobs' => [['name' => 'publish', 'steps' => [
            ['name' => 'input', 'status' => 'succeeded'],
            ['name' => 'Run the DNS phase', 'status' => $status === 'succeeded' ? 'succeeded' : 'failed', 'outputs' => ['stderr' => 'a task failed']],
            ['name' => 'Report the result', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($printed, JSON_THROW_ON_ERROR)]],
            ['name' => 'cleanup', 'status' => 'succeeded'],
        ]]],
    ];
}

/** Serve answers the history of the run and an empty list of nested runs. */
function dnsPublishServe(array $history): void
{
    $socket = new class($history) implements WebSocketClient
    {
        private ?array $frame = null;

        private bool $open = false;

        /** @param array<string, mixed> $history */
        public function __construct(private readonly array $history) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = $this->frame;
            $this->frame = null;
            if ($frame === null) {
                return [];
            }
            $data = $frame['type'] === 'workflow.history.get' ? $this->history : ['results' => []];

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $data]]];
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
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'read-token']);
}

/** The shared terminal subscriber's path: the declared selector reads the run, and the projection records the result. */
function projectDnsPublishTerminal(Operation $operation): Operation
{
    $definition = app(DirectOperation::class)->definition('dns.publish');

    return app(SwampCommandStep::class)->projectTerminal($operation, ['id' => DNS_PUBLISH_RUN], $definition['terminal_result']);
}

describe('DnsPublishProjection', function (): void {
    it('hands the run the entry the Gateway rendered and no secret', function (): void {
        $operation = dnsPublishOperation();

        expect(app(DnsPublishProjection::class)->input($operation))
            ->toBe(['intent' => $operation->input['intent'], 'execution_secrets' => []]);
    });

    it('declares the terminal step the workflow prints its result in', function (): void {
        $workflow = file_get_contents(base_path('../../swamp/workflows/workflow-dns-publish.yaml'));
        $selector = app(DirectOperation::class)->definition('dns.publish')['terminal_result'];

        expect($selector)->toBe(['job' => 'publish', 'step' => 'Report the result'])
            ->and($workflow)->toContain("name: 'Report the result'");
    });

    it('records what the phase installed for a succeeded run', function (): void {
        $operation = dnsPublishOperation();
        dnsPublishServe(dnsPublishHistory($operation, 'succeeded', ['revision' => '1790000000000001', 'dnsmasq' => 'restarted']));

        $projected = projectDnsPublishTerminal($operation);

        expect($projected->status)->toBe(OperationStatus::Succeeded)
            ->and($projected->result)->toBe(['revision' => '1790000000000001', 'dnsmasq' => 'restarted'])
            ->and($projected->lock_key)->toBeNull();
    });

    it('records the installed revision and the skip of a superseded publication', function (): void {
        $operation = dnsPublishOperation();
        dnsPublishServe(dnsPublishHistory($operation, 'succeeded', ['revision' => '1790000000000009', 'dnsmasq' => 'unchanged', 'skipped' => 'dns.revision_superseded']));

        $projected = projectDnsPublishTerminal($operation);

        expect($projected->status)->toBe(OperationStatus::Succeeded)
            ->and($projected->result)->toBe(['revision' => '1790000000000009', 'dnsmasq' => 'unchanged', 'skipped' => 'dns.revision_superseded']);
    });

    it('records the code the phase printed for a failed run', function (): void {
        $operation = dnsPublishOperation();
        dnsPublishServe(dnsPublishHistory($operation, 'failed', ['error_code' => 'dns.check_failed']));

        $projected = projectDnsPublishTerminal($operation);

        expect($projected->status)->toBe(OperationStatus::Failed)
            ->and($projected->error_code)->toBe('dns.check_failed')
            ->and($projected->result)->toBe(['error_code' => 'dns.check_failed']);
    });

    it('records whether the Node was recovered after a failure, and the cause when the recovery failed', function (): void {
        $operation = dnsPublishOperation();
        dnsPublishServe(dnsPublishHistory($operation, 'failed', ['error_code' => 'dns.rollback_failed', 'recovered' => false, 'cause' => 'dns.check_failed']));

        $projected = projectDnsPublishTerminal($operation);

        expect($projected->error_code)->toBe('dns.rollback_failed')
            ->and($projected->result)->toBe(['error_code' => 'dns.rollback_failed', 'recovered' => false, 'cause' => 'dns.check_failed']);
    });

    it('records a recovered Node', function (): void {
        $operation = dnsPublishOperation();
        dnsPublishServe(dnsPublishHistory($operation, 'failed', ['error_code' => 'dns.check_failed', 'recovered' => true]));

        expect(projectDnsPublishTerminal($operation)->result)->toBe(['error_code' => 'dns.check_failed', 'recovered' => true]);
    });
});
