<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\SwampRunReader;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Support\ValidatedData;
use RuntimeException;

/** Reads Swamp's own history; no execution or worker credentials. */
final readonly class WebSocketSwampRunReader implements SwampRunReader
{
    public function __construct(
        private WebSocketClient $socket,
        private string $address,
        #[\SensitiveParameter] private string $token,
        private string $caPath,
    ) {}

    public function read(int $groupId): array
    {
        if ($this->token === '' || preg_match('/[\r\n]/', $this->token.$this->address) === 1) {
            throw new RuntimeException('Swamp reader is not configured.');
        }
        $deadline = microtime(true) + 5.0;
        try {
            $this->socket->connect(new WebSocketEndpoint($this->address, 443, 'swamp.orbit', '/', $this->caPath, $this->token), 2.0);
            $search = $this->request('workflow.run.search', ['inputs' => ['group' => (string) $groupId], 'limit' => 5], $deadline);
            $data = $search['data'] ?? null;
            $results = is_array($data) ? ($data['results'] ?? null) : null;
            if (! is_array($results) || ! array_is_list($results) || count($results) > 5) {
                throw new RuntimeException('Invalid Swamp run search.');
            }
            $runs = [];
            foreach ($results as $item) {
                if (! is_array($item) || ! is_string($item['runId'] ?? null) || ! $this->belongsTo($item, $groupId)) {
                    throw new RuntimeException('Invalid Swamp group match.');
                }
                $id = $item['runId'];
                $reply = $this->request('workflow.history.get', ['workflowIdOrName' => $id], $deadline);
                $run = $reply['data'] ?? null;
                if (! is_array($run) || ($run['id'] ?? null) !== $id || ! $this->belongsTo($run, $groupId) || ! is_array($run['jobs'] ?? null)) {
                    throw new RuntimeException('Invalid Swamp run details.');
                }
                $logs = $this->request('workflow.history.logs', ['runIdOrWorkflow' => $id, 'tail' => 200], $deadline);
                $data = $logs['data'] ?? null;
                if (! is_array($data) || ! in_array($data['type'] ?? null, ['log', 'no_log_file', 'empty_log'], true)) {
                    throw new RuntimeException('Invalid Swamp log reply.');
                }
                $log = $data['log'] ?? null;
                if ($data['type'] === 'log' && ! is_array($log)) {
                    throw new RuntimeException('Missing Swamp log.');
                }
                $lines = is_array($log) && $data['type'] === 'log' ? ($log['lines'] ?? null) : [];
                if (! is_array($lines) || ! array_is_list($lines) || count(array_filter($lines, is_string(...))) !== count($lines)) {
                    throw new RuntimeException('Invalid Swamp log lines.');
                }
                $run['log'] = implode("\n", $lines);
                $run['log_truncated'] = count($lines) >= 200;
                $runs[] = ValidatedData::object($run);
            }

            return ['runs' => $runs, 'truncated' => ($search['total'] ?? count($runs)) > count($runs)];
        } finally {
            $this->socket->close();
        }
    }

    /** @param array<array-key, mixed> $run */
    private function belongsTo(array $run, int $groupId): bool
    {
        $inputs = $run['inputs'] ?? null;
        $group = is_array($inputs) ? ($inputs['group'] ?? null) : null;

        return (is_string($group) || is_int($group)) && (string) $group === (string) $groupId;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $type, array $payload, float $deadline): array
    {
        $id = bin2hex(random_bytes(8));
        $this->socket->send(['type' => $type, 'id' => $id, 'payload' => $payload]);
        while ($this->socket->isConnected() && ($remaining = $deadline - microtime(true)) > 0) {
            foreach ($this->socket->receive(min(0.2, $remaining)) as $message) {
                if (($message['id'] ?? null) !== $id) {
                    continue;
                }
                if (($message['type'] ?? null) !== $type || ! is_array($message['payload'] ?? null)) {
                    throw new RuntimeException('Swamp read refused.');
                }

                return ValidatedData::object($message['payload']);
            }
        }
        throw new RuntimeException('Swamp read did not complete.');
    }
}
