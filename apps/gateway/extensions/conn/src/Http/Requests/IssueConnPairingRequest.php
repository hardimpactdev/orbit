<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** With `renew: true` a client renews a session it already had; a revoke of its newest pairing then refuses the renewal. */
final class IssueConnPairingRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'renew' => ['sometimes', 'boolean:strict'],
        ];
    }

    public function renews(): bool
    {
        return $this->boolean('renew');
    }
}
