<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Actions;

use Illuminate\Validation\ValidationException;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Data\EnableAiRouterData;
use Orbit\Extensions\AiRouter\Domain\AiRouterCollectorEnvironment;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostnameRoute;
use Orbit\Extensions\AiRouter\Domain\AiRouterLifecycleLock;
use Orbit\Extensions\AiRouter\Domain\AiRouterPlacement;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterRenameCutover;
use SensitiveParameter;

final readonly class EnableAiRouterAction
{
    public function __construct(
        private AiRouterLifecycleLock $lifecycle,
        private AiRouterRenameCutover $cutover,
        private AiRouterPlacement $placement,
        private AiRouterState $state,
        private AdmitAiRouterOperationAction $operations,
        private AiRouterHostnameRoute $hostnameRoute = new AiRouterHostnameRoute,
    ) {}

    public function execute(#[SensitiveParameter] EnableAiRouterData $data): Operation
    {
        return $this->lifecycle->run(fn (): Operation => DB::transaction(fn (): Operation => $this->setup($data)));
    }

    private function setup(#[SensitiveParameter] EnableAiRouterData $data): Operation
    {
        $this->cutover->assertComplete();
        if (config('task_vms.enabled') === true) {
            $origin = config('task_vms.model_proxy_origin');
            if (! is_string($origin) || rtrim($data->routerUrl, '/') !== rtrim($origin, '/')) {
                throw ValidationException::withMessages([
                    'cliproxy_url' => ['The cliproxy_url must equal task_vms.model_proxy_origin while task VMs are enabled.'],
                ]);
            }
        }

        $node = $this->placement->node($data->nodeId);
        $connection = $this->placement->connection($data->cacheConnection);
        $takeover = $this->hostnameRoute->takeoverCandidate($node);
        $readToken = $this->state->readToken() ?? bin2hex(random_bytes(24));
        $controlToken = $this->state->controlToken() ?? bin2hex(random_bytes(24));
        $this->state->enable(
            $node->id,
            $data->cacheConnection,
            rtrim($data->routerUrl, '/'),
            $data->managementKey,
            $readToken,
            $controlToken,
        );
        return $this->operations->execute('ai_router.setup');
    }
}
