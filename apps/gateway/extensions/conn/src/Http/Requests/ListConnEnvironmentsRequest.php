<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListConnEnvironmentsRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['include' => ['sometimes']];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        // Preserve ignored include values, including arrays and strings the request stack trims.
        parse_str($this->server->getString('QUERY_STRING'), $query);

        return array_key_exists('include', $query) ? ['include' => $query['include']] : [];
    }

    public function includeGit(): bool
    {
        return $this->validated('include') === 'git';
    }
}
