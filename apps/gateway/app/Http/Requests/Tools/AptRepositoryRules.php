<?php

declare(strict_types=1);

namespace App\Http\Requests\Tools;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Facades\Validator;

final class AptRepositoryRules
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'repository' => ['nullable', 'array:name,uris,suites,components,architectures,signing_key', 'required_array_keys:name,uris,suites,components,signing_key'],
            'repository.name' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'string', 'max:63', 'regex:/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D', 'not_in:caddy,php'],
            'repository.uris' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'array', 'list', 'min:1', 'max:16'],
            'repository.uris.*' => ['required', 'string', 'max:2048', 'url:https', 'regex:~\Ahttps://[^\s/?#@]+(?:/[^\s?#]*)?\z~D', 'distinct:strict'],
            'repository.suites' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'array', 'list', 'min:1', 'max:16'],
            'repository.suites.*' => ['required', 'string', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', 'distinct:strict'],
            'repository.components' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'array', 'list', 'min:1', 'max:16'],
            'repository.components.*' => ['required', 'string', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}\z/D', 'distinct:strict'],
            'repository.architectures' => ['sometimes', 'array', 'list', 'min:1', 'max:16'],
            'repository.architectures.*' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9-]{0,31}\z/D', 'distinct:strict'],
            'repository.signing_key' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'array:url,fingerprint'],
            'repository.signing_key.url' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'string', 'max:2048', 'url:https', 'regex:~\Ahttps://[^\s/?#@]+(?:/[^\s?#]*)?\z~D'],
            'repository.signing_key.fingerprint' => ['exclude_without:repository', 'exclude_if:repository,null', 'required', 'string', 'regex:/\A(?:[A-F0-9]{40}|[A-F0-9]{64})\z/D'],
        ];
    }

    /** @param array<string, mixed>|null $definition */
    public function validate(?array $definition, string $manager): void
    {
        if ($definition !== null && ($manager !== 'apt' || Validator::make(['repository' => $definition], $this->rules())->fails())) {
            throw new ResourceOperationException('tool.repository_invalid', 'The apt repository definition is invalid.', 422);
        }
    }
}
