<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Concerns\WithSpinner;
use App\Concerns\WithStepTree;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\select;

#[Signature('node:default
    {name? : Visible development app node name}
    {--clear : Clear the local default node}
    {--json : Output as JSON}')]
#[Description('Choose, show, set, or clear the local default development app node')]
class NodeDefaultCommand extends Command
{
    use WithSpinner;
    use WithStepTree;

    public function handle(): int
    {
        $callerRole = (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';

        if ($callerRole === 'gateway') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control node.',
                meta: ['caller_role' => $callerRole],
            );
        }

        $name = $this->argument('name');
        $clear = (bool) $this->option('clear');

        if (is_string($name) && $name === '') {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Node name cannot be empty.',
                meta: ['field' => 'name'],
            );
        }

        $name = $this->stringArgument('name');

        if ($name !== null && $clear) {
            return $this->failCommand(
                code: 'validation_failed',
                message: 'Cannot provide both a node name and --clear.',
                meta: ['fields' => ['name', 'clear']],
            );
        }

        if ($clear) {
            return $this->clearDefault();
        }

        if ($name !== null) {
            return $this->setDefault($name);
        }

        if ($this->isInteractiveInput()) {
            return $this->chooseDefault();
        }

        return $this->showDefault();
    }

    private function showDefault(): int
    {
        $defaultNode = $this->readDefaultNode();

        if ($defaultNode === null) {
            return $this->successResponse(
                action: 'show',
                defaultNode: null,
                meta: [],
                humanRenderer: function (): void {
                    $this->line('No default development app node is set.');
                    $this->line('Run `orbit node:default <name>` to set one.');
                },
            );
        }

        return $this->successResponse(
            action: 'show',
            defaultNode: [
                'name' => $defaultNode,
                'role' => 'app',
                'environment' => 'development',
            ],
            meta: [],
            humanRenderer: fn () => $this->line("Default development app node: {$defaultNode}"),
        );
    }

    private function setDefault(string $name): int
    {
        $validation = $this->validateTargetNode($name);
        if ($validation !== null) {
            return $validation;
        }

        if (! $this->wantsJson()) {
            $exitCode = $this->runStepTree(
                'Set Default Node',
                [
                    [
                        'label' => 'Load visible development app nodes',
                        'doneLabel' => 'Loaded visible development app nodes',
                        'run' => fn (): string => 'validated',
                    ],
                    [
                        'label' => 'Store local default',
                        'doneLabel' => 'Stored local default',
                        'run' => function () use ($name): string {
                            $this->writeDefaultNode($name);

                            return $name;
                        },
                    ],
                ],
                doneFooter: "Default development app node set to {$name}",
                failFooter: 'Failed to set default development app node',
            );

            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        } else {
            $this->writeDefaultNode($name);
        }

        return $this->successResponse(
            action: 'set',
            defaultNode: [
                'name' => $name,
                'role' => 'app',
                'environment' => 'development',
            ],
            meta: [],
            humanRenderer: fn (): null => null,
        );
    }

    private function chooseDefault(): int
    {
        try {
            $nodes = $this->fetchDevelopmentAppNodes();
        } catch (RuntimeException) {
            return $this->failGatewayUnavailable();
        }

        if ($nodes === []) {
            return $this->failCommand(
                code: 'node.not_found',
                message: 'No development app nodes found.',
                meta: [],
            );
        }

        $choices = collect($nodes)->mapWithKeys(fn (array $n): array => [
            $n['name'] => "{$n['name']} ({$n['environment']})",
        ])->all();

        $currentDefault = $this->readDefaultNode();
        $defaultChoice = in_array($currentDefault, array_keys($choices), true) ? $currentDefault : null;

        $selected = select(
            label: 'Default development app node',
            options: $choices,
            default: $defaultChoice,
        );

        return $this->setDefault($selected);
    }

    private function clearDefault(): int
    {
        $wasSet = $this->readDefaultNode() !== null;
        $this->writeDefaultNode(null);

        return $this->successResponse(
            action: 'clear',
            defaultNode: null,
            meta: ['was_set' => $wasSet],
            humanRenderer: fn () => $wasSet
                ? $this->line('Default development app node cleared.')
                : $this->line('No default development app node was set.'),
        );
    }

    private function validateTargetNode(string $name): ?int
    {
        try {
            $nodes = $this->fetchDevelopmentAppNodes();
        } catch (RuntimeException) {
            return $this->failGatewayUnavailable();
        }

        $target = collect($nodes)->firstWhere('name', $name);

        if (is_array($target)) {
            return null;
        }

        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if ($this->hasConfiguredGateway() || ! $node instanceof Node) {
            return $this->failCommand(
                code: 'node.not_found',
                message: "Node '{$name}' not found or not visible.",
                meta: ['name' => $name],
            );
        }

        if ($node->role !== 'app' || $node->environment !== 'development') {
            return $this->failCommand(
                code: 'node.invalid_role',
                message: "Node '{$name}' is not a development app node.",
                meta: [
                    'name' => $name,
                    'role' => $node->role,
                    'required_role' => 'app',
                    'required_environment' => 'development',
                ],
                humanMessage: "Node '{$name}' is not a development app node.\nOnly development app nodes may be set as the local default.",
            );
        }

        return null;
    }

    /**
     * @return list<array{name: string, role: string, environment: string}>
     */
    private function fetchDevelopmentAppNodes(): array
    {
        if ($this->hasConfiguredGateway()) {
            return $this->fetchGatewayDevelopmentAppNodes();
        }

        return $this->fetchLocalDevelopmentAppNodes();
    }

    /**
     * @return list<array{name: string, role: string, environment: string}>
     */
    private function fetchGatewayDevelopmentAppNodes(): array
    {
        try {
            $dto = app(GatewayConnector::class)
                ->send(new ListNodesRequest(role: 'app', environment: 'development'))
                ->dto();
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage() !== '' ? $e->getMessage() : 'Gateway request failed.');
        }

        $nodes = $dto->nodes;

        return collect($nodes)
            ->filter(fn (mixed $node): bool => $this->isDevelopmentAppNodePayload($node))
            ->map(fn (array $node): array => [
                'name' => $node['name'],
                'role' => 'app',
                'environment' => 'development',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name: string, role: string, environment: string}>
     */
    private function fetchLocalDevelopmentAppNodes(): array
    {
        return Node::query()
            ->where('role', 'app')
            ->where('environment', 'development')
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (Node $node): array => [
                'name' => $node->name,
                'role' => $node->role,
                'environment' => $node->environment,
            ])
            ->all();
    }

    private function hasConfiguredGateway(): bool
    {
        $settings = LocalGatewaySettings::query()->first();

        return $settings instanceof LocalGatewaySettings
            && is_string($settings->gateway_url)
            && $settings->gateway_url !== '';
    }

    private function isDevelopmentAppNodePayload(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        return isset($node['name'])
            && is_string($node['name'])
            && $node['name'] !== ''
            && ($node['role'] ?? null) === 'app'
            && ($node['environment'] ?? null) === 'development';
    }

    private function failGatewayUnavailable(): int
    {
        return $this->failCommand(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to set a default node.',
            meta: [],
        );
    }

    private function readDefaultNode(): ?string
    {
        $record = LocalNodeDefault::query()->first();

        if ($record === null) {
            return null;
        }

        $name = $record->default_node_name;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return $name;
    }

    private function writeDefaultNode(?string $name): void
    {
        $record = LocalNodeDefault::query()->first();

        if ($record === null) {
            LocalNodeDefault::query()->create([
                'default_node_name' => $name,
            ]);

            return;
        }

        $record->update(['default_node_name' => $name]);
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isInteractiveInput(): bool
    {
        if ($this->wantsJson()) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        if (app()->runningUnitTests()) {
            return true;
        }

        return function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    /**
     * @param  array<string, mixed>|null  $defaultNode
     * @param  array<string, mixed>  $meta
     */
    private function successResponse(string $action, ?array $defaultNode, array $meta, ?callable $humanRenderer = null): int
    {
        $data = [
            'action' => $action,
            'default_node' => $defaultNode,
        ];

        if ($this->wantsJson()) {
            $payload = [
                'success' => [
                    'data' => $data,
                ],
            ];

            if ($meta !== []) {
                $payload['success']['meta'] = $meta;
            }

            $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($humanRenderer !== null) {
            $humanRenderer();
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta, ?string $humanMessage = null): int
    {
        if ($this->wantsJson()) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'meta' => $meta,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $output = $humanMessage ?? $message;
        $this->error($output);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
