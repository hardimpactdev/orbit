<?php

declare(strict_types=1);

namespace App\Services\Updates;

final readonly class LocalUpdateResult
{
    /** A newer release existed, the gate allowed it, and the binary was swapped. */
    public const string STATUS_COMPLETED = 'completed';

    /** The installed version already equals the latest release; no download. */
    public const string STATUS_SKIPPED_ALREADY = 'skipped_already';

    /** A newer release exists but the gateway is still behind it; no download. */
    public const string STATUS_SKIPPED_GATEWAY_BEHIND = 'skipped_gateway_behind';

    /** A step failed after the gate allowed the update. */
    public const string STATUS_FAILED = 'failed';

    /** The latest release version could not be resolved from the release source. */
    public const string STATUS_CHECK_FAILED = 'check_failed';

    /** The local install root does not exist and cannot be updated. */
    public const string STATUS_INSTALLATION_UNAVAILABLE = 'installation_unavailable';

    /**
     * @param  array<string, string>  $stepResults  Ordered step key => terminal status.
     */
    public function __construct(
        public string $status,
        public array $stepResults = [],
        public ?string $failedStep = null,
        public string $output = '',
        public ?string $installationPath = null,
        public ?string $fromVersion = null,
        public ?string $toVersion = null,
        public ?string $latestVersion = null,
        public ?int $doctorIssues = null,
    ) {}

    public function successful(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * A successful, no-side-effect outcome (exit 0) that performed no binary swap.
     */
    public function skipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED_ALREADY || $this->status === self::STATUS_SKIPPED_GATEWAY_BEHIND;
    }

    /**
     * Both completed updates and skips exit 0; only failures exit non-zero.
     */
    public function isExitSuccess(): bool
    {
        return $this->successful() || $this->skipped();
    }
}
