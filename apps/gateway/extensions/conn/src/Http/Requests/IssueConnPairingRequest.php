<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Existing clients keep the immediate reply unless they explicitly select Operations. */
final class IssueConnPairingRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['async' => ['sometimes', 'boolean']];
    }
}
