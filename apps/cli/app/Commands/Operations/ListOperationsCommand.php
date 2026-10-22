<?php

declare(strict_types=1);

namespace App\Commands\Operations;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Operations\ListOperationsRequest;
use Orbit\Sdk\Responses\Operations\OperationsResponse;

final class ListOperationsCommand extends GatewayCommand
{
    private const array Statuses = ['queued', 'running', 'waiting', 'succeeded', 'failed', 'cancelled'];

    #[\Override]
    protected $signature = 'operation:list
        {--status= : queued, running, waiting, succeeded, failed, or cancelled}
        {--kind= : An operation kind, such as instance.deploy}
        {--instance= : Only operations on this Instance}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the newest operations on the Nodes you can access.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $status = $this->option('status');
        $kind = $this->option('kind');
        $instance = $this->option('instance');

        if ($status !== null && ! in_array($status, self::Statuses, true)) {
            return $this->renderGatewayFailure('input.invalid', '--status must be queued, running, waiting, succeeded, failed, or cancelled.', details: ['field' => 'status']);
        }

        if ($instance !== null && preg_match('/\A[1-9][0-9]*\z/', $instance) !== 1) {
            return $this->renderGatewayFailure('instance.id_invalid', '--instance must be a positive Instance ID.', details: ['field' => 'instance']);
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $operations = $this->sendWithProgress(
            $connector,
            new ListOperationsRequest(
                status: $status,
                kind: $kind,
                targetType: $instance === null ? null : 'instance',
                targetId: $instance === null ? null : (int) $instance,
            ),
            OperationsResponse::class,
            ['List operations', 'Loading operations', 'Loaded operations'],
        );

        if (! $operations instanceof OperationsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($operations->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'KIND', 'TARGET', 'STATUS', 'STEP', 'BY', 'STARTED'],
            array_map(OperationOutput::row(...), $operations->operations),
            'No operations found.',
        ));

        return self::SUCCESS;
    }
}
