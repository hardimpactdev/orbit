<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Jobs\Operations\RunGitHubAppOperation;
use App\Models\Operation;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/** Consume browser admission once; the Gateway exchanges the encrypted code. */
final readonly class CompleteGitHubAppRegistrationAction
{
    public function __construct(private GitHubAppStore $store, private OperationStore $operations, private GatewayServingHost $gateway) {}

    public function handle(#[SensitiveParameter] string $state, #[SensitiveParameter] string $code, ?string $requestId = null): Operation
    {
        return DB::transaction(function () use ($state, $code, $requestId): Operation {
            Setting::query()->where('scope_type', 'gateway')->where('scope_id', 0)
                ->where('key', 'github.app.registration')->lockForUpdate()->first();
            $registration = $this->store->registration();
            $nodeId = $this->gateway->nodeId();
            if ($registration === null || ! $registration->matches($state, now()->getTimestamp())
                || $this->store->credentials() !== null || $this->store->exchange() !== null || $nodeId === null || $code === '' || strlen($code) > 4096) {
                throw new ResourceOperationException('github.registration_invalid', 'The redirect does not match an unused GitHub App registration.', 422);
            }
            $operation = $this->operations->create('github.app_register', 'node', $nodeId, $nodeId, [
                'registration_id' => hash('sha256', $state),
            ], 'github.app.callback', $requestId);
            $this->store->beginExchange($operation, $code);
            DB::afterCommit(static fn () => RunGitHubAppOperation::dispatch($operation->id));

            return $operation;
        });
    }
}
