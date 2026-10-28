<?php

declare(strict_types=1);

namespace App\Http\Requests\Operations;

/** Receipt reads use only the configured executor peer identity and server-recorded run descriptor. */
final class ReceiptExecutorRequest extends ExecutorRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['worker' => ['prohibited'], 'node_id' => ['prohibited'], 'effect_key' => ['prohibited'], 'transport' => ['prohibited'], 'name' => ['prohibited'], 'input' => ['prohibited']];
    }
}
