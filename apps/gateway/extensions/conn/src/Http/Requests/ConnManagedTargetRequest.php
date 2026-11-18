<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedTime;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;

/** A request on a managed target route; `AuthenticateConnManagedTarget` already proved the target. */
final class ConnManagedTargetRequest extends FormRequest
{
    public const string Nonce = 'regex:/^[A-Za-z0-9_-]{16,64}$/';

    /**
     * Rejects an `observed` ACK whose handler close is earlier than its fence, by every fractional digit, so evidence that
     * cannot be true leaves the revision owed.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->route()?->getName() !== 'conn:managed:ack' || $validator->errors()->isNotEmpty()) {
                return;
            }
            $fenced = ConnManagedTime::parse($this->input('fenced_at'));
            $closed = ConnManagedTime::parse($this->input('handlers_closed_at'));
            if ($fenced instanceof ConnManagedTime && $closed instanceof ConnManagedTime && $closed->before($fenced)) {
                $validator->errors()->add('handlers_closed_at', 'The handler close cannot be earlier than the fence.');
            }
        }];
    }

    /** A JSON integer: `true`, `"1"` and `1.0` are refused. */
    private static function strictInteger(int $min): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($min): void {
            if (! is_int($value) || $value < $min) {
                $fail("The {$attribute} field must be an integer of at least {$min}.");
            }
        };
    }

    /** A time that exists, as RFC 3339 with an explicit offset. */
    private static function time(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! ConnManagedTime::parse($value) instanceof ConnManagedTime) {
                $fail("The {$attribute} field must be an RFC 3339 time with an offset.");
            }
        };
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'conn:managed:broadcasting-auth' => ['socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'], 'channel_name' => ['required', 'string']],
            'conn:managed:authority' => ['nonce' => ['required', 'string', self::Nonce], 'after_revision' => ['present', 'nullable', self::strictInteger(0)]],
            'conn:managed:introspect' => ['nonce' => ['required', 'string', self::Nonce], 'token' => ['required', 'string'], 'peer_address' => ['required', 'ip']],
            'conn:managed:ack' => [
                'revision' => ['required', self::strictInteger(1)],
                'kind' => ['required', 'string', 'in:observed,recovered'],
                'notice_received_at' => ['present', 'nullable', self::time(), 'prohibited_if:kind,recovered'],
                'fenced_at' => ['nullable', self::time(), 'required_if:kind,observed', 'prohibited_if:kind,recovered'],
                'handlers_closed_at' => ['nullable', self::time(), 'required_if:kind,observed', 'prohibited_if:kind,recovered'],
                'recovered_at' => ['nullable', self::time(), 'required_if:kind,recovered', 'prohibited_if:kind,observed'],
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
