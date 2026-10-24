<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Config;

/** The Gateway binds native recovery evidence to the original staged record. */
final readonly class GatewayReleaseRecovery
{
    public function __construct(private GatewayReleaseCommands $commands) {}

    /** @return array{outcome: string, original: string, path: string, sha256: string} */
    public function protect(GatewayRelease $record): array
    {
        $saved = $record->phases['recovery'] ?? null;
        if (is_array($saved)) {
            $this->verify($record);

            return ['outcome' => 'verified', 'original' => $this->text($saved, 'original'), 'path' => $this->text($saved, 'path'), 'sha256' => $this->text($saved, 'sha256')];
        }
        $source = $record->snapshot_path;
        if (! is_string($source) || ! is_string($record->sha)) {
            $this->refuse();
        }
        $result = $this->commands->protectRecovery(rtrim(Config::string('orbit.home'), '/'), $record->id, $record->sha, $source);

        return ['outcome' => 'verified', 'original' => $source, 'path' => $result['path'], 'sha256' => $result['sha256']];
    }

    public function verify(GatewayRelease $record): void
    {
        $saved = $record->phases['recovery'] ?? null;
        $home = rtrim(Config::string('orbit.home'), '/');
        if (! is_string($record->sha) || ! is_array($saved) || ($saved['outcome'] ?? null) !== 'verified'
            || ($saved['path'] ?? null) !== $home.'/recovery/stage-'.$record->id.'-'.$record->sha.'.sqlite'
            || ! is_string($saved['sha256'] ?? null)) {
            $this->refuse();
        }
        $this->commands->verifyRecovery($home, $record->id, $record->sha, $saved['sha256']);
    }

    /** @param array<mixed> $values */
    private function text(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value)) {
            $this->refuse();
        }

        return $value;
    }

    private function refuse(): never
    {
        throw new GatewayReleaseException('recovery', 'gateway.release_recovery_invalid', 'The original staged recovery copy could not be privately preserved and verified. Keep the hold.');
    }
}
