<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskFindingCategory;
use App\Models\Instance;
use App\Models\TaskCheck;
use Illuminate\Support\Facades\DB;

/**
 * The review receipt that a reviewer turn posts from its task copy (docs/reference/tasks.md#the-review-receipt): its verdict and the
 * category of each blocking finding. It is admitted as the pull request text is, and recorded on the turn's check with the turn; the last
 * post of a turn counts. The review row is written when the turn ends.
 */
final readonly class RecordTaskReviewReceiptAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private RecordTaskPullRequestTextAction $text) {}

    /** @param  list<TaskFindingCategory>  $findings */
    public function execute(Instance $copy, string $verdict, array $findings): TaskCheck
    {
        $this->extension->execute();

        return DB::transaction(function () use ($copy, $verdict, $findings): TaskCheck {
            [, $turn, $check] = $this->text->admit($copy);
            $check->update(['review_receipt' => ['verdict' => $verdict, 'findings' => array_map(static fn (TaskFindingCategory $finding): string => $finding->value, $findings)], 'review_receipt_operation_id' => $turn->id]);

            return $check;
        });
    }
}
