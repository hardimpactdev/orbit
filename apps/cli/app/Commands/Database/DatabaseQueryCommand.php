<?php

declare(strict_types=1);

namespace App\Commands\Database;

use App\Exceptions\GatewayApiException;

final class DatabaseQueryCommand extends DatabaseGatewayCommand
{
    #[\Override]
    protected $signature = 'database:query
        {target? : Instance, workspace, or connection slug}
        {--sql= : SQL query to execute}
        {--connection= : Connection slug when the target maps to multiple connections}
        {--write : Allow write SQL}
        {--limit= : Maximum rows returned}
        {--full : Return full row payloads}
        {--timeout= : Query timeout in seconds}
        {--max-json-bytes= : Maximum JSON response size}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Run a database query through the gateway.';

    public function handle(): int
    {
        $target = $this->requiredArgument('target', 'target', 'The query target argument is required.');

        if (is_int($target)) {
            return $target;
        }

        $sql = $this->stringOption('sql');

        if ($sql === null) {
            return $this->failValidation('sql', 'The --sql option is required.');
        }

        try {
            $response = $this->gatewayPost('/api/database-connections/query', $this->filledPayload([
                'target' => $target,
                'sql' => $sql,
                'connection' => $this->stringOption('connection'),
                'write' => $this->option('write') === true,
                'full' => $this->option('full') === true,
                'limit' => $this->stringOption('limit'),
                'timeout' => $this->stringOption('timeout'),
                'max_json_bytes' => $this->stringOption('max-json-bytes'),
            ]));
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        return $this->renderSuccess($response);
    }

    #[\Override]
    protected function wantsJson(): bool
    {
        return true;
    }
}
