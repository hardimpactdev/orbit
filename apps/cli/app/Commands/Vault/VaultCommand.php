<?php

declare(strict_types=1);

namespace App\Commands\Vault;

use App\Commands\GatewayCommand;
use App\Commands\Integrations\FollowsIntegrationOperations;
use App\Support\Console\ConsoleWriter;
use App\Support\GatedExtensionCommand;
use Orbit\Sdk\Responses\Vault\VaultStatusResponse;

/** The `vault:*` commands belong to the vault extension and follow its Gateway switch. */
abstract class VaultCommand extends GatewayCommand implements GatedExtensionCommand
{
    use FollowsIntegrationOperations;

    public function extensionSlug(): ?string
    {
        return 'vault';
    }

    protected function renderStatus(VaultStatusResponse $response, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'Configured' => $response->configured,
            'URL' => $response->url,
            'Email' => $response->email,
            'Organization' => self::reference($response->organization),
            'Collection' => self::reference($response->collection),
            'Items' => $response->items,
            'Pending' => $response->pending,
            'Skipped' => count($response->skipped),
            'Last synced at' => $response->lastSyncedAt,
            'Last error' => $response->lastError === null
                ? null
                : $response->lastError['code'].': '.$response->lastError['message'],
            'Request ID' => $response->requestId,
        ]));

        if ($response->skipped !== []) {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Source', 'Slug', 'Username', 'Reason'],
                array_map(static fn (array $row): array => [
                    $row['source'],
                    $row['slug'],
                    $row['username'],
                    $row['reason'],
                ], $response->skipped),
            ));
        }

        return self::SUCCESS;
    }

    /** @param array{id: string, name: string}|null $reference */
    private static function reference(?array $reference): ?string
    {
        return $reference === null ? null : "{$reference['name']} ({$reference['id']})";
    }
}
