<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions;

use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Data\ConnLaunchSnapshot;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;

final readonly class ReadConnLaunchesAction
{
    public function __construct(private ConnLaunchAccess $access, private ConnLaunchSnapshot $snapshot) {}

    /** @return list<array<string, mixed>> */
    public function index(Node $caller): array
    {
        return DB::transaction(function () use ($caller): array {
            $profileId = $this->access->profileId($caller, lock: true);
            if ($profileId === null) {
                return [];
            }

            return array_values($this->access->visible($caller, $profileId)->latest('id')->get()->map($this->snapshot->project(...))->all());
        });
    }

    /** @return array<string, mixed> */
    public function show(Node $caller, int $operationId): array
    {
        return DB::transaction(function () use ($caller, $operationId): array {
            $profileId = $this->access->profileId($caller, lock: true);
            abort_if($profileId === null, 404);

            return $this->snapshot->project($this->access->visible($caller, $profileId)->findOrFail($operationId));
        });
    }
}
