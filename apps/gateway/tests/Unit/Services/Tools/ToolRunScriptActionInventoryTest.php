<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use Orbit\Core\Tools\ToolRunScriptAction;

/**
 * @return list<string>
 */
function toolRunScriptDispatchedActionsFromGatewayApp(): array
{
    $root = dirname(__DIR__, 4).'/app';
    $dispatched = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    )) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents) || ! str_contains($contents, 'ToolScriptDispatcher')) {
            continue;
        }

        if (preg_match_all("/\\baction:\\s*'([a-z0-9-]+)'/", $contents, $named) > 0) {
            foreach ($named[1] as $action) {
                $dispatched[$action] = true;
            }
        }

        if (
            preg_match_all(
                "/(?:scripts|toolScriptDispatcher|scriptDispatcher)\\(\\)->run\\(\\s*[^,]+,\\s*'[^']+',\\s*'([a-z0-9-]+)'/s",
                $contents,
                $positional,
            ) > 0
        ) {
            foreach ($positional[1] as $action) {
                $dispatched[$action] = true;
            }
        }

        if (
            preg_match_all(
                "/scripts->run\\(\\s*[^,]+,\\s*'[^']+',\\s*'([a-z0-9-]+)'/s",
                $contents,
                $property,
            ) > 0
        ) {
            foreach ($property[1] as $action) {
                $dispatched[$action] = true;
            }
        }
    }

    $actions = array_keys($dispatched);
    sort($actions);

    return $actions;
}

it('accepts every production ToolScriptDispatcher action literal', function (): void {
    $dispatchedActions = toolRunScriptDispatchedActionsFromGatewayApp();

    expect($dispatchedActions)->not->toBeEmpty();

    foreach ($dispatchedActions as $action) {
        expect(ToolRunScriptAction::isAllowed($action))
            ->toBeTrue("Dispatched tool run-script action '{$action}' must be on ToolRunScriptAction.");
    }

    expect(ToolRunScriptAction::isAllowed('not-a-dispatched-action'))
        ->toBeFalse()
        ->and(ToolRunScriptAction::values())
        ->toContain('preflight')
        ->toContain('probe-php-cli')
        ->toContain('logs');
});

it('rejects unknown actions through ToolScriptDispatcher before transport', function (): void {
    $dispatcher = new ToolScriptDispatcher(
        new class implements RunsInternalCommands {
            public function runInternal(
                Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                throw new RuntimeException('transport must not run for unknown actions');
            }
        },
    );

    $node = new Node(['name' => 'agent-1']);

    expect(fn () => $dispatcher->run($node, 'openclaw', 'not-a-real-action', 'printf ok'))
        ->toThrow(InvalidArgumentException::class, "Tool run payload action 'not-a-real-action' is invalid.");
});
