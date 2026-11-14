<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

/**
 * The copy turn agent a role of a task group resolves to: each value and its source, `group`, `project` or `gateway`. A new copy
 * thread of the role starts with it; a thread keeps the values it started with.
 */
final readonly class TaskCopyAgentResponse
{
    public const array Values = ['provider', 'model', 'effort'];

    public function __construct(
        public string $provider,
        public string $providerSource,
        public string $model,
        public string $modelSource,
        public string $effort,
        public string $effortSource,
    ) {}

    /** The role's agent from `resolved_agents.<role>`; null when the Gateway sends none or a value is malformed. */
    public static function fromGatewayData(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }
        $values = [];
        foreach (self::Values as $key) {
            $entry = $data[$key] ?? null;
            if (! is_array($entry) || ! is_string($entry['value'] ?? null) || ! is_string($entry['source'] ?? null)) {
                return null;
            }
            $values[$key] = $entry;
        }

        return new self($values['provider']['value'], $values['provider']['source'], $values['model']['value'], $values['model']['source'],
            $values['effort']['value'], $values['effort']['source']);
    }

    /** `claude / claude-opus-5-5 / medium` */
    public function label(): string
    {
        return "{$this->provider} / {$this->model} / {$this->effort}";
    }

    /** @return array{provider: array{value: string, source: string}, model: array{value: string, source: string}, effort: array{value: string, source: string}} */
    public function toArray(): array
    {
        return [
            'provider' => ['value' => $this->provider, 'source' => $this->providerSource],
            'model' => ['value' => $this->model, 'source' => $this->modelSource],
            'effort' => ['value' => $this->effort, 'source' => $this->effortSource],
        ];
    }
}
