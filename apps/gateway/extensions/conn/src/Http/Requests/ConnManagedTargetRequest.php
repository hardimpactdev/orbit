<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;

/** A request on a managed target route; `AuthenticateConnManagedTarget` already proved the target. */
final class ConnManagedTargetRequest extends FormRequest
{
    public const string Nonce = 'regex:/^[A-Za-z0-9_-]{16,64}$/';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'conn:managed:broadcasting-auth' => ['socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'], 'channel_name' => ['required', 'string']],
            'conn:managed:authority' => ['nonce' => ['required', 'string', self::Nonce], 'after_revision' => ['present', 'nullable', 'integer', 'min:0']],
            'conn:managed:introspect' => ['nonce' => ['required', 'string', self::Nonce], 'token' => ['required', 'string'], 'peer_address' => ['required', 'ip']],
            'conn:managed:ack' => [
                'revision' => ['required', 'integer', 'min:1'],
                'kind' => ['required', 'in:observed,recovered'],
                'notice_received_at' => ['present', 'nullable', 'date', 'prohibited_if:kind,recovered'],
                'fenced_at' => ['nullable', 'date', 'required_if:kind,observed', 'prohibited_if:kind,recovered'],
                'handlers_closed_at' => ['nullable', 'date', 'required_if:kind,observed', 'prohibited_if:kind,recovered'],
                'recovered_at' => ['nullable', 'date', 'required_if:kind,recovered', 'prohibited_if:kind,observed'],
            ],
            default => [],
        };
    }

    public function target(): ConnManagedTarget
    {
        $target = $this->attributes->get('conn.managed_target');
        assert($target instanceof ConnManagedTarget);

        return $target;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }
}
