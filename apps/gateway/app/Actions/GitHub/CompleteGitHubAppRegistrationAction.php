<?php

declare(strict_types=1);

namespace App\Actions\GitHub;

use App\Data\GitHub\GitHubAppData;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\HttpGitHubAppManagement;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;

/**
 * GitHub's redirect after the operator confirmed the App. The Gateway consumes the registration once, exchanges the
 * one-time code itself and stores the App key encrypted; the key never leaves the Gateway. No Operation or run starts.
 */
final readonly class CompleteGitHubAppRegistrationAction
{
    public function __construct(private GitHubAppStore $store, private HttpGitHubAppManagement $github) {}

    public function handle(#[SensitiveParameter] string $state, #[SensitiveParameter] string $code): GitHubAppData
    {
        DB::transaction(function () use ($state, $code): void {
            $this->lock();
            $registration = $this->store->registration();
            if ($registration === null || ! $registration->matches($state, now()->getTimestamp())
                || $this->store->credentials() !== null || $code === '' || strlen($code) > 4096) {
                throw new ResourceOperationException('github.registration_invalid', 'The redirect does not match an unused GitHub App registration.', 422);
            }
            // A state is good for one exchange; a retried redirect starts over with github:app:install.
            $this->store->clearRegistration();
        });
        try {
            $credentials = $this->github->register($code);
        } catch (RuntimeException) {
            throw new ResourceOperationException('github.registration_failed', 'GitHub refused to exchange the one-time code. Run github:app:install again.', 502);
        }
        DB::transaction(function () use ($credentials): void {
            $this->lock();
            if ($this->store->credentials() !== null) {
                throw new ResourceOperationException('github.registration_invalid', 'The Gateway already has a GitHub App.', 422);
            }
            $this->store->put($credentials);
        });

        return GitHubAppData::fromStore($this->store);
    }

    private function lock(): void
    {
        Setting::query()->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', 'like', 'github.app%')->lockForUpdate()->get();
    }
}
