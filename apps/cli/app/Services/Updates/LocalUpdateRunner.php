<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Version\VersionInfoResolver;
use Orbit\Core\Progress\StreamedStepTree;

/**
 * Orchestrates the gated, version-aware local `orbit update` flow.
 *
 * The flow always begins with a `Checking for updates` step that resolves the
 * latest release and compares it to the installed CLI version, then applies the
 * gateway-first gate before any download:
 *
 * - Latest release unresolvable → check failure.
 * - Installed version already latest → skip (already installed).
 * - Newer release, gateway is KNOWN and behind it → skip (update gateway first).
 * - Newer release, gateway already on it (or gateway version UNKNOWN, imposing
 *   no ceiling) → download → replace → doctor.
 *
 * Each step is surfaced to the caller through the `$onStep` progress callback so
 * the human renderer can animate the tree. The `update:all` runner keeps using
 * the simpler {@see LocalUpdateWorkflow}; this runner serves the public `update`
 * command only.
 */
final readonly class LocalUpdateRunner
{
    public const string STEP_CHECK = 'check';

    public const string STEP_DOWNLOAD = 'download';

    public const string STEP_REPLACE = 'replace';

    public const string STEP_DOCTOR = 'doctor';

    public function __construct(
        private RunsLocalUpdate $updater,
        private CheckoutPathResolver $checkoutPathResolver,
        private VersionInfoResolver $versionResolver,
        private GatewayVersionProbe $gatewayVersion,
    ) {}

    /**
     * @param  callable(string $stepKey, string $status, ?string $message): void|null  $onStep
     *                                                                                          `$status` is a {@see StreamedStepTree} status
     *                                                                                          (`progress`, `done`, `skip`, or `fail`).
     */
    public function run(?callable $onStep = null): LocalUpdateResult
    {
        $installRoot = $this->checkoutPathResolver->resolve();

        if (! is_dir($installRoot)) {
            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_CHECKOUT_UNAVAILABLE,
                checkoutPath: $installRoot,
            );
        }

        $this->emit($onStep, self::STEP_CHECK, 'start', null);

        $info = $this->versionResolver->resolve();
        $local = $info->version;
        $latest = $info->latestVersion;

        if ($latest === null) {
            $this->emit($onStep, self::STEP_CHECK, 'fail', 'Could not reach the release source');

            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_CHECK_FAILED,
                stepResults: [self::STEP_CHECK => 'failed'],
                failedStep: self::STEP_CHECK,
                output: 'Could not resolve the latest Orbit release version.',
                fromVersion: $local,
            );
        }

        if (! version_compare($latest, $local, '>')) {
            $this->emit($onStep, self::STEP_CHECK, 'skip', "Skipped: {$local} is already installed");

            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_SKIPPED_ALREADY,
                stepResults: [self::STEP_CHECK => 'skipped'],
                fromVersion: $local,
                latestVersion: $latest,
            );
        }

        $gatewayVersion = $this->gatewayVersion->currentVersion();

        if ($gatewayVersion !== null && version_compare($gatewayVersion, $latest, '<')) {
            $this->emit($onStep, self::STEP_CHECK, 'done', "Done: new version available, {$latest}");

            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_SKIPPED_GATEWAY_BEHIND,
                stepResults: [self::STEP_CHECK => 'completed'],
                fromVersion: $local,
                latestVersion: $latest,
            );
        }

        $this->emit($onStep, self::STEP_CHECK, 'done', "new version available, {$latest}");

        return $this->applyUpdate($onStep, $local, $latest);
    }

    /**
     * @param  callable(string, string, ?string): void|null  $onStep
     */
    private function applyUpdate(?callable $onStep, string $local, string $latest): LocalUpdateResult
    {
        $stepResults = [self::STEP_CHECK => 'completed'];

        $this->emit($onStep, self::STEP_DOWNLOAD, 'progress', "Downloading {$latest}");
        $download = $this->updater->downloadBinary();

        if (! $download['successful'] || ! is_string($download['staged_path']) || ! is_string($download['version'])) {
            $stepResults[self::STEP_DOWNLOAD] = 'failed';
            $this->emit($onStep, self::STEP_DOWNLOAD, 'fail', $this->failureMessage($download['output'], $download['exit_code']));

            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_FAILED,
                stepResults: $stepResults,
                failedStep: self::STEP_DOWNLOAD,
                output: $download['output'],
                fromVersion: $local,
                latestVersion: $latest,
            );
        }

        $resolvedVersion = $download['version'];
        $stepResults[self::STEP_DOWNLOAD] = 'completed';
        $this->emit($onStep, self::STEP_DOWNLOAD, 'done', "Downloaded {$latest}");

        $this->emit($onStep, self::STEP_REPLACE, 'progress', "Replacing {$local} with {$latest}");
        $replace = $this->updater->replaceBinary($download['staged_path'], $resolvedVersion);

        if (! $replace['successful']) {
            $stepResults[self::STEP_REPLACE] = 'failed';
            $this->emit($onStep, self::STEP_REPLACE, 'fail', $this->failureMessage($replace['output'], $replace['exit_code']));

            return new LocalUpdateResult(
                status: LocalUpdateResult::STATUS_FAILED,
                stepResults: $stepResults,
                failedStep: self::STEP_REPLACE,
                output: $replace['output'],
                fromVersion: $local,
                latestVersion: $latest,
            );
        }

        $stepResults[self::STEP_REPLACE] = 'completed';
        $this->emit($onStep, self::STEP_REPLACE, 'done', 'Done');

        $this->emit($onStep, self::STEP_DOCTOR, 'progress', 'Running');
        $doctor = $this->updater->runDoctor();
        $issues = $doctor['issues'];

        $stepResults[self::STEP_DOCTOR] = 'completed';
        $this->emit($onStep, self::STEP_DOCTOR, 'done', $this->doctorMessage($issues));

        return new LocalUpdateResult(
            status: LocalUpdateResult::STATUS_COMPLETED,
            stepResults: $stepResults,
            fromVersion: $local,
            toVersion: $latest,
            latestVersion: $latest,
            doctorIssues: $issues,
        );
    }

    private function doctorMessage(?int $issues): string
    {
        if ($issues === null || $issues === 0) {
            return 'Done';
        }

        return $issues === 1 ? '1 issue found' : "{$issues} issues found";
    }

    private function failureMessage(string $output, int $exitCode): string
    {
        return $output !== '' ? $output : "exit code {$exitCode}";
    }

    /**
     * @param  callable(string, string, ?string): void|null  $onStep
     */
    private function emit(?callable $onStep, string $key, string $status, ?string $message): void
    {
        if ($onStep !== null) {
            $onStep($key, $status, $message);
        }
    }
}
