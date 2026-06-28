<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Commands\Concerns\WithStepTree;
use App\Exceptions\GatewayApiException;
use RuntimeException;

final class CfDnsAddCommand extends CloudflareGatewayCommand
{
    use WithStepTree;

    #[\Override]
    protected $signature = 'cf-dns:add
        {name? : DNS record name}
        {content? : IP address for the record}
        {--type=A : DNS record type, A or AAAA}
        {--zone= : Cloudflare zone ID or domain}
        {--proxied : Enable Cloudflare proxy}
        {--json : Output JSON}';

    #[\Override]
    protected $description = 'Add a Cloudflare address DNS record through the gateway.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $name = $this->stringArgument('name');
        $content = $this->stringArgument('content');

        if ($name === null || $content === null) {
            return $this->failValidation(
                $name === null ? 'name' : 'content',
                'DNS record name and content are required.',
            );
        }

        $endpointZone = $this->stringOption('zone') ?? $name;

        if ($this->wantsJson()) {
            try {
                $response = $this->addRecord($name, $content, $endpointZone);
            } catch (GatewayApiException $exception) {
                return $this->renderGatewayFailure($exception);
            }

            return $this->renderSuccess($response);
        }

        return $this->renderAddTree($name, $content, $endpointZone);
    }

    private function renderAddTree(string $name, string $content, string $endpointZone): int
    {
        $response = [];

        $outcome = $this->runStepOperation(
            'Adding Cloudflare DNS record',
            [
                ['label' => 'Resolve Cloudflare zone'],
                ['label' => 'Check existing address records'],
                ['label' => 'Write Cloudflare DNS record'],
            ],
            work: function () use ($name, $content, $endpointZone, &$response): array {
                return $response = $this->addRecordForHuman($name, $content, $endpointZone);
            },
            doneFooter: function () use ($name, $content, &$response): string {
                return $this->successLine($name, $content, $response);
            },
        );

        return $outcome->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function successLine(string $name, string $content, array $response): string
    {
        $record = $this->successData($response);
        $type = is_string($record['type'] ?? null) ? $record['type'] : $this->stringOption('type') ?? 'A';
        $recordName = is_string($record['name'] ?? null) ? $record['name'] : $name;
        $recordContent = is_string($record['content'] ?? null) ? $record['content'] : $content;
        $state = ($this->successMeta($response)['already_present'] ?? false) === true ? 'already present' : 'created';

        return "Cloudflare DNS record {$recordName} {$type} -> {$recordContent} {$state}";
    }

    /**
     * @return array<string, mixed>
     */
    private function addRecord(string $name, string $content, string $endpointZone): array
    {
        return $this->gatewayPost('/api/cloudflare/zones/'.rawurlencode($endpointZone).'/dns', [
            'name' => $name,
            'content' => $content,
            'type' => $this->stringOption('type') ?? 'A',
            'proxied' => $this->option('proxied') === true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function addRecordForHuman(string $name, string $content, string $endpointZone): array
    {
        try {
            return $this->addRecord($name, $content, $endpointZone);
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

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function successMeta(array $response): array
    {
        $meta = $response['success']['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }
}
