<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A caller asks for one managed token by fixed purpose; it names no scopes ([Caller route](/reference/conn#caller-route)). */
final class IssueConnManagedTokenRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'in:reader'],
            'scopes' => ['prohibited'],
            'scope' => ['prohibited'],
        ];
    }
}
