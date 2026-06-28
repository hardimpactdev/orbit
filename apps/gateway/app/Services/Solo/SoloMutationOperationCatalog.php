<?php

declare(strict_types=1);

namespace App\Services\Solo;

final class SoloMutationOperationCatalog
{
    /**
     * @return array<string, SoloMutationOperation>
     *
     * @mago-expect lint:halstead
     */
    public static function all(): array
    {
        return [
            'project/create' => self::op(
                'project/create',
                'POST',
                'solo:project:create',
                '/projects',
                'project',
                ['name'],
                ['name' => 'name'],
            ),
            'project/rename' => self::op(
                'project/rename',
                'PATCH',
                'solo:project:update',
                '/projects/{project}/rename',
                'project',
                ['project'],
                ['name' => 'name'],
            ),
            'project/select' => self::op(
                'project/select',
                'POST',
                'solo:project:update',
                '/projects/{project}/select',
                'project',
                ['project'],
            ),
            'project/delete' => self::op(
                'project/delete',
                'DELETE',
                'solo:project:delete',
                '/projects/{project}',
                'project',
                ['project'],
            ),
            'process/input' => self::op(
                'process/input',
                'POST',
                'solo:process:input',
                '/processes/{process}/input',
                'process',
                ['process'],
                ['input' => 'input'],
            ),
            'process/spawn' => self::op(
                'process/spawn',
                'POST',
                'solo:process:spawn',
                '/processes',
                'process',
                ['command'],
                ['command' => 'command', 'project' => 'project'],
            ),
            'process/start' => self::process('start'),
            'process/stop' => self::process('stop'),
            'process/restart' => self::process('restart'),
            'process/clear-output' => self::process('clear-output'),
            'process/rename' => self::op(
                'process/rename',
                'PATCH',
                'solo:process:lifecycle',
                '/processes/{process}/rename',
                'process',
                ['process'],
                ['name' => 'name'],
            ),
            'process/close' => self::op(
                'process/close',
                'DELETE',
                'solo:process:delete',
                '/processes/{process}',
                'process',
                ['process'],
            ),
            'scratchpad/create' => self::op(
                'scratchpad/create',
                'POST',
                'solo:scratchpad:write',
                '/scratchpads',
                'scratchpad',
                ['name'],
                ['name' => 'name', 'content' => 'content'],
            ),
            'scratchpad/write' => self::op(
                'scratchpad/write',
                'PUT',
                'solo:scratchpad:write',
                '/scratchpads/{scratchpad}',
                'scratchpad',
                ['scratchpad'],
                ['content' => 'content', 'expected_revision' => 'expected_revision'],
            ),
            'scratchpad/append' => self::scratchpad('append', 'POST', [
                'content' => 'content',
                'expected_revision' => 'expected_revision',
            ]),
            'scratchpad/append-section' => self::scratchpad('append-section', 'POST', [
                'heading' => 'heading',
                'content' => 'content',
                'expected_revision' => 'expected_revision',
            ]),
            'scratchpad/edit' => self::scratchpad('edit', 'PATCH', [
                'search' => 'search',
                'replace' => 'replace',
                'expected_revision' => 'expected_revision',
            ]),
            'scratchpad/rename' => self::scratchpad('rename', 'PATCH', [
                'name' => 'name',
                'expected_revision' => 'expected_revision',
            ]),
            'scratchpad/archive' => self::scratchpad('archive', 'POST'),
            'scratchpad/clear' => self::scratchpad('clear', 'DELETE'),
            'scratchpad/delete' => self::scratchpad('delete', 'DELETE'),
            'todo/create' => self::op(
                'todo/create',
                'POST',
                'solo:todo:write',
                '/projects/{project}/todos',
                'todo',
                ['project', 'title'],
                ['title' => 'title', 'body' => 'body'],
            ),
            'todo/update' => self::todo('update', 'PATCH', 'solo:todo:write', ['title' => 'title', 'body' => 'body']),
            'todo/complete' => self::todo('complete', 'POST', 'solo:todo:write'),
            'todo/reopen' => self::todo('reopen', 'POST', 'solo:todo:write'),
            'todo/delete' => self::todo('delete', 'DELETE', 'solo:todo:delete'),
            'todo/lock' => self::todo('lock', 'POST', 'solo:todo:lock'),
            'todo/unlock' => self::todo('unlock', 'POST', 'solo:todo:lock'),
            'todo/comment/add' => self::op(
                'todo/comment/add',
                'POST',
                'solo:todo:comment',
                '/todos/{todo}/comments',
                'comment',
                ['todo'],
                ['body' => 'body'],
            ),
            'todo/comment/update' => self::op(
                'todo/comment/update',
                'PATCH',
                'solo:todo:comment',
                '/comments/{comment}',
                'comment',
                ['comment'],
                ['body' => 'body'],
            ),
            'todo/comment/delete' => self::op(
                'todo/comment/delete',
                'DELETE',
                'solo:todo:comment',
                '/comments/{comment}',
                'comment',
                ['comment'],
            ),
            'lock/acquire' => self::op('lock/acquire', 'POST', 'solo:lock:*', '/locks/{name}', 'lock', ['name']),
            'lock/release' => self::op('lock/release', 'DELETE', 'solo:lock:*', '/locks/{name}', 'lock', ['name']),
            'timer/set' => self::op(
                'timer/set',
                'POST',
                'solo:timer:*',
                '/timers/{name}',
                'timer',
                ['name'],
                ['seconds' => 'seconds'],
            ),
            'timer/cancel' => self::timer('cancel', 'DELETE'),
            'timer/pause' => self::timer('pause', 'POST'),
            'timer/resume' => self::timer('resume', 'POST'),
        ];
    }

