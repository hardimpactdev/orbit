<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

final readonly class UfwRuleShape
{
    public function __construct(
        public string $comment,
        public string $action,
        public string $direction,
        public string $source,
        public string $destination,
        public string $port,
        public string $protocol,
        public ?string $inInterface,
        public ?string $outInterface,
        public ?string $family,
    ) {}

    /** @return array<string, mixed> */
    public function toAgentInput(): array
    {
        return [
            'comment' => $this->comment,
            'action' => $this->action,
            'direction' => $this->direction,
            'source' => $this->source,
            'destination' => $this->destination,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'in_interface' => $this->inInterface,
            'out_interface' => $this->outInterface,
            'family' => $this->family,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromAgentOutput(array $value): self
    {
        $string = static fn (string $key): string => is_string($value[$key] ?? null)
            ? $value[$key] : throw new \UnexpectedValueException('Invalid firewall observation.');
        $nullable = static fn (string $key): ?string => array_key_exists($key, $value) && ($value[$key] === null || is_string($value[$key]))
            ? $value[$key] : throw new \UnexpectedValueException('Invalid firewall observation.');

        return new self(
            $string('comment'), $string('action'), $string('direction'), $string('source'),
            $string('destination'), $string('port'), $string('protocol'),
            $nullable('in_interface'), $nullable('out_interface'), $nullable('family'),
        );
    }

    public function withSource(string $source): self
    {
        return new self(
            comment: $this->comment,
            action: $this->action,
            direction: $this->direction,
            source: $source,
            destination: $this->destination,
            port: $this->port,
            protocol: $this->protocol,
            inInterface: $this->inInterface,
            outInterface: $this->outInterface,
            family: $this->family,
        );
    }

    public function matches(self $observed): bool
    {
        return
            $this->comment === $observed->comment
            && $this->action === $observed->action
            && $this->direction === $observed->direction
            && $this->source === $observed->source
            && $this->destination === $observed->destination
            && $this->port === $observed->port
            && $this->protocol === $observed->protocol
            && $this->inInterface === $observed->inInterface
            && $this->outInterface === $observed->outInterface
            && ($this->family === null || $this->family === $observed->family);
    }
}
