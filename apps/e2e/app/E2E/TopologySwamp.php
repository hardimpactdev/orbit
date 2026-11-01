<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** The private native runtime beside one topology's Gateway. */
final readonly class TopologySwamp
{
    public function __construct(private IncusHost $host, private ?string $clubTokenFile = null) {}

    public function assertCredential(): void
    {
        $this->credential();
    }

    public function prepare(TopologyTarget $target): void
    {
        $result = $this->host->exec($target->instance('gateway'), new GuestCommand(
            ['/usr/local/bin/converge-swamp.py', 'prepare'], 900, $this->credential(),
        ));
        if (! $result->successful()) {
            // Native CLI output can contain newly minted credentials; never publish it.
            $code = preg_match('/\Ae2e\.swamp_[a-z_]+\z/D', trim($result->stderr)) === 1
                ? trim($result->stderr) : 'e2e.swamp_setup_failed';
            throw new RuntimeException('e2e.swamp_prepare_failed: '.$code.'.');
        }
    }

    public function refresh(TopologyTarget $target): void
    {
        $result = $this->host->exec($target->instance('gateway'), new GuestCommand(
            ['/usr/local/bin/converge-swamp.py', 'refresh'], 900,
        ));
        if (! $result->successful()) {
            throw new RuntimeException('e2e.swamp_refresh_failed: sandbox definitions could not be loaded.');
        }
    }

    private function credential(): string
    {
        if ($this->clubTokenFile === null || $this->clubTokenFile === '') {
            throw new RuntimeException('e2e.swamp_club_credential_missing: set ORBIT_E2E_SWAMP_CLUB_TOKEN_FILE.');
        }
        $stat = @lstat($this->clubTokenFile);
        if ($stat === false) {
            throw new RuntimeException('e2e.swamp_club_credential_missing: the sandbox credential file is absent.');
        }
        if (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
            || $stat['uid'] !== posix_geteuid() || $stat['size'] < 1 || $stat['size'] > 16_384) {
            throw new RuntimeException('e2e.swamp_club_credential_invalid: use an owned mode-0600 regular file.');
        }
        $value = @file_get_contents($this->clubTokenFile);
        if (! is_string($value) || trim($value) === '' || preg_match('/[\x00-\x20]/', trim($value)) === 1) {
            throw new RuntimeException('e2e.swamp_club_credential_invalid: the sandbox credential is malformed.');
        }

        return trim($value);
    }
}
