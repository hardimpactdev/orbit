<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Tasks\RemoteTaskWorkspaceStateReader;
use App\Models\Instance;
use Illuminate\Container\Attributes\Bind;

#[Bind(RemoteTaskWorkspaceStateReader::class)]
interface TaskWorkspaceStateReader
{
    public function headCommit(Instance $instance): ?string;

    public function currentBranch(Instance $instance): ?string;
}
