<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * Swamp network work that an admission needs runs after its transaction commits, never under the SQLite write lock.
 * Execution copies push first. Admitted runs start only when every copy is stored; a failed copy fails them unstarted.
 * Outside a transaction the work runs at once. A rolled-back transaction discards its work.
 */
#[Scoped]
final class SwampDispatch
{
    /** @var array<int, Closure(): void> */
    private array $copies = [];

    /** @var array<int, Operation> */
    private array $starts = [];

    private int $next = 0;

    /** @param Closure(): void $push */
    public function copy(Closure $push): void
    {
        $id = $this->next++;
        $this->copies[$id] = $push;
        DB::afterRollBack(function () use ($id): void {
            unset($this->copies[$id]);
        });
        DB::afterCommit(fn () => $this->dispatch());
    }

    public function start(Operation $operation): void
    {
        $id = $this->next++;
        $this->starts[$id] = $operation;
        DB::afterRollBack(function () use ($id): void {
            unset($this->starts[$id]);
        });
        DB::afterCommit(fn () => $this->dispatch());
    }

    private function dispatch(): void
    {
        [$copies, $starts] = [$this->copies, $this->starts];
        $this->copies = $this->starts = [];
        try {
            foreach ($copies as $push) {
                $push();
            }
        } catch (ResourceOperationException $error) {
            foreach ($starts as $operation) {
                app(StartSwampRun::class)->refuse($operation, $error->errorCode);
            }
            $first = reset($starts);
            throw $first === false ? $error
                : new ResourceOperationException($error->errorCode, $error->getMessage(), $error->status, details: ['operation_id' => $first->id]);
        }
        foreach ($starts as $operation) {
            app(StartSwampRun::class)->start($operation);
        }
    }
}
