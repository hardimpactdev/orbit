<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class LocalUpdateResult
{
    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CHECKOUT_UNAVAILABLE = 'checkout_unavailable';

    /**
     * @param  array<string, string>  $stepResults
     */
    public function __construct(
        public string $status,
        public array $stepResults = [],
        public ?string $failedStep = null,
        public string $output = '',
        public ?string $checkoutPath = null,
    ) {}

    public function successful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
