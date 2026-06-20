<?php

declare(strict_types=1);

namespace App\Commands\Operation;

use App\Commands\LocalOnlyCommand;
use App\Services\Version\VersionInfo;
use App\Services\Version\VersionInfoResolver;
use Carbon\CarbonImmutable;
use DateTimeZone;

final class VersionCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'version {--local : Read only local installed metadata without checking release sources} {--json : Output JSON}';

    #[\Override]
    protected $description = 'Show Orbit version and release metadata';

    public function handle(VersionInfoResolver $versions): int
    {
        $info = $this->option('local')
            ? $versions->resolveLocal()
            : $versions->resolve();

        if ($this->wantsJson()) {
            return $this->renderSuccess($info->toArray());
        }

        $this->line($this->row('Version', $this->versionLabel($info)));
        $this->line($this->row('Released at', $this->formatTimestamp($info->releasedAt)));
        $this->line($this->row('Installed at', $this->formatTimestamp($info->installedAt)));

        return self::SUCCESS;
    }

    private function versionLabel(VersionInfo $info): string
    {
        if (! $info->updateAvailable || $info->latestVersion === null) {
            return $info->version;
        }

        return "{$info->version} (new version available: {$info->latestVersion})";
    }

    private function row(string $label, string $value): string
    {
        return sprintf('%-14s%s', $label, $value);
    }

    private function formatTimestamp(?string $timestamp): string
    {
        if ($timestamp === null) {
            return 'unknown';
        }

        return CarbonImmutable::parse($timestamp)
            ->setTimezone($this->displayTimezone())
            ->format('d-m-Y - H:i');
    }

    private function displayTimezone(): DateTimeZone
    {
        foreach ($this->displayTimezoneCandidates() as $timezone) {
            $timezone = trim($timezone);

            if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
                return new DateTimeZone($timezone);
            }
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * @return list<string>
     */
    private function displayTimezoneCandidates(): array
    {
        $candidates = [
            getenv('ORBIT_DISPLAY_TIMEZONE'),
            getenv('TZ'),
            $this->timezoneFromLocaltimeSymlink(),
            $this->timezoneFromEtcTimezone(),
        ];

        return array_values(array_filter($candidates, is_string(...)));
    }

    private function timezoneFromLocaltimeSymlink(): ?string
    {
        $target = @readlink('/etc/localtime');

        if (! is_string($target) || $target === '') {
            return null;
        }

        foreach (['/usr/share/zoneinfo/', '/var/db/timezone/zoneinfo/'] as $prefix) {
            if (str_starts_with($target, $prefix)) {
                return substr($target, strlen($prefix));
            }
        }

        return null;
    }

    private function timezoneFromEtcTimezone(): ?string
    {
        if (! is_file('/etc/timezone') || ! is_readable('/etc/timezone')) {
            return null;
        }

        $timezone = file_get_contents('/etc/timezone');

        if ($timezone === false) {
            return null;
        }

        return trim($timezone);
    }
}
