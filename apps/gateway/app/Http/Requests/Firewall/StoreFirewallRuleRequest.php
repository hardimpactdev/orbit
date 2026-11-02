<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallPort;
use App\Domain\Firewall\FirewallSource;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class StoreFirewallRuleRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'source' => ['sometimes', 'string', 'max:255'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'protocol' => ['sometimes', Rule::in(['tcp', 'udp'])],
            'port' => ['required', 'string', 'max:11'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateSource($validator);
                $this->validatePort($validator);
            },
        ];
    }

    public function getData(): StoreFirewallRuleData
    {
        $validated = $this->validated();
        $routeAction = $this->route('firewall_action');

        return new StoreFirewallRuleData(
            action: FirewallAction::from(is_string($routeAction) ? $routeAction : ''),
            source: FirewallSource::normalize(ValidatedData::string($validated['source'] ?? 'any')),
            protocol: ValidatedData::string($validated['protocol'] ?? 'any'),
            port: FirewallPort::normalize(ValidatedData::string($validated['port'] ?? null)),
            label: is_string($validated['label'] ?? null) ? $validated['label'] : null,
        );
    }

    protected function validateSource(Validator $validator): void
    {
        $source = $this->input('source', 'any');

        if (! is_string($source)) {
            return;
        }

        try {
            FirewallSource::normalize($source);
        } catch (InvalidArgumentException) {
            $validator->errors()->add('source', 'The source must be any or a valid IPv4 or IPv6 CIDR.');
        }
    }

    protected function validatePort(Validator $validator): void
    {
        $port = $this->input('port');

        if (! is_string($port)) {
            return;
        }

        try {
            FirewallPort::normalize($port);
        } catch (InvalidArgumentException) {
            $validator->errors()->add('port', 'The port must be from 1 to 65535 or an ordered bounded range.');
        }
    }
}
