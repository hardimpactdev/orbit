<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloMutatingOperationCatalog
{
    /**
     * @return list<SoloMutatingOperationDefinition>
     *
     * @mago-expect lint:halstead
     */
    public static function all(): array
    {
        return [
            self::op(
                'solo:project:create',
                'solo:project:create {name? : Project name} {--json : Output JSON}',
                'POST',
                'project/create',
                'project',
                ['name'],
            ),
            self::op(
                'solo:project:rename',
                'solo:project:rename {project? : Project name or id} {--name= : New project name} {--json : Output JSON}',
                'PATCH',
                'project/rename',
                'project',
                ['project'],
                ['name' => 'name'],
            ),
            self::op(
                'solo:project:select',
                'solo:project:select {project? : Project name or id} {--json : Output JSON}',
                'POST',
                'project/select',
                'project',
                ['project'],
            ),
            self::op(
                'solo:project:delete',
                'solo:project:delete {project? : Project name or id} {--force : Confirm destructive action} {--json : Output JSON}',
                'DELETE',
                'project/delete',
                'project',
                ['project'],
                forceRequired: true,
                destructiveConsent: true,
            ),
            self::op(
                'solo:process:input',
                'solo:process:input {process? : Process name or id} {--input= : Input to send} {--json : Output JSON}',
                'POST',
                'process/input',
                'process',
                ['process'],
                ['input' => 'input'],
            ),
            self::op(
                'solo:process:spawn',
                'solo:process:spawn {processCommand? : Command to spawn} {--project= : Project name} {--json : Output JSON}',
                'POST',
                'process/spawn',
                'process',
                ['processCommand'],
                ['project' => 'project'],
            ),
            self::processLifecycle('solo:process:start', 'start'),
            self::processLifecycle('solo:process:stop', 'stop', forceRequired: true),
            self::processLifecycle('solo:process:restart', 'restart', forceRequired: true),
            self::processLifecycle('solo:process:clear-output', 'clear-output', forceRequired: true),
            self::op(
                'solo:process:rename',
                'solo:process:rename {process? : Process name or id} {--name= : New process name} {--json : Output JSON}',
                'PATCH',
                'process/rename',
                'process',
                ['process'],
                ['name' => 'name'],
            ),
            self::processLifecycle(
                'solo:process:close',
                'close',
                forceRequired: true,
                destructiveConsent: true,
            ),
            self::op(
                'solo:scratchpad:create',
                'solo:scratchpad:create {name? : Scratchpad name} {--content= : Initial content} {--json : Output JSON}',
                'POST',
                'scratchpad/create',
                'scratchpad',
                ['name'],
                ['content' => 'content'],
            ),
            self::scratchpadWrite('solo:scratchpad:write', 'write', 'PUT', [
                'content' => 'content',
                'expected-revision' => 'expected_revision',
            ]),
            self::scratchpadWrite('solo:scratchpad:append', 'append', 'POST', [
                'content' => 'content',
                'expected-revision' => 'expected_revision',
            ]),
            self::scratchpadWrite('solo:scratchpad:append-section', 'append-section', 'POST', [
                'heading' => 'heading',
                'content' => 'content',
                'expected-revision' => 'expected_revision',
            ]),
            self::scratchpadWrite('solo:scratchpad:edit', 'edit', 'PATCH', [
                'search' => 'search',
                'replace' => 'replace',
                'expected-revision' => 'expected_revision',
            ]),
            self::scratchpadWrite('solo:scratchpad:rename', 'rename', 'PATCH', [
                'name' => 'name',
                'expected-revision' => 'expected_revision',
            ]),
            self::scratchpadWrite('solo:scratchpad:archive', 'archive', 'POST', [], forceRequired: true),
            self::scratchpadWrite(
                'solo:scratchpad:clear',
                'clear',
                'DELETE',
                [],
                forceRequired: true,
                destructiveConsent: true,
            ),
            self::scratchpadWrite(
                'solo:scratchpad:delete',
                'delete',
                'DELETE',
                [],
                forceRequired: true,
                destructiveConsent: true,
            ),
            self::op(
                'solo:todo:create',
                'solo:todo:create {title? : Todo title} {--project= : Project id or name} {--body= : Todo body} {--json : Output JSON}',
                'POST',
                'todo/create',
                'todo',
                ['title'],
                ['project' => 'project', 'body' => 'body'],
            ),
            self::todo('solo:todo:update', 'update', 'PATCH', ['title' => 'title', 'body' => 'body']),
            self::todo('solo:todo:complete', 'complete', 'POST'),
            self::todo('solo:todo:reopen', 'reopen', 'POST'),
            self::todo(
                'solo:todo:delete',
                'delete',
                'DELETE',
                forceRequired: true,
                destructiveConsent: true,
            ),
            self::todo('solo:todo:lock', 'lock', 'POST'),
            self::todo('solo:todo:unlock', 'unlock', 'POST'),
            self::op(
                'solo:todo:comment:add',
                'solo:todo:comment:add {todo? : Todo id} {--body= : Comment body} {--json : Output JSON}',
                'POST',
                'todo/comment/add',
                'comment',
                ['todo'],
                ['body' => 'body'],
            ),
            self::op(
                'solo:todo:comment:update',
                'solo:todo:comment:update {comment? : Comment id} {--body= : Comment body} {--json : Output JSON}',
                'PATCH',
                'todo/comment/update',
                'comment',
                ['comment'],
                ['body' => 'body'],
            ),
            self::op(
                'solo:todo:comment:delete',
                'solo:todo:comment:delete {comment? : Comment id} {--force : Confirm destructive action} {--json : Output JSON}',
                'DELETE',
                'todo/comment/delete',
                'comment',
                ['comment'],
                forceRequired: true,
                destructiveConsent: true,
            ),
            self::op(
                'solo:lock:acquire',
                'solo:lock:acquire {name? : Lock name} {--json : Output JSON}',
                'POST',
                'lock/acquire',
                'lock',
                ['name'],
            ),
            self::op(
                'solo:lock:release',
                'solo:lock:release {name? : Lock name} {--json : Output JSON}',
                'DELETE',
                'lock/release',
                'lock',
                ['name'],
            ),
            self::op(
                'solo:timer:set',
                'solo:timer:set {name? : Timer name} {--seconds= : Timer seconds} {--json : Output JSON}',
                'POST',
                'timer/set',
                'timer',
                ['name'],
                ['seconds' => 'seconds'],
            ),
            self::timer('solo:timer:cancel', 'cancel', 'DELETE'),
            self::timer('solo:timer:pause', 'pause', 'POST'),
            self::timer('solo:timer:resume', 'resume', 'POST'),
        ];
    }

    public static function find(string $command): ?SoloMutatingOperationDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->command === $command) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $requiredArguments
     * @param  array<string, string>  $payloadOptions
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private static function op(
        string $command,
        string $signature,
        string $method,
        string $path,
        string $successKey,
        array $requiredArguments = [],
        array $payloadOptions = [],
        bool $forceRequired = false,
        bool $destructiveConsent = false,
    ): SoloMutatingOperationDefinition {
        return new SoloMutatingOperationDefinition(
            command: $command,
            signature: $signature,
            method: $method,
            gatewayPath: '/api/solo/'.$path,
            successKey: $successKey,
            requiredArguments: $requiredArguments,
            payloadOptions: $payloadOptions,
            forceRequired: $forceRequired,
            destructiveConsent: $destructiveConsent,
        );
    }

    private static function processLifecycle(
        string $command,
        string $action,
        bool $forceRequired = false,
        bool $destructiveConsent = false,
    ): SoloMutatingOperationDefinition {
        return self::op(
            $command,
            "solo:process:{$action} {process? : Process name or id} {--force : Confirm destructive action} {--json : Output JSON}",
            'POST',
            "process/{$action}",
            'process',
            ['process'],
            forceRequired: $forceRequired,
            destructiveConsent: $destructiveConsent,
        );
    }

    /**
     * @param  array<string, string>  $payloadOptions
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private static function scratchpadWrite(
        string $command,
        string $action,
        string $method,
        array $payloadOptions,
        bool $forceRequired = false,
        bool $destructiveConsent = false,
    ): SoloMutatingOperationDefinition {
        $options = '{--content= : Content} {--heading= : Section heading} {--search= : Search text} {--replace= : Replacement text} {--name= : New name} {--expected-revision= : Expected scratchpad revision} {--force : Confirm destructive action} {--json : Output JSON}';

        return self::op(
            $command,
            "solo:scratchpad:{$action} {scratchpad? : Scratchpad name or id} {$options}",
            $method,
            "scratchpad/{$action}",
            'scratchpad',
            ['scratchpad'],
            $payloadOptions,
            $forceRequired,
            $destructiveConsent,
        );
    }

    /**
     * @param  array<string, string>  $payloadOptions
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private static function todo(
        string $command,
        string $action,
        string $method,
        array $payloadOptions = [],
        bool $forceRequired = false,
        bool $destructiveConsent = false,
    ): SoloMutatingOperationDefinition {
        return self::op(
            $command,
            "solo:todo:{$action} {todo? : Todo id} {--project= : Project id or name} {--title= : Todo title} {--body= : Todo body} {--force : Confirm destructive action} {--json : Output JSON}",
            $method,
            "todo/{$action}",
            'todo',
            ['todo'],
            ['project' => 'project'] + $payloadOptions,
            $forceRequired,
            $destructiveConsent,
        );
    }

    private static function timer(string $command, string $action, string $method): SoloMutatingOperationDefinition
    {
        return self::op(
            $command,
            "solo:timer:{$action} {timer? : Timer id or name} {--json : Output JSON}",
            $method,
            "timer/{$action}",
            'timer',
            ['timer'],
        );
    }
}
