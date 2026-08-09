<?php

declare(strict_types=1);

namespace Orbit\Core\Operations;

use InvalidArgumentException;
use LogicException;

final readonly class OperationStreamFrameSource
{
    private function __construct(
        private bool $gateway,
        private ?int $nodeId = null,
        private ?string $nodeName = null,
    ) {}

    public static function gateway(): self
    {
        return new self(gateway: true);
    }

    public static function node(int $id, string $name): self
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Frame source_node.id must be a positive integer.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Frame source_node.name must be a non-empty string.');
        }

        return new self(gateway: false, nodeId: $id, nodeName: $name);
    }

    /**
     * @param  array<array-key, mixed>  $frame
     */
    public static function fromArray(array $frame): self
    {
        $hasGateway = array_key_exists('source', $frame);
        $hasNode = array_key_exists('source_node', $frame);

        if ($hasGateway === $hasNode) {
            throw new InvalidArgumentException(
                'Frame source must contain exactly one of source or source_node.',
            );
        }

        if ($hasGateway) {
            if ($frame['source'] !== 'gateway') {
                throw new InvalidArgumentException('Frame source must be gateway.');
            }

            return self::gateway();
        }

        if (! is_array($frame['source_node'])) {
            throw new InvalidArgumentException('Frame source_node must be an object.');
        }

        if (
            ! isset($frame['source_node']['id'])
            || ! is_int($frame['source_node']['id'])
            || $frame['source_node']['id'] < 1
        ) {
            throw new InvalidArgumentException('Frame source_node.id must be a positive integer.');
        }

        if (
            ! isset($frame['source_node']['name'])
            || ! is_string($frame['source_node']['name'])
            || $frame['source_node']['name'] === ''
        ) {
            throw new InvalidArgumentException('Frame source_node.name must be a non-empty string.');
        }

        return self::node($frame['source_node']['id'], $frame['source_node']['name']);
    }

    public function isGateway(): bool
    {
        return $this->gateway;
    }

    /**
     * @return array{source: 'gateway'}|array{source_node: array{id: int, name: string}}
     */
    public function toArray(): array
    {
        if ($this->gateway) {
            return ['source' => 'gateway'];
        }

        if ($this->nodeId === null || $this->nodeName === null) {
            throw new LogicException('Node frame source is incomplete.');
        }

        return [
            'source_node' => [
                'id' => $this->nodeId,
                'name' => $this->nodeName,
            ],
        ];
    }
}
