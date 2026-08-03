<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolScriptDispatcher;
use Orbit\Core\Tools\ToolRunScriptAction;

/**
 * Architecture inventory: production gateway tool run-script action literals
 * must be accepted by the shared core contract, and unknown actions fail closed
 * at the gateway dispatcher before transport.
 */
it('accepts every production ToolScriptDispatcher action literal', function (): void {
    $root = dirname(__DIR__, 4).'/app';
    $dispatched = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (! is_string($contents) || ! str_contains($contents, 'ToolScriptDispatcher')) {
            continue;
        }

        // Named-parameter dispatches: action: 'probe' / action: "preflight"
        if (preg_match_all("/\\baction:\\s*'([a-z0-9-]+)'/", $contents, $named) > 0) {
            foreach ($named[1] as $action) {
                $dispatched[$action] = true;
            }
        }

        // Positional ToolScriptDispatcher-style calls:
        // $this->scripts->run($node, 'tool', 'action', $script)
        // $this->scripts()->run($node, 'tool', 'action', $script)
        // $this->toolScriptDispatcher()->run($node, 'tool', 'action', $script)
        // $this->scriptDispatcher()->run($node, 'tool', 'action', $script)
        if (preg_match_all(
            "/(?:scripts|toolScriptDispatcher|scriptDispatcher)\\(\\)->run\\(\\s*[^,]+,\\s*'[^']+',\\s*'([a-z0-9-]+)'/s",
            $contents,
            $positional,
        ) > 0) {
            foreach ($positional[1] as $action) {
                $dispatched[$action] = true;
            }
        }

        if (preg_match_all(
            "/scripts->run\\(\\s*[^,]+,\\s*'[^']+',\\s*'([a-z0-9-]+)'/s",
            $contents,
            $property,
        ) > 0) {
            foreach ($property[1] as $action) {
                $dispatched[$action] = true;
            }
        }
    }

    $dispatchedActions = array_keys($dispatched);
    sort($dispatchedActions);

    expect($dispatchedActions)->not->toBeEmpty();

    foreach ($dispatchedActions as $action) {
        expect(ToolRunScriptAction::isAllowed($action))
            ->toBeTrue("Dispatched tool run-script action '{$action}' must be on ToolRunScriptAction.");
    }

    // Fail closed for anything outside the shared contract.
    expect(ToolRunScriptAction::isAllowed('not-a-dispatched-action'))->toBeFalse()
        ->and(ToolRunScriptAction::values())
        ->toContain('preflight')
        ->toContain('probe-php-cli')
        ->toContain('logs');
});

it('rejects unknown actions through ToolScriptDispatcher before transport', function (): void {
    $dispatcher = new ToolScriptDispatcher(
        new class implements RunsInternalCommands
        {
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
