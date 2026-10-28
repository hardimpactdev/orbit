<?php

declare(strict_types=1);

namespace App\Http\Requests\GitHub;

use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DependencyReadTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = config('dependency_detection.services_node_id');
        $caller = $this->getUserResolver()();
        if ((! is_int($id) && ! is_string($id)) || preg_match('/\A[1-9][0-9]*\z/D', (string) $id) !== 1
            || ! $caller instanceof Node || (string) $caller->id !== (string) $id) {
            throw new ResourceOperationException('dependency_token.forbidden', 'Services peer required.', 403);
        }

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            if ($this->query->count() !== 0) {
                throw new UnexpectedValueException('Query parameters are not accepted.');
            }

            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }
}
