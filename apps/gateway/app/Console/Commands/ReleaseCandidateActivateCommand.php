<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Release\CandidateReleaseChannelActivator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

#[Signature('orbit:release-candidate:activate
    {buildId : Candidate build id under candidates/<BUILD_ID>/}
    {--channel=live-test : Stable candidate channel name}
    {--manifest=orbit-release-manifest.candidate.json : Candidate manifest filename inside the candidate build directory}
    {--json : Output machine-readable JSON}')]
#[Description('Activate an immutable release candidate manifest on a stable artifact channel')]
class ReleaseCandidateActivateCommand extends Command
{
    public function handle(CandidateReleaseChannelActivator $activator): int
    {
        try {
            $result = $activator->activate(
                buildId: (string) $this->argument('buildId'),
                channel: (string) $this->option('channel'),
                manifest: (string) $this->option('manifest'),
            );
        } catch (RuntimeException $exception) {
            return $this->failure($exception->getMessage());
        }

        if ($this->wantsJson()) {
            $this->line($this->json([
                'success' => [
                    'data' => $result,
                ],
            ]));

            return self::SUCCESS;
        }

        $this->info("Activated release candidate channel [{$result['channel']}].");
        $this->line("Build: {$result['build_id']}");
        $this->line("Manifest: {$result['manifest_url']}");

        return self::SUCCESS;
    }

    private function failure(string $message): int
    {
        if ($this->wantsJson()) {
            $this->line($this->json([
                'error' => [
                    'code' => 'release_candidate_activation_failed',
                    'message' => $message,
                ],
            ]));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): string
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode release candidate activation response.', previous: $exception);
        }

        if (is_string($encoded)) {
            return $encoded;
        }

        throw new RuntimeException('Could not encode release candidate activation response.');
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }
}
