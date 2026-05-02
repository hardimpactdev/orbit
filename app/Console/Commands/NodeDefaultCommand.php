<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LocalNodeDefault;
use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;

#[Signature('node:default
    {name? : Visible development app node name}
    {--clear : Clear the local default node}
    {--json : Output as JSON}')]
#[Description('Choose, show, set, or clear the local default development app node')]
class NodeDefaultCommand extends Command
{
    public function handle(): int
    {
        $callerRole = $this->callerRole();

        if ($callerRole === 'app' || $callerRole === 'gateway') {
            return $this->failCommand(
                code: 'caller_role_not_allowed',
                message: 'This command may only be run from a control node.',
                meta: ['caller_role' => $callerRole],
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
                message: 'Provide only one node target.',
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
                humanRenderer: fn () => $this->line("No default development app node is set.\nRun `orbit node:default <name>` to set one."),
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

        $this->writeDefaultNode($name);

        return $this->successResponse(
            action: 'set',
            defaultNode: [
                'name' => $name,
                'role' => 'app',
                'environment' => 'development',
            ],
            meta: [],
            humanRenderer: function () use ($name): void {
                $this->line('┌ Set Default Node');
                $this->line('○ Load visible development app nodes');
                $this->line('○ Store local default');
                $this->line("└ Default development app node set to {$name}.");
            },
        );
    }

    private function chooseDefault(): int
    {
        $nodes = $this->fetchDevelopmentAppNodes();

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
        $node = Node::query()
            ->where('name', $name)
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
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
            );
        }

        return null;
    }

    /**
     * @return list<array{name: string, role: string, environment: string}>
     */
    private function fetchDevelopmentAppNodes(): array
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

    private function isInteractiveInput(): bool
    {
        if ($this->wantsJson()) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return false;
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
    private function failCommand(string $code, string $message, array $meta): int
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

        $this->error($message);

        return self::FAILURE;
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
