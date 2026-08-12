<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Models\Node;
use App\Services\Processes\RemoteRuntimeHibernation;
use Throwable;

final readonly class DockerRuntimeHibernationLookup
{
    public function __construct(
        private RemoteRuntimeHibernation $runtimeHibernation,
    ) {}

    /**
     * @param  list<string>  $scopeKeys
     * @return list<string>|null
     */
    public function hibernatedKeys(Node $node, array $scopeKeys): ?array
    {
        try {
            $states = $this->runtimeHibernation->states($node, $scopeKeys);
        } catch (Throwable) {
            return null;
        }

        if ($states === null) {
            return null;
        }

        return array_values(array_map(
            static fn (array $state): string => $state['key'],
            array_filter(
                $states,
                static fn (array $state): bool => $state['hibernated'],
            ),
        ));
    }
}
