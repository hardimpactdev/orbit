<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class ListConnProjectsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['include_unavailable' => ['sometimes', 'required', 'boolean']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        // Read the original query so input trimming cannot admit padded boolean strings.
        parse_str($this->server->getString('QUERY_STRING'), $query);
        if (array_diff(array_keys($query), ['include_unavailable']) !== []) {
            throw ValidationException::withMessages(['query' => ['Only the include_unavailable query field is allowed.']]);
        }

        if (! array_key_exists('include_unavailable', $query)) {
            return [];
        }

        return ['include_unavailable' => match ($query['include_unavailable']) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $query['include_unavailable'],
        }];
    }

    public function includeUnavailable(): bool
    {
        return $this->validated('include_unavailable', false) === true;
    }
}
