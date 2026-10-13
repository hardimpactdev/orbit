<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** One issue's composed discovery topology, as `<worktree>/.e2e/topology.json` records it. */
final readonly class FeatureTopology
{
    public const int SCHEMA = 5;

    /** The one disk device name a mounted worktree uses on every checkout Node. */
    public const string SOURCE_DEVICE = 'orbit-source';

    public TopologyTarget $target;

    public string $network;

    /** @var array<string, string> */
    public array $instances;

    /** The exact attempt this topology belongs to; two attempts of one issue never share resources. */
    public AttemptId $attempt;

    public AttemptPurpose $purpose;

    /**
     * @param  array<string, array{device:string,source:string,path:string}>  $mounts
     */
    public function __construct(
        public TopologyConstructionInputs $construction,
        public SourceState $source,
        public VerificationReport $verification,
        public array $mounts = [],
    ) {
        $this->target = $construction->target;
        $this->attempt = $this->target->attempt;
        $this->purpose = AttemptPurpose::Discovery;
        $this->network = $this->target->network();
        $instances = [];
        foreach ($construction->nodes as $node => $record) {
            $instances[$node] = $record['instance'];
        }
        $this->instances = $instances;

        self::validateMounts($mounts, $source->mounted ? $this->target->recipe->checkoutNodeKeys() : []);
    }

    /**
     * A mounted source names one identical device on every checkout Node and nothing else.
     *
     * @param  array<array-key, mixed>  $mounts
     * @param  list<string>  $mountedNodes
     */
    private static function validateMounts(array $mounts, array $mountedNodes): void
    {
        if (array_keys($mounts) !== $mountedNodes) {
            throw new InvalidArgumentException('The topology mounts do not match the source state.');
        }

        $sources = [];
        foreach ($mounts as $mount) {
            if (
                ! is_array($mount)
                || array_keys($mount) !== ['device', 'source', 'path']
                || $mount['device'] !== self::SOURCE_DEVICE
                || ! is_string($mount['source'])
                || ! is_string($mount['path'])
                || ! MountPath::isSafe($mount['source'])
                || ! MountPath::isSafe($mount['path'])
            ) {
                throw new InvalidArgumentException('A topology mount is invalid.');
            }
            $sources[$mount['source'].':'.$mount['path']] = true;
        }

        if (count($sources) > 1) {
            throw new InvalidArgumentException('Every topology mount must share one source and one path.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'issue' => $this->target->issue,
            'attempt_id' => $this->attempt->value,
            'purpose' => $this->purpose->value,
            'nodes' => $this->target->recipe->nodeKeys(),
            'construction' => $this->construction->toArray(),
            'network' => $this->network,
            'instances' => $this->instances,
            'mounts' => $this->mounts,
            'source' => $this->source->toArray(),
            'verification' => $this->verification->toArray(),
        ];
    }

    /** @param array<array-key, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (($value['schema'] ?? null) !== self::SCHEMA) {
            $version = json_encode($value['schema'] ?? null, JSON_THROW_ON_ERROR);

            throw new InvalidArgumentException(
                "The feature topology schema {$version} is not supported; release it with the harness that created it.",
            );
        }

        if (
            array_keys($value) !== [
                'schema',
                'issue',
                'attempt_id',
                'purpose',
                'nodes',
                'construction',
                'network',
                'instances',
                'mounts',
                'source',
                'verification',
            ]
            || $value['purpose'] !== AttemptPurpose::Discovery->value
            || ! is_array($value['construction'])
            || ! is_array($value['mounts'])
            || ! is_array($value['source'])
            || ! is_array($value['verification'])
        ) {
            throw new InvalidArgumentException('The feature topology schema is invalid.');
        }

        $topology = new self(
            TopologyConstructionInputs::fromArray($value['construction']),
            SourceState::fromArray($value['source']),
            VerificationReport::fromArray($value['verification']),
            SerializedArrays::mounts($value['mounts']),
        );
        if (
            $value['issue'] !== $topology->target->issue
            || $value['attempt_id'] !== $topology->attempt->value
            || $value['nodes'] !== $topology->target->recipe->nodeKeys()
            || $value['network'] !== $topology->network
            || $value['instances'] !== $topology->instances
        ) {
            throw new InvalidArgumentException('The feature topology identity does not match its construction inputs.');
        }

        return $topology;
    }
}
