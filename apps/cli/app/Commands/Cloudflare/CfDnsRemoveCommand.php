<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfDnsRemoveCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-dns:remove
        {record-id? : Cloudflare DNS record ID}
        {--zone= : Cloudflare zone ID or domain}
        {--force : Confirm destructive operation without prompting}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Remove a Cloudflare address DNS record through the gateway.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $recordId = $this->requiredArgument(
            'record-id',
            'record_id',
            'A Cloudflare DNS record ID and zone are required.',
        );

        if (is_int($recordId)) {
            return $recordId;
        }

        $zone = $this->stringOption('zone');

        if ($zone === null) {
            return $this->failValidation('zone', 'A Cloudflare DNS record ID and zone are required.');
        }

        $consent = $this->confirmDestructive(
            'Removing a Cloudflare DNS record requires --force in non-interactive mode.',
        );

        if (is_int($consent)) {
            return $consent;
        }

        if ($this->wantsJson()) {
            try {
                $response = $this->removeRecord($recordId, $zone);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderRemoveTree($recordId, $zone);
    }

    private function renderRemoveTree(string $recordId, string $zone): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Removing Cloudflare DNS record',
            [
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Verify DNS record'],
                ['label' => 'Delete Cloudflare DNS record'],
            ],
            work: function () use ($recordId, $zone, &$response): array {
                return $response = $this->removeRecordForHuman($recordId, $zone);
            },
            doneFooter: function () use ($recordId, $zone, &$response): string {
                $resolvedZone = $this->successData($response)['zone'] ?? null;
                $displayZone = is_string($resolvedZone) && $resolvedZone !== '' ? $resolvedZone : $zone;

                return "Cloudflare DNS record {$recordId} removed from {$displayZone}";
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRecord(string $recordId, string $zone): array
    {
        return $this->gatewayDelete(
            '/api/cloudflare/zones/'.rawurlencode($zone).'/dns/'.rawurlencode($recordId),
            ['destructive_consent' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function removeRecordForHuman(string $recordId, string $zone): array
    {
        try {
            return $this->removeRecord($recordId, $zone);
        } catch (GatewayApiException $exception) {
            throw new RuntimeException(
                $exception->gatewayErrorMessage() ?? $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successData(array $response): array
    {
        $record = $response['success']['data']['record'] ?? null;

        return is_array($record) ? $record : [];
    }
}
