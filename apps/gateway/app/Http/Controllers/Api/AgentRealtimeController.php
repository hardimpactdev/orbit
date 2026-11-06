<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Actions\NodeFacts\AgentWatchListAction;
use App\Actions\NodeFacts\RecordNodeFactAction;
use App\Actions\Tasks\ListAgentWorkspacesAction;
use App\Data\Tasks\AgentWorkspaceData;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamStore;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentRealtimeAuthRequest;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AgentRealtimeController extends Controller
{
    private const int FactBytes = 1_048_576;

    public function show(Request $request, RealtimeConnection $realtime, ManagedNodeEligibility $eligibility): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);
        $connection = $realtime->resolve();
        $id = $node->id;

        return response()->json([
            'data' => [
                'url' => $connection?->url(),
                'address' => $connection?->resolveAddress,
                'key' => $connection?->key,
                'channel' => "presence-node.{$id}",
                'log_channel' => "presence-node-logs.{$id}",
                'member' => "agent.{$id}",
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    /** The task checkouts the caller's agent watches (ADR 0151). */
    public function workspaces(Request $request, ManagedNodeEligibility $eligibility, ListAgentWorkspacesAction $action): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);

        return response()->json([
            'data' => array_map(static fn (AgentWorkspaceData $workspace): array => $workspace->toArray(), $action->execute($node)),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    /** What the caller's agent watches: its role services and task checkouts. */
    public function watch(Request $request, ManagedNodeEligibility $eligibility, AgentWatchListAction $action): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);

        $watch = $action->execute($node);

        // `applications` is a map, so an empty one is `{}`, never `[]`.
        return response()->json([
            'data' => [...$watch, 'applications' => (object) $watch['applications']],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    /** One fact the caller's agent observed. An unchanged fact writes nothing; the answer is 204 either way. */
    public function facts(Request $request, ManagedNodeEligibility $eligibility, RecordNodeFactAction $action): Response
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);
        if (strlen($request->getContent()) > self::FactBytes) {
            throw new ResourceOperationException('agent.fact_too_large', 'An agent fact is at most 1 MiB.', 413);
        }
        $kind = NodeFactKind::from(ValidatedData::string(
            Validator::make($request->all(), ['kind' => ['required', 'string', Rule::enum(NodeFactKind::class)]])->validate()['kind']));
        $rules = ['observed_at' => ['required', 'date_format:Y-m-d\\TH:i:s.v\\Z,Y-m-d\\TH:i:s\\Z'], 'data' => ['required', 'array'],
            'subject' => $kind->subjectRules()];
        foreach ($kind->rules() as $field => $rule) {
            $rules['data.'.$field] = $rule;
        }
        // The kind's rules require named fields, so a list never reaches the object read below.
        $envelope = Validator::make($request->all(), $rules)->validate();
        $data = ValidatedData::object($envelope['data']);
        $subject = $kind->hasSubject() ? ValidatedData::string($envelope['subject']) : '';
        $action->execute($node, $kind, $subject, CarbonImmutable::parse(ValidatedData::string($envelope['observed_at'])), $data);

        return response()->noContent();
    }

    /**
     * The live log streams whose source is on the caller's Node (ADR 0153). The agent reads only what
     * this list names; a `log-streams.changed` event only prompts it to ask again.
     */
    public function logStreams(Request $request, ManagedNodeEligibility $eligibility, LogStreamStore $streams): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);

        return response()->json([
            'data' => array_map(static fn (LogStream $stream): array => [
                'id' => $stream->id,
                'lines' => $stream->lines,
                'source' => $stream->source->toArray(),
            ], $streams->forNode($node->id)),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function authenticate(AgentRealtimeAuthRequest $request, RealtimeConnection $realtime, ManagedNodeEligibility $eligibility, PresenceChannelSigner $signer, AgentReportedVersions $versions): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);
        $id = $node->id;
        $channel = $request->channelName();

        $connection = $realtime->resolve();

        if ($connection === null) {
            throw new ResourceOperationException('realtime.not_configured', 'Realtime is not configured.', 404);
        }

        // The Node's own presence channel or its own log channel (ADR 0153).
        if (! in_array($channel, ["presence-node.{$id}", "presence-node-logs.{$id}"], strict: true)) {
            throw new ResourceOperationException('agent.channel_forbidden', 'Agent may only join its own presence channels.', 403);
        }

        $version = $request->version();

        // The agent's version in presence: the fleet rollout checks it after an update (ADR 0202).
        if ($channel === "presence-node.{$id}" && $version !== null) {
            $versions->record($id, $version);
        }

        return response()->json($signer->sign($request->socketId(), $channel, $connection, 'agent.'.$node->id, [
            'kind' => 'agent',
            'node_id' => $node->id,
            'version' => $request->version(),
        ]));
    }

    private function peer(Request $request): Node
    {
        $peer = $request->user();

        if (! $peer instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        return $peer;
    }

    private function ensureEligible(Node $node, ManagedNodeEligibility $eligibility): void
    {
        if (! $eligibility->allows($node)) {
            throw new ResourceOperationException('agent.node_ineligible', 'Node is not eligible for an agent.', 403);
        }
    }
}
