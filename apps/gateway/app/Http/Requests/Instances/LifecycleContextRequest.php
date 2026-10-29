<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Http\Requests\Operations\ExecutorRequest;

/** Services-only family read; it grants no transport capability. */
final class LifecycleContextRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
