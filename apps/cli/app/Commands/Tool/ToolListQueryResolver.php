<?php

declare(strict_types=1);

namespace App\Commands\Tool;

use Closure;

final readonly class ToolListQueryResolver
{
    /**
     * @param  Closure(string): array<string, mixed>  $gatewayGet
     */
    public function __construct(
        private ?string $instance,
        private ?string $node,
        private ?string $defaultNode,
        private bool $all,
        private Closure $gatewayGet,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        if ($this->all) {
            return $this->filledQuery([
                'instance' => $this->instance,
                'node' => $this->node,
            ]);
        }

        $node = $this->node ?? ($this->instance === null ? $this->defaultNode : null);

        return $this->filledQuery([
            'instance' => $this->instance,
            'node' => $this->usesCallerNodeFallback($node) ? $this->callerNodeName() : $node,
        ]);
    }

    private function usesCallerNodeFallback(?string $node): bool
    {
        return $node === null && $this->instance === null && $this->node === null;
    }

    private function callerNodeName(): ?string
    {
        $response = ($this->gatewayGet)('/api/me');
        $data = $response['success']['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        $self = $data['self'] ?? null;

        if (! is_array($self)) {
            return null;
        }

        $name = $self['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function filledQuery(array $query): array
    {
        return array_filter($query, static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
