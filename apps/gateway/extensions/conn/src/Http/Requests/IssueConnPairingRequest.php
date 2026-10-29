<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Existing clients wait for the Operation unless they explicitly select an asynchronous reply. */
final class IssueConnPairingRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['async' => ['sometimes', 'boolean']];
    }
}
