<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Models\AptRepository;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListAptRepositoriesAction
{
    /** @return Collection<int, AptRepository> */
    public function execute(int $nodeId): Collection
    {
        return AptRepository::query()->withCount('tools')->where('node_id', $nodeId)->orderBy('name')->get();
    }
}
