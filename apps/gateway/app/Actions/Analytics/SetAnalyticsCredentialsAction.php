<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\AnalyticsCredentialsData;
use App\Domain\Analytics\AnalyticsPlausibleCredentials;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class SetAnalyticsCredentialsAction
{
    public function __construct(
        private AnalyticsStatsKeyStore $keys,
        private AnalyticsPlausibleCredentials $credentials,
        private ShowAnalyticsCredentialsAction $show,
    ) {}

    public function execute(#[SensitiveParameter] string $apiKey, #[SensitiveParameter] ?string $managementEmail = null, #[SensitiveParameter] ?string $managementPassword = null): AnalyticsCredentialsData
    {
        DB::transaction(function () use ($apiKey, $managementEmail, $managementPassword): void {
            $this->keys->put($apiKey);
            $this->credentials->storeManagement($managementEmail, $managementPassword);
        });

        return $this->show->execute();
    }
}
