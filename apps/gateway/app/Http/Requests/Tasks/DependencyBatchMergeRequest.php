<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DependencyBatchMergeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $caller = $this->getUserResolver()();
        $id = config('dependency_detection.services_node_id');
        if ((! is_int($id) && ! is_string($id)) || ! $caller instanceof Node || (string) $id !== (string) $caller->id) {
            throw new ResourceOperationException('dependency_merge.forbidden', 'Services peer required.', 403);
        }

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['publish_operation_id' => ['required', 'integer:strict', 'min:1'], 'head_sha' => ['required', 'string', 'regex:/\A[0-9a-f]{40}\z/D']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['publish_operation_id', 'head_sha']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
