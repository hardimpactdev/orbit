<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

final readonly class AiRouterSettings
{
    public const string Enabled = 'ai_router.enabled';

    public const string NodeId = 'ai_router.node_id';

    public const string CacheConnection = 'ai_router.cache_connection';

    public const string RouterUrl = 'ai_router.router_url';

    public const string ManagementKey = 'ai_router.management_key';

    public const string ReadToken = 'ai_router.read_token';

    public const string ControlToken = 'ai_router.control_token';
}
