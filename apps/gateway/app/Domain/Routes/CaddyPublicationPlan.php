<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;

/** The complete Node publication input, including role-dependent serving state. */
final readonly class CaddyPublicationPlan
{
    /** @return array{content: string, version: string, listen_addresses: list<string>, unserved: bool} */
    public static function input(NodeCaddyfile $render, int $nodeId): array
    {
        return ['content' => $render->content, 'version' => $render->version, 'listen_addresses' => $render->listenAddresses,
            'unserved' => $render->sites === [] || ! CaddySiteRoles::nodeExpectsCaddy($nodeId)];
    }
}
