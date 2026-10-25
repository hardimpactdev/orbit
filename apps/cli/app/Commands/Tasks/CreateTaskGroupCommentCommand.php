<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupCommentRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentResponse;

final class CreateTaskGroupCommentCommand extends TaskCommand
{
    /** @var array<string, string> */
    private const array TYPES = [
        'assistance_requested' => 'assistance_requested: ask for help after all subtasks end',
        'resolution' => 'resolution: record an answer to group direction',
    ];

    #[\Override]
    protected $signature = 'tasks:group:comment:create
        {group? : Numeric task group ID}
        {--type= : assistance_requested or resolution}
        {--body= : Comment text}
        {--author= : Who wrote the comment}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Record group assistance or an answer after all subtasks end; start no work.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $type = $this->choiceInput($this->option('type'), 'Comment type', 'comment_type', array_keys(self::TYPES));

        if ($type === false) {
            return self::FAILURE;
        }

        $body = $this->textInput($this->option('body'), 'Body', 'comment_body', self::COMMENT_BODY_MAX);

        if ($body === false) {
            return self::FAILURE;
        }

        $author = $this->textInput($this->option('author'), 'Author', 'comment_author', self::AUTHOR_MAX);

        if ($author === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groupId ??= $this->selectGroup($connector);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $type ??= $this->promptChoice('Comment type', self::TYPES);
        $body ??= $this->promptText('Body', self::COMMENT_BODY_MAX, multiline: true);
        $author ??= $this->promptText('Author', self::AUTHOR_MAX, default: self::localUser());

        $comment = $this->sendWithProgress(
            $connector,
            new CreateTaskGroupCommentRequest($groupId, $type, $body, $author),
            TaskCommentResponse::class,
            ['Create comment', 'Creating comment', 'Created comment'],
        );

        if (! $comment instanceof TaskCommentResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($comment->toArray());

            return self::SUCCESS;
        }

        $this->writeComment($comment);
        $this->writeHumanMessage("Request ID: {$comment->requestId}");

        return self::SUCCESS;
    }
}
