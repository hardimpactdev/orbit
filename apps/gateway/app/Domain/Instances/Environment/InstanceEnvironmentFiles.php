<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Instances\InstanceState;
use App\Models\Instance;
use App\Models\Node;

/**
 * The `.env` of each Instance on a Node, which the Node agent reports as `env_keys` facts. A production `.env` lives in
 * its account's home under `/home`, which the agent's unit binds back read-only; Orbit grants the `orbit-env` group
 * traverse on that home, as it grants Caddy traverse on a release.
 */
final readonly class InstanceEnvironmentFiles
{
    public function __construct(private InstanceEnvironmentContextResolver $resolver) {}

    /** @return list<string> sorted */
    public function forNode(Node $node): array
    {
        return array_keys($this->placed($node));
    }

    /**
     * The production homes among them, which the agent's unit binds back and `orbit-env` may traverse.
     *
     * @return list<string> sorted
     */
    public function homes(Node $node): array
    {
        return array_values(array_unique(array_filter($this->placed($node))));
    }

    /** @return array<string, ?string> each `.env`, sorted, with its production home or null */
    private function placed(Node $node): array
    {
        $files = [];
        foreach (Instance::query()->where('node_id', $node->id)->whereNot('status', InstanceState::Removing)->with('node')->lazyById() as $instance) {
            $file = $this->resolver->file($instance);
            if ($file !== null) {
                $files[$file] = $instance->placementEnvironment() === 'production' ? dirname($file) : null;
            }
        }
        ksort($files);

        return $files;
    }
}
