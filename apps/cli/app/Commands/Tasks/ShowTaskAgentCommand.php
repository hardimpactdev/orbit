<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ShowTaskAgentRequest;
use Orbit\Sdk\Responses\Tasks\TaskAgentSnapshotResponse;

final class ShowTaskAgentCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:agent:show
        {group? : Numeric task group ID}
        {session? : Numeric agent thread ID}
        {--after-sequence= : The cursor of a previous read; return only the entries after it}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a redacted read of a task agent thread\'s conversation.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');
        $sessionId = $groupId === false ? false : $this->idArgument('session', 'Agent thread', 'agent_thread');

        if ($groupId === false || $sessionId === false) {
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

        $sessionId ??= $this->selectAgent($connector, $groupId);

        if ($sessionId === null) {
            return self::FAILURE;
        }

        $after = $this->option('after-sequence');
        $request = new ShowTaskAgentRequest($groupId, $sessionId, is_string($after) && $after !== '' ? $after : null);
        $snapshot = $this->sendWithProgress($connector, $request, TaskAgentSnapshotResponse::class, ['Show agent thread', 'Loading agent thread', 'Loaded agent thread']);

        if (! $snapshot instanceof TaskAgentSnapshotResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($snapshot->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Agent thread: '.$snapshot->threadId, [
            'State' => $snapshot->state,
            'Kind' => $snapshot->kind,
            'Source' => $snapshot->archived ? 'saved transcript' : 'live',
            'Cursor' => $snapshot->cursor,
            'Tokens' => self::tokens($snapshot->tokens),
            'Input' => self::tokens($snapshot->inputTokens),
            'Cached' => self::tokens($snapshot->cachedInputTokens),
            'Output' => self::tokens($snapshot->outputTokens),
            'Calls' => self::tokens($snapshot->modelCalls),
            'Peak' => self::tokens($snapshot->peakContextTokens),
            'Line diff' => self::lineDiff(null, $snapshot->linesAdded, $snapshot->linesDeleted),
            'Input requests' => count($snapshot->inputRequests),
            'Error' => $snapshot->error,
            'Provider error' => $snapshot->providerError,
        ]));

        if ($snapshot->entries === []) {
            $this->writeHumanMessage($snapshot->kind === 'delta' ? 'No entries after the cursor.' : 'No entries.');
        }

        foreach ($snapshot->entries as $entry) {
            $this->writeText(implode(' · ', [self::time($entry['at']), $entry['kind'], $entry['label']]), $entry['text']);
        }

        $this->writeHumanMessage("Request ID: {$snapshot->requestId}");

        return self::SUCCESS;
    }
}
