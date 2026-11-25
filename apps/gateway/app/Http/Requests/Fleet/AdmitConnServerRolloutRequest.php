<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use App\Domain\ConnServer\ConnServerRollout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AdmitConnServerRolloutRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['dry_run' => ['required', 'boolean'], 'scope' => ['required', Rule::in(ConnServerRollout::Scopes)],
            'node_ids' => ['required_if:scope,nodes', 'prohibited_unless:scope,nodes', 'array', 'min:1', 'max:4'],
            'node_ids.*' => ['integer', 'distinct', 'exists:conn_server_nodes,node_id']];
    }
}
