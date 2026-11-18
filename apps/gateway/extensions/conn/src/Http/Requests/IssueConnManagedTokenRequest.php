<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A caller asks for one managed token by fixed purpose; it names no scopes ([Caller route](/reference/conn#caller-route)). */
final class IssueConnManagedTokenRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['purpose' => ['required', 'string', 'in:reader']];
    }

    /**
     * The body holds `purpose` and nothing else: a `scopes` field is refused even when it is empty or null.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->json()->all()), ['purpose']) as $field) {
                $validator->errors()->add((string) $field, 'The field is not accepted.');
            }
        }];
    }
}
