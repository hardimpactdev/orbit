<?php

declare(strict_types=1);

namespace App\Http\Requests\Vault;

use App\Data\Vault\SetupVaultData;
use App\Http\Requests\TopLevelJsonObjectInspector;
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
            'url' => ['required', 'string', 'max:2048', 'url:https'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:1024'],
            'master_password' => ['required', 'string', 'max:1024'],
            'organization' => ['required', 'string', 'max:255'],
            'collection' => ['required', 'string', 'max:255'],
        ];
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
