<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `include_held` opts in to the pairings the Gateway holds sessions for; installed clients keep the device pairings alone. */
final class ListConnPairingsRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['include_held' => ['sometimes', 'required', 'boolean']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        // Read the original query so input trimming cannot admit padded boolean strings.
        parse_str($this->server->getString('QUERY_STRING'), $query);
        if (! array_key_exists('include_held', $query)) {
            return [];
        }

        return ['include_held' => match ($query['include_held']) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $query['include_held'],
        }];
    }

    public function includeHeld(): bool
    {
        return $this->validated('include_held', false) === true;
    }
}
