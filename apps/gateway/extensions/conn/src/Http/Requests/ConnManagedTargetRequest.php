<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;

/** A request on a managed target route; `AuthenticateConnManagedTarget` already proved the target. */
final class ConnManagedTargetRequest extends FormRequest
{
    public const string Nonce = 'regex:/^[A-Za-z0-9_-]{16,64}$/';

    /** An RFC 3339 time with an explicit offset, the only form a target time takes. */
    public const string Time = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * Rejects an `observed` ACK whose handler close is earlier than its fence, so evidence that cannot be true leaves the
     * revision owed.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route()?->getName() !== 'conn:managed:ack' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $fenced = $this->input('fenced_at');
            $closed = $this->input('handlers_closed_at');
            if (is_string($fenced) && is_string($closed) && CarbonImmutable::parse($closed)->lessThan(CarbonImmutable::parse($fenced))) {
                $validator->errors()->add('handlers_closed_at', 'The handler close cannot be earlier than the fence.');
            }
        }];
    }

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
                'notice_received_at' => ['present', 'nullable', 'string', self::Time, 'prohibited_if:kind,recovered'],
                'fenced_at' => ['nullable', 'string', self::Time, 'required_if:kind,observed', 'prohibited_if:kind,recovered'],
                'handlers_closed_at' => ['nullable', 'string', self::Time, 'required_if:kind,observed', 'prohibited_if:kind,recovered'],
                'recovered_at' => ['nullable', 'string', self::Time, 'required_if:kind,recovered', 'prohibited_if:kind,observed'],
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
