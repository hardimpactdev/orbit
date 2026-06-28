<?php

declare(strict_types=1);

namespace App\Commands\Cloudflare;

use App\Exceptions\GatewayApiException;

use function Laravel\Prompts\table;

final class CfDnsListCommand extends CloudflareGatewayCommand
{
    #[\Override]
    protected $signature = 'cf-dns:list {zone? : Cloudflare zone ID or domain} {--json}';

    #[\Override]
    protected $description = 'List Cloudflare DNS records for a zone.';

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        $zone = $this->stringArgument('zone');

        if ($zone === null) {
            return $this->renderFailure('validation_failed', 'The zone argument is required.', ['field' => 'zone']);
        }

        try {
            $response = $this->gatewayGet('/api/cloudflare/zones/'.rawurlencode($zone).'/dns');
        } catch (GatewayApiException $exception) {
            return $this->renderGatewayFailure($exception);
        }

        if ($this->wantsJson()) {
            return $this->renderSuccess($response);
        }

        $records = $this->recordsFromGatewayResponse($response);

        if ($records === []) {
            $this->line("No Cloudflare DNS records found for {$zone}.");

            return self::SUCCESS;
        }

        table(
            headers: ['RECORD ID', 'TYPE', 'NAME', 'CONTENT', 'PROXIED'],
            rows: array_map(fn (array $record): array => [
                $this->recordString($record, 'id'),
                $this->recordString($record, 'type'),
                $this->recordString($record, 'name'),
                $this->recordString($record, 'content'),
                $this->proxiedLabel($record),
            ], $records),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<array<string, mixed>>
     */
    private function recordsFromGatewayResponse(array $response): array
    {
        $records = $response['success']['data']['records'] ?? null;

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter($records, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function proxiedLabel(array $record): string
    {
        $proxied = $record['proxied'] ?? null;

        if (! is_bool($proxied)) {
            return '—';
        }

        return $proxied ? 'yes' : 'no';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordString(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return '—';
    }
}
