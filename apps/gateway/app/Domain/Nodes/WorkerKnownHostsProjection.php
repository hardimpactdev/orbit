<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\AdmitWorkerKnownHostsAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Project the local write, then admit any pin snapshot changed while that write was unfinished. */
final readonly class WorkerKnownHostsProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['intent']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            Node::query()->whereKey(209)->lockForUpdate()->firstOrFail();
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $terminal = $operation->status->terminal();
            $result = array_intersect_key(ValidatedData::object($data['result'] ?? []), array_flip([
                'known_hosts_ready', 'digest', 'changed', 'path', 'error_code',
            ]));
            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            if (! $terminal && $projected->status->terminal()
                && app(SwampWorkerKnownHostsInput::class)->capture()['digest'] !== (ValidatedData::object($operation->input['intent'])['digest'] ?? null)) {
                app(AdmitWorkerKnownHostsAction::class)->execute();
            }

            return $projected;
        });
    }
}
