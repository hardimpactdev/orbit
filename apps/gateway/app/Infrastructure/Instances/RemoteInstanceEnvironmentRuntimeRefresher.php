<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentRuntimeRefresher;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use Throwable;

/**
 * After an environment change, rebuilds a production Instance's cached configuration through the Node agent's
 * `instance.refresh-config-cache`, then refreshes its PHP cache.
 */
final readonly class RemoteInstanceEnvironmentRuntimeRefresher implements InstanceEnvironmentRuntimeRefresher
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ProductionPhpRuntimeManager $runtime,
    ) {}

    public function refresh(InstanceEnvironmentContext $context): string
    {
        try {
            $instance = Instance::query()->findOrFail($context->instanceId);
        } catch (Throwable) {
            $this->configFailed();
        }

        if ($instance->selected_php_version === null || $instance->selected_php_version === '') {
            return 'skipped_no_php_version';
        }

        try {
            $outcome = $this->agents->run($context->node, 'instance.refresh-config-cache', [
                'user' => $context->executionUser,
                'home' => $context->path,
                'version' => $instance->selected_php_version,
                'application' => $instance->applicationDirectory(),
            ])['outcome'] ?? null;
        } catch (Throwable) {
            $this->configFailed();
        }

        if ($outcome === 'no_current_release') {
            return 'skipped_no_current_release';
        }

        if (! in_array($outcome, ['config_cached', 'config_uncached'], true)) {
            $this->configFailed();
        }

        try {
            $this->runtime->refreshCache($instance);
        } catch (Throwable) {
            throw new ResourceOperationException(
                errorCode: 'env.php_runtime_refresh_failed',
                message: 'The environment file changed and config cache preparation completed, but the production FPM opcache refresh failed.',
                status: 502,
            );
        }

        return $outcome === 'config_cached' ? 'config_cache_and_opcache_refreshed' : 'opcache_refreshed_config_uncached';
    }

    private function configFailed(): never
    {
        throw new ResourceOperationException(
            errorCode: 'env.config_cache_refresh_failed',
            message: 'The environment file was synchronized, but production config cache preparation failed or was unconfirmed. A failed config build keeps the old cache. FPM opcache was not reset. Retry env-sync.',
            status: 502,
        );
    }
}
