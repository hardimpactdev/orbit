<?php

declare(strict_types=1);
use Orbit\Extensions\AiRouter\Domain\AiRouterCacheRenameCommand;
use Orbit\Extensions\AiRouter\Domain\AiRouterPrepareCommand;
use Orbit\Extensions\AiRouter\Domain\AiRouterTakeoverRestoreCommand;

return [
    'ai_router.restore-takeover' => AiRouterTakeoverRestoreCommand::class,
    'ai_router.prepare' => AiRouterPrepareCommand::class, 'ai_router.rename-cache' => AiRouterCacheRenameCommand::class];