    public static function find(string $operation): SoloMutationOperation
    {
        $definition = self::all()[$operation] ?? null;

        if ($definition instanceof SoloMutationOperation) {
            return $definition;
        }

        throw new SoloProxyException(
            errorCode: 'validation_failed',
            message: 'Unknown Solo mutation operation.',
            meta: [
                'operation' => $operation,
                'reason' => 'solo_operation_unknown',
            ],
            status: 422,
        );
    }

    /**
     * @param  list<string>  $requiredFields
     * @param  array<string, string>  $payloadFields
     */
    /**
     * @param  list<string>  $requiredFields
     * @param  array<string, string>  $payloadFields
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private static function op(
        string $apiPath,
        string $method,
        string $permission,
        string $upstreamTemplate,
        string $dataKey,
        array $requiredFields = [],
        array $payloadFields = [],
    ): SoloMutationOperation {
        return new SoloMutationOperation(
            $apiPath,
            $method,
            $permission,
            $upstreamTemplate,
            $dataKey,
            $requiredFields,
            $payloadFields,
        );
    }

    private static function process(string $action): SoloMutationOperation
    {
        return self::op(
            "process/{$action}",
            'POST',
            'solo:process:lifecycle',
            "/processes/{process}/{$action}",
            'process',
            ['process'],
        );
    }

    /**
     * @param  array<string, string>  $payloadFields
     */
    private static function scratchpad(string $action, string $method, array $payloadFields = []): SoloMutationOperation
    {
        return self::op(
            "scratchpad/{$action}",
            $method,
            'solo:scratchpad:write',
            "/scratchpads/{scratchpad}/{$action}",
            'scratchpad',
            ['scratchpad'],
            $payloadFields,
        );
    }

    /**
     * @param  array<string, string>  $payloadFields
     */
    private static function todo(
        string $action,
        string $method,
        string $permission,
        array $payloadFields = [],
    ): SoloMutationOperation {
        return self::op(
            "todo/{$action}",
            $method,
            $permission,
            self::todoTemplate($action),
            'todo',
            ['project', 'todo'],
            $payloadFields,
        );
    }

    private static function todoTemplate(string $action): string
    {
        if ($action === 'delete') {
            return '/projects/{project}/todos/{todo}';
        }

        return "/projects/{project}/todos/{todo}/{$action}";
    }

    private static function timer(string $action, string $method): SoloMutationOperation
    {
        return self::op("timer/{$action}", $method, 'solo:timer:*', "/timers/{timer}/{$action}", 'timer', ['timer']);
    }
}
