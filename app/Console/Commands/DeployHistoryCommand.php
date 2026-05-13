<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersDeployResponses;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Deploy\ListDeployHistoryRequest;
use App\Http\Gateway\Responses\Deploy\DeployResponse;
use App\Services\Deploy\DeployManager;
use App\Services\Nodes\CallerRoleResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

#[Signature('deploy:history
    {app? : Production app name or domain}
    {--limit=50 : Number of runs to return}
    {--json : Output JSON}')]
#[Description('List deployment runs for a production app')]
class DeployHistoryCommand extends Command
{
    use RendersDeployResponses;

    public function handle(DeployManager $deploy, CallerRoleResolver $roles): int
    {
        $callerRole = $roles->resolve();

        $app = $this->stringArgument('app');

        if ($app === null) {
            return $this->failCommand('validation_failed', 'App is required.', ['field' => 'app']);
        }

        $limit = $this->positiveIntOption('limit', 50);

        if ($limit === false) {
            return $this->failCommand('validation_failed', 'Invalid value for --limit: must be a positive integer.', ['field' => 'limit', 'value' => (string) $this->option('limit')]);
        }

        try {
            if ($callerRole !== 'gateway') {
                /** @var DeployResponse $dto */
                $dto = app(GatewayConnector::class)
                    ->send(new ListDeployHistoryRequest($app, $limit))
                    ->dto();

                $result = [
                    'runs' => $dto->data['runs'] ?? [],
                    'meta' => $dto->meta,
                ];
            } else {
                $result = $deploy->history($app, $limit);
            }
        } catch (GatewayApiException $exception) {
            return $this->failCommand($exception->errorCode() ?? 'gateway_unavailable', $exception->getMessage(), $exception->errorMeta(), $exception->errorData());
        } catch (\Throwable) {
            return $this->failCommand('gateway_unavailable', 'Gateway connection is required to list deployment history.', []);
        }

        if ($this->wantsJson()) {
            return $this->jsonSuccess(['runs' => $result['runs']], $result['meta']);
        }

        if ($result['runs'] === []) {
            $this->line("No deployment runs found for {$app}.");

            return self::SUCCESS;
        }

        table(
            headers: ['ID', 'STATUS', 'EXIT', 'STARTED'],
            rows: array_map(fn (array $run): array => [
                $run['id'],
                $run['status'],
                $run['exit_code'] ?? '',
                $run['started_at'] ?? '',
            ], $result['runs']),
        );

        return self::SUCCESS;
    }
}
