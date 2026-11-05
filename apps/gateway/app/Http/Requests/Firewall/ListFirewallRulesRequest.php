<?php

declare(strict_types=1);

namespace App\Http\Requests\Firewall;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Foundation\Http\FormRequest;

/** `node` limits the read to one Node; `managed` and `live` add Orbit's own rules and the live comparison. */
final class ListFirewallRulesRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'managed' => ['sometimes', 'boolean'],
            'live' => ['sometimes', 'boolean'],
        ];
    }

    public function nodeId(): ?int
    {
        if (! $this->query->has('node')) {
            return null;
        }

        $node = filter_var($this->query('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($node) ? $node : throw new ResourceOperationException('firewall.node_id_invalid', 'Node ID must be a positive integer.', 422);
    }

    public function managed(): bool
    {
        return $this->boolean('managed');
    }

    public function live(): bool
    {
        return $this->boolean('live');
    }
}
