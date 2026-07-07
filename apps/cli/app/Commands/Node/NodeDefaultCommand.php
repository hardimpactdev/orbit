<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\Concerns\WithStepTree;
use App\Commands\LocalOnlyCommand;
use App\Services\Node\NodeDefaultActions;
use App\Services\OrbitConfigStore;
use RuntimeException;

final class NodeDefaultCommand extends LocalOnlyCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'node:default
        {name? : Node to set as the local default}
        {--clear : Clear the local default}
        {--json}';

    #[\Override]
    protected $description = 'Choose, show, set, or clear the local default node.';

    public function handle(NodeDefaultActions $actions): int
    {
        $name = $this->stringArgument('name');
        $clear = (bool) $this->option('clear');

        if ($name !== null && $clear) {
            return $this->renderFailure(
                'validation_failed',
                'Provide only one node target.',
                ['fields' => ['name', 'clear']],
            );
        }

        if ($clear) {
            return $this->handleClear($actions);
        }

        if ($name !== null) {
            return $this->handleSet($actions, $name);
        }

        if ($this->isInteractive()) {
            return $this->handleChoose($actions);
        }

        return $this->handleShow($actions);
    }

    private function handleShow(NodeDefaultActions $actions): int
    {
        $result = $actions->show();

        if ($this->wantsJson()) {
            return $this->renderSuccess([
                'action' => $result['action'],
                'default_node' => $result['default_node'],
            ]);
        }

        $default = $result['default_node'];

        if ($default === null) {
            $this->line('No default node is set.');
            $this->line('Run `orbit node:default <name>` to set one.');

            return self::SUCCESS;
        }

        $this->line("Default node: {$default['name']}");

        return self::SUCCESS;
    }

    private function handleSet(NodeDefaultActions $actions, string $name): int
    {
        if ($this->wantsJson()) {
            $result = $actions->set($name);

            if (array_key_exists('code', $result)) {
                /** @var array{code: string, message: string, meta: array<string, mixed>} $result */
                return $this->renderFailure($result['code'], $result['message'], $result['meta']);
            }

            /** @var array{action: string, default_node: array{name: string, role: string|null}, meta: array<string, mixed>} $result */
            return $this->renderSuccess([
                'action' => $result['action'],
                'default_node' => $result['default_node'],
            ]);
        }

        return $this->renderSetTree($actions, $name);
    }

    private function renderSetTree(NodeDefaultActions $actions, string $name): int
    {
        $outcome = $this->runStepOperation(
            'Setting Default Node',
            [
                ['label' => 'Load visible nodes', 'doneLabel' => 'Loaded visible nodes'],
                ['label' => 'Store local default', 'doneLabel' => 'Stored local default'],
            ],
            work: function () use ($actions, $name): void {
                $result = $actions->set($name);

                if (array_key_exists('code', $result)) {
                    /** @var array{code: string, message: string, meta: array<string, mixed>} $result */
                    throw new RuntimeException($result['message']);
                }
            },
            doneFooter: "Default node set to {$name}.",
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    private function handleClear(NodeDefaultActions $actions): int
    {
        $result = $actions->clear();

        if ($this->wantsJson()) {
            return $this->renderSuccess([
                'action' => $result['action'],
                'default_node' => $result['default_node'],
            ], $result['meta']);
        }

        if ($result['meta']['was_set']) {
            $this->line('Default node cleared.');
        } else {
            $this->line('No default node was set.');
        }

        return self::SUCCESS;
    }

    private function handleChoose(NodeDefaultActions $actions): int
    {
        $nodesOrError = $actions->fetchVisibleNodes();

        if (array_key_exists('code', $nodesOrError)) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $nodesOrError */
            return $this->renderFailure($nodesOrError['code'], $nodesOrError['message'], $nodesOrError['meta']);
        }

        /** @var list<array{name: string, role: string|null}> $nodesOrError */
        if ($nodesOrError === []) {
            return $this->renderFailure(
                'node.not_found',
                'No nodes found.',
                [],
            );
        }

        $choices = array_column($nodesOrError, 'name');
        $currentDefault = app(OrbitConfigStore::class)->defaultNode();
        $default = $currentDefault !== null && in_array($currentDefault, $choices, true)
            ? $currentDefault
            : $choices[0];

        $selected = $this->choice('Select the default node', $choices, $default);

        return $this->handleSet($actions, (string) $selected);
    }

    private function isInteractive(): bool
    {
        if ($this->wantsJson()) {
            return false;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        if (app()->runningUnitTests()) {
            return false;
        }

        return function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
