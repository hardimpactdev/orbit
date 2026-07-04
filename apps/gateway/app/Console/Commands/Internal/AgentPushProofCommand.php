<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Models\Node;
use App\Services\NodeCommandTransport\NodeAgentPushClient;
use App\Services\NodeCommandTransport\NodeCommandEnvelope;
use App\Services\NodeCommandTransport\NodeCommandTransportSelector;
use App\Services\NodeCommandTransport\NodeTransport;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('orbit:internal:agent-push-proof
    {node : Target node registry name}
    {--operation-token= : Scoped operation token accepted by the target Agent listener}
    {--json : Emit structured JSON proof output}')]
#[Description('Run a typed binary argv envelope through the Orbit Agent push transport')]
class AgentPushProofCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(
        NodeCommandTransportSelector $selector,
        NodeAgentPushClient $client,
    ): int {
        $nodeArgument = $this->argument('node');

        if (! is_string($nodeArgument)) {
            $this->error('Target node name is required.');

            return self::FAILURE;
        }

        $nodeName = $nodeArgument;
        /** @var Node $node */
        $node = Node::query()->where('name', $nodeName)->firstOrFail();

        $envelope = NodeCommandEnvelope::agentPushBinary(
            operationId: 'op_'.Str::uuid()->toString(),
            binary: 'orbit',
            argv: ['version', '--json'],
        );
        $transport = $selector->select($node, $envelope, NodeTransportPreference::Auto);

        if ($transport !== NodeTransport::AgentPush) {
            $this->emit([
                'node' => $node->name,
                'transport' => $transport->value,
                'status' => 'failed',
                'operation_id' => $envelope->operationId,
                'binary' => $envelope->binary,
                'error' => 'agent-push transport is unavailable for the target node',
            ]);

            return self::FAILURE;
        }

        $operationToken = $this->option('operation-token');

        if (! is_string($operationToken)) {
            $this->error('Operation token is required.');

            return self::FAILURE;
        }

        $token = trim($operationToken);
        $result = $client->execute($node, $envelope, $token);

        $this->emit([
            'node' => $node->name,
            'transport' => $result->transport,
            'status' => $result->status,
            'operation_id' => $result->operationId,
            'binary' => $result->binary,
            'exit_code' => $result->exitCode,
            'frames' => $result->frames,
        ]);

        return $result->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return;
        }

        $this->line(sprintf(
            '%s %s %s',
            (string) ($payload['node'] ?? '-'),
            (string) ($payload['transport'] ?? '-'),
            (string) ($payload['status'] ?? '-'),
        ));
    }
}
