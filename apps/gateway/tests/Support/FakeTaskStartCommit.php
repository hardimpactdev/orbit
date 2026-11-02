<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskStartCommit;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/** Pins a fixed start commit, or refuses with a set exception. Records the transaction level of each read. */
final class FakeTaskStartCommit implements TaskStartCommit
{
    /** @var list<int> */
    public array $transactionLevels = [];

    public function __construct(public string $commit = 'cccccccccccccccccccccccccccccccccccccccc', public ?ResourceOperationException $refusal = null) {}

    public function resolve(Project $project): string
    {
        $this->transactionLevels[] = DB::transactionLevel();
        if ($this->refusal !== null) {
            throw $this->refusal;
        }

        return $this->commit;
    }
}
