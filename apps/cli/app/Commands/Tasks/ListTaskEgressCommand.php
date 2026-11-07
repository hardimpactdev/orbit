<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Carbon\CarbonImmutable;
use Orbit\Sdk\Requests\Tasks\ListTaskEgressRequest;
use Orbit\Sdk\Responses\Tasks\TaskEgressResponse;
use Orbit\Sdk\Responses\Tasks\TaskEgressRowResponse;

final class ListTaskEgressCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:egress
        {group? : Numeric task group ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the outside hosts a task group\'s VM connected to.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
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

        $egress = $this->sendWithProgress($connector, new ListTaskEgressRequest($groupId), TaskEgressResponse::class, ['List outside hosts', 'Loading outside hosts', 'Loaded outside hosts']);

        if (! $egress instanceof TaskEgressResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($egress->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Host', 'Port', 'Seen by', 'Flags', 'Address', 'First seen'],
            array_map(static fn (TaskEgressRowResponse $row): array => [
                $row->destination,
                $row->port === null ? '' : "{$row->port}/{$row->protocol}",
                implode(',', $row->sources),
                implode(',', $row->flags),
                ($row->addresses[0] ?? '').(count($row->addresses) > 1 ? ' +'.(count($row->addresses) - 1) : ''),
                CarbonImmutable::parse($row->firstSeenAt)->utc()->format('m-d H:i:s'),
            ], $egress->rows),
            'No outside hosts.',
        ));

        $this->writeHumanMessage("Request ID: {$egress->requestId}");

        return self::SUCCESS;
    }
}
