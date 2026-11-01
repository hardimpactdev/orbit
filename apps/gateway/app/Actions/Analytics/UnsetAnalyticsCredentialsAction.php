<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Analytics\AnalyticsCredentialsData;
use App\Domain\Analytics\AnalyticsPlausibleCredentials;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use Illuminate\Support\Facades\DB;

final readonly class UnsetAnalyticsCredentialsAction
{
    public function __construct(
        private AnalyticsStatsKeyStore $keys,
        private AnalyticsPlausibleCredentials $credentials,
        private ShowAnalyticsCredentialsAction $show,
    ) {}

    public function execute(): AnalyticsCredentialsData
    {
        DB::transaction(function (): void {
            $this->keys->clear();
            $this->credentials->clearManagement();
        });

        return $this->show->execute();
    }
}
