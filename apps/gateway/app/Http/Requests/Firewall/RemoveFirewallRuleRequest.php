<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Domain\Firewall\FirewallPort;
use App\Domain\Firewall\FirewallSource;
use App\Support\ValidatedData;
use Illuminate\Validation\Rule;

/** Selects one rule by its port; source and protocol narrow the match only when given. */
final class RemoveFirewallRuleRequest extends StoreFirewallRuleRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'port' => ['required', 'string', 'max:11'],
            'source' => ['sometimes', 'string', 'max:255'],
            'protocol' => ['sometimes', Rule::in(['tcp', 'udp', 'any'])],
        ];
    }

    /** @return array{port: string, source: string|null, protocol: string|null} */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'port' => FirewallPort::normalize(ValidatedData::string($validated['port'])),
            'source' => isset($validated['source']) ? FirewallSource::normalize(ValidatedData::string($validated['source'])) : null,
            'protocol' => isset($validated['protocol']) ? ValidatedData::string($validated['protocol']) : null,
        ];
    }
}
