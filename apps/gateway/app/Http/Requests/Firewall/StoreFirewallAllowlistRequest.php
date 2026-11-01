<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallPort;
use App\Domain\Firewall\FirewallSource;
use App\Support\ValidatedData;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class StoreFirewallAllowlistRequest extends StoreFirewallRuleRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:63', 'regex:/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/D'],
            'sources' => ['required', 'array', 'list', 'min:1', 'max:64'],
            'sources.*' => ['required', 'string', 'max:18'],
            'source' => ['prohibited'],
            'protocol' => ['sometimes', Rule::in(['tcp', 'udp'])],
            'port' => ['required', 'string', 'max:11'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator): void {
                $sources = $this->input('sources');
                if (! is_array($sources)) {
                    return;
                }
                foreach ($sources as $index => $source) {
                    try {
                        if (! is_string($source) || FirewallSource::family(FirewallSource::normalize($source)) !== 'v4') {
                            throw new InvalidArgumentException;
                        }
                    } catch (InvalidArgumentException) {
                        $validator->errors()->add('sources.'.$index, 'Each source must be a valid IPv4 address or CIDR.');
                    }
                }
            },
        ];
    }

    public function getData(): StoreFirewallRuleData
    {
        $validated = $this->validated();
        $sources = array_values(array_unique(array_map(
            FirewallSource::normalize(...),
            ValidatedData::stringList($validated['sources']),
        )));
        sort($sources);

        return new StoreFirewallRuleData(
            name: ValidatedData::string($validated['name']),
            action: FirewallAction::Allowlist,
            source: 'any',
            protocol: ValidatedData::string($validated['protocol'] ?? 'tcp'),
            port: FirewallPort::normalize(ValidatedData::string($validated['port'])),
            sources: $sources,
        );
    }
}
