<?php

declare(strict_types=1);

namespace App\Commands\Node;

use App\Commands\LocalOnlyCommand;
use App\Services\Node\NodeDefaultActions;
use App\Services\OrbitConfigStore;

final class NodeDefaultCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'node:default
        {name? : Development node to set as the local default}
        {--clear : Clear the local default}
        {--json}';

    #[\Override]
    protected $description = 'Choose, show, set, or clear the local default development node.';

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

        return $this->renderSuccess([
            'action' => $result['action'],
            'default_node' => $result['default_node'],
        ]);
    }

    private function handleSet(NodeDefaultActions $actions, string $name): int
    {
        $result = $actions->set($name);

        if (isset($result['code'])) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $result */
            return $this->renderFailure($result['code'], $result['message'], $result['meta']);
        }

        /** @var array{action: string, default_node: array{name: string, role: string}, meta: array<string, mixed>} $result */
        return $this->renderSuccess([
            'action' => $result['action'],
            'default_node' => $result['default_node'],
        ]);
    }

    private function handleClear(NodeDefaultActions $actions): int
    {
        $result = $actions->clear();

        return $this->renderSuccess([
            'action' => $result['action'],
            'default_node' => $result['default_node'],
        ], $result['meta']);
    }

    private function handleChoose(NodeDefaultActions $actions): int
    {
        $nodesOrError = $actions->fetchDevelopmentNodes();

        if (isset($nodesOrError['code'])) {
            /** @var array{code: string, message: string, meta: array<string, mixed>} $nodesOrError */
            return $this->renderFailure($nodesOrError['code'], $nodesOrError['message'], $nodesOrError['meta']);
        }

        /** @var list<array{name: string, role: string}> $nodesOrError */
        if ($nodesOrError === []) {
            return $this->renderFailure(
                'node.not_found',
                'No development app nodes found.',
                [],
            );
        }

        $choices = array_column($nodesOrError, 'name');
        $currentDefault = app(OrbitConfigStore::class)->defaultNode();
        $default = $currentDefault !== null && in_array($currentDefault, $choices, true)
            ? $currentDefault
            : $choices[0];

        $selected = $this->choice('Select the default development app node', $choices, $default);

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
