<?php

declare(strict_types=1);

namespace App\Http\Requests\Vault;

use App\Data\Vault\SetupVaultData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetupVaultRequest extends FormRequest
{
    private const array Keys = ['url', 'client_id', 'client_secret', 'master_password', 'organization', 'collection'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:https', $this->orbitOrigin(...)],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:1024'],
            'master_password' => ['required', 'string', 'max:1024'],
            'organization' => ['required', 'string', 'max:255'],
            'collection' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Only an Orbit host: the Gateway trusts only the Orbit root CA, and an origin elsewhere would
     * let a caller with Gateway access send every database password to a server it controls.
     */
    private function orbitOrigin(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = is_string($value) ? parse_url($value) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! str_ends_with($host, '.orbit')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            $fail('The url must be an HTTPS origin on a .orbit host, such as https://vault.orbit.');
        }
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), self::Keys);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): SetupVaultData
    {
        $validated = $this->validated();
        $value = static fn (string $key): string => is_string($validated[$key] ?? null) ? $validated[$key] : '';

        return new SetupVaultData(
            $value('url'),
            $value('client_id'),
            $value('client_secret'),
            $value('master_password'),
            $value('organization'),
            $value('collection'),
        );
    }
}
