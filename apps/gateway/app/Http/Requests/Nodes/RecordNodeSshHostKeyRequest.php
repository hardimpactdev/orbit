<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Infrastructure\Ssh\HostKey;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class RecordNodeSshHostKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'ipv4'],
            'type' => ['required', 'string', 'in:ssh-ed25519'],
            'value' => ['required', 'string', 'max:16384', 'regex:/\A[A-Za-z0-9+\/]+={0,2}\z/D'],
        ];
    }

    /** @return array<string,mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['host', 'type', 'value']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function observedHost(): string
    {
        return ValidatedData::string($this->validated('host'));
    }

    public function hostKey(): HostKey
    {
        $value = ValidatedData::string($this->validated('value'));
        $bytes = base64_decode($value, strict: true);
        if ($bytes === false || strlen($bytes) !== 51 || ! str_starts_with($bytes, pack('N', 11).'ssh-ed25519'.pack('N', 32))) {
            throw ValidationException::withMessages(['value' => ['The public key must contain a complete SSH Ed25519 key.']]);
        }

        return new HostKey(ValidatedData::string($this->validated('type')), $value, 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, binary: true)), '='));
    }
}
