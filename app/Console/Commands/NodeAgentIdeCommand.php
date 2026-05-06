<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\AgentIde\ListAgentIdeAdaptersRequest;
use App\Http\Gateway\Requests\Nodes\SetNodeAgentIdeRequest;
use App\Http\Gateway\Responses\AgentIde\AgentIdeAdapterChoicesResponse;
use App\Http\Gateway\Responses\Nodes\NodeAgentIdeResponse;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Nodes\NodeAgentIdeDefaults;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('node:agent-ide
    {name? : Node name}
    {agent_ide? : Agent IDE adapter (opencode, polyscope, or none)}
    {--json : Output JSON}')]
#[Description('Set the default agent IDE for a node')]
class NodeAgentIdeCommand extends Command
{
    public function handle(NodeAgentIdeDefaults $defaults): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control or gateway node.',
                meta: ['caller_role' => 'app'],
            );
        }

        if ($callerRole === 'unknown') {
            return $this->failCommand(
                code: 'local_context_invalid',
                message: 'Local node role setting is invalid.',
                meta: [
                    'setting' => 'general.local_node_role',
                    'reason' => 'unsupported_value',
                    'caller_role' => 'unknown',
                ],
            );
        }

        if ($callerRole === 'control' && ! $this->hasConfiguredGateway()) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update node configuration.',
                meta: [],
            );
        }

        $name = $this->resolveName($callerRole);

        if ($name === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name is required.',
                meta: ['field' => 'name'],
            );
        }

        try {
            $agentIde = $this->resolveAgentIde($callerRole, $defaults);
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to read agent IDE adapters.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to read agent IDE adapters.',
                meta: [],
            );
        }

        if ($agentIde === null) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Agent IDE adapter is required.',
                meta: ['field' => 'agent_ide'],
            );
        }

        if ($callerRole === 'control') {
            return $this->forwardSet($name, $agentIde);
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->failCommand(
                code: 'node.not_found',
                message: "Node '{$name}' not found.",
                meta: ['name' => $name],
            );
        }

        if (! $defaults->isSupported($agentIde)) {
            return $this->failCommand(
                code: 'node.unsupported_adapter',
                message: "Adapter '{$agentIde}' is not supported.",
                meta: ['adapter' => $agentIde],
            );
        }

        return $this->respondSuccess($defaults->set($node, $agentIde));
    }

    private function resolveName(string $callerRole): ?string
    {
        $name = $this->stringArgument('name');

        if ($name !== null) {
            return $name;
        }

        if ($this->isInteractiveInput()) {
            return trim(text(
                label: 'Node name',
                required: true,
                validate: fn (string $value): ?string => $this->validatePromptNodeName($value, $callerRole),
            ));
        }

        return null;
    }

    private function validatePromptNodeName(string $value, string $callerRole): ?string
    {
        $name = trim($value);

        if ($name === '') {
            return 'Node name is required.';
        }

        if ($callerRole !== 'gateway') {
            return null;
        }

        $exists = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->exists();

        return $exists ? null : "Node '{$name}' not found.";
    }

    private function resolveAgentIde(string $callerRole, NodeAgentIdeDefaults $defaults): ?string
    {
        $agentIde = $this->stringArgument('agent_ide');

        if ($agentIde !== null) {
            return $agentIde;
        }

        if ($this->isInteractiveInput()) {
            return select(
                label: 'Agent IDE adapter',
                options: $this->agentIdeChoices($callerRole, $defaults),
                required: true,
            );
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function agentIdeChoices(string $callerRole, NodeAgentIdeDefaults $defaults): array
    {
        if ($callerRole !== 'control') {
            return $defaults->supportedAdapters();
        }

        /** @var AgentIdeAdapterChoicesResponse $dto */
        $dto = app(GatewayConnector::class)
            ->send(new ListAgentIdeAdaptersRequest('node'))
            ->dto();

        return $dto->supportedInputs();
    }

    private function forwardSet(string $name, string $agentIde): int
    {
        try {
            /** @var NodeAgentIdeResponse $dto */
            $dto = app(GatewayConnector::class)
                ->send(new SetNodeAgentIdeRequest($name, $agentIde))
                ->dto();
        } catch (GatewayApiException $e) {
            return $this->failCommand(
                code: $e->errorCode() ?? 'gateway_unavailable',
                message: $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Gateway connection is required to update node configuration.',
                meta: $e->errorMeta(),
            );
        } catch (Throwable) {
            return $this->failCommand(
                code: 'gateway_unavailable',
                message: 'Gateway connection is required to update node configuration.',
                meta: [],
            );
        }

        return $this->respondSuccess([
            'name' => $dto->name,
            'agent_ide' => $dto->agentIde,
            'action' => $dto->action,
        ]);
    }

    private function callerRole(): string
    {
        $localRole = Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->value('role');

        if (! is_string($localRole) || $localRole === '') {
            return 'control';
        }

        if (! in_array($localRole, ['gateway', 'app', 'control'], true)) {
            return 'unknown';
        }

        return $localRole;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hasConfiguredGateway(): bool
    {
        return LocalGatewaySettings::query()
            ->whereNotNull('gateway_url')
            ->where('gateway_url', '!=', '')
            ->exists();
    }

    private function isInteractiveInput(): bool
    {
        return ! $this->wantsJson() && $this->input->isInteractive();
    }

    /**
     * @param  array{
     *     name: string,
     *     agent_ide: array{adapter: string|null, source: string},
     *     action: string
     * }  $data
     */
    private function respondSuccess(array $data): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $name = $data['name'];
        $adapter = $data['agent_ide']['adapter'];

        if ($adapter === null) {
            $this->line("Node '{$name}' agent IDE cleared");

            return self::SUCCESS;
        }

        if ($data['action'] === 'converged') {
            $this->line("Node '{$name}' agent IDE already set to '{$adapter}'");

            return self::SUCCESS;
        }

        $this->line("Node '{$name}' agent IDE set to '{$adapter}'");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => empty($meta) ? (object) [] : $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
