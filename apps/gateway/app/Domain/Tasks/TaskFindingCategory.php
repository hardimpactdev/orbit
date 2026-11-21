<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** The kind of one blocking finding in a review receipt ([The review receipt](/reference/tasks#the-review-receipt)). */
enum TaskFindingCategory: string
{
    case Regression = 'regression';
    case Destructive = 'destructive';
    case FailureState = 'failure_state';
    case Race = 'race';
    case Contract = 'contract';
    case Validation = 'validation';
    case OutcomeMissing = 'outcome_missing';
    case Docs = 'docs';
    case Other = 'other';

    /** The one line the reviewer's receipt rule gives for the category. */
    public function meaning(): string
    {
        return match ($this) {
            self::Regression => 'an unchanged input behaves differently than on the base',
            self::Destructive => 'deletes, overwrites or stops something it must not',
            self::FailureState => 'a wrong state or result after a failure, an interruption or a rollback',
            self::Race => 'the order or the lock between concurrent actions',
            self::Contract => 'two components disagree on the same data or rule',
            self::Validation => 'accepts input it must refuse, or refuses input it must accept',
            self::OutcomeMissing => 'an outcome or a deliverable of the brief is not met or not proven',
            self::Docs => 'docs, fixtures or generated output are wrong',
            self::Other => 'none of the above',
        };
    }
}
