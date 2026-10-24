<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

final readonly class GatewayReleaseInventory
{
    /**
     * @param  array<string, string>  $commits
     * @param  array<string, int>  $incomplete
     * @param  list<string>  $retained
     */
    private function __construct(
        public ?string $current,
        public string $state,
        public bool $superseded,
        public array $commits,
        public array $incomplete,
        public array $retained,
    ) {}

    /** @param array<string, mixed> $result */
    public static function fromAgent(array $result): self
    {
        $invalid = static fn (): GatewayReleaseException => new GatewayReleaseException('inspect', 'gateway.release_observation_failed', 'The agent returned an invalid release inventory.', 500);
        if (! array_key_exists('current', $result)
            || ($result['current'] !== null && (! is_string($result['current']) || ! GatewayReleaseCommit::isId($result['current'])))
            || ! is_array($result['commits'] ?? null)
            || ! in_array($result['state'] ?? null, ['adopted', 'in_place', 'unreadable'], true)
            || ! is_bool($result['superseded'] ?? null)
            || ! is_array($result['incomplete'] ?? null)
            || ! is_array($result['retained'] ?? null) || ! array_is_list($result['retained'])) {
            throw $invalid();
        }
        foreach ($result['commits'] as $id => $sha) {
            if (! GatewayReleaseCommit::isId((string) $id) || ! is_string($sha) || ! GatewayReleaseCommit::isSha($sha) || ! str_starts_with($sha, (string) $id)) {
                throw $invalid();
            }
        }
        foreach ($result['incomplete'] as $id => $modified) {
            if (! GatewayReleaseCommit::isId((string) $id) || ! is_int($modified) || array_key_exists($id, $result['commits'])) {
                throw $invalid();
            }
        }
        foreach ($result['retained'] as $id) {
            if (! is_string($id) || ! GatewayReleaseCommit::isId($id) || ! array_key_exists($id, $result['commits'])) {
                throw $invalid();
            }
        }
        if (count(array_unique($result['retained'])) !== count($result['retained'])
            || count($result['retained']) !== count($result['commits'])
            || ($result['state'] === 'adopted') !== ($result['current'] !== null && array_key_exists($result['current'], $result['commits']))
            || ($result['state'] === 'in_place' && $result['current'] !== null)) {
            throw $invalid();
        }

        return new self($result['current'], $result['state'], $result['superseded'], $result['commits'], $result['incomplete'], $result['retained']);
    }
}
