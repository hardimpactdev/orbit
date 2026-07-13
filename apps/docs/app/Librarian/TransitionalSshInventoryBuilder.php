<?php

declare(strict_types=1);

namespace App\Librarian;

use RuntimeException;

final readonly class TransitionalSshInventoryBuilder
{
    public function __construct(
        private TransitionalSshConsumerFinder $consumerFinder,
        private TransitionalSshConsumerClassifier $consumerClassifier,
    ) {}

    /**
     * @return array{
     *     schema_version: int,
     *     generated_from: array<string, mixed>,
     *     policy: array<string, string>,
     *     provisioning_ssh: list<array{path: string, call_line: int, marker_line: int, edge: string}>,
     *     transitional_ssh: list<array{path: string, call_line: int, marker_line: int, edge: string}>,
     *     unmarked_consumers: list<string>,
     * }
     */
    public function build(): array
    {
        $classifiedConsumers = $this->consumerClassifier->classify($this->consumerFinder->find());

        return [
            'schema_version' => 2,
            'generated_from' => [
                'source_roots' => $this->consumerFinder->sourceRoots(),
                'edge_patterns' => $this->consumerFinder->edgePatterns(),
                'cli_selector_patterns' => $this->consumerFinder->cliSelectorPatterns(),
            ],
            'policy' => [
                'permanent_lane' => 'provisioning/bootstrap only',
                'provisioning_marker' => $this->consumerClassifier->provisioningMarker(),
                'transitional_marker' => $this->consumerClassifier->transitionalMarker(),
                'steady_state_lane' => 'Agent push or gateway-local execution',
            ],
            'provisioning_ssh' => $classifiedConsumers['provisioning_ssh'],
            'transitional_ssh' => $classifiedConsumers['transitional_ssh'],
            'unmarked_consumers' => array_map(
                static fn (array $edge): string => "{$edge['path']}:{$edge['call_line']} ({$edge['edge']})",
                $classifiedConsumers['unmarked_consumers'],
            ),
        ];
    }

    public function artifactPath(): string
    {
        return base_path('content/generated/transitional-ssh-inventory.json');
    }

    /**
     * @param  array<string, mixed>  $inventory
     */
    public function toJson(array $inventory): string
    {
        return json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function write(): string
    {
        $inventory = $this->build();

        if ($inventory['unmarked_consumers'] !== []) {
            throw new RuntimeException(
                'Refusing to write an SSH inventory with unmarked consumers: '
                    .implode(', ', $inventory['unmarked_consumers']),
            );
        }

        $path = $this->artifactPath();

        if (file_put_contents($path, $this->toJson($inventory)) === false) {
            throw new RuntimeException("Unable to write transitional SSH inventory: {$path}");
        }

        return $path;
    }

    public function isFresh(): bool
    {
        $inventory = $this->build();

        return (
            $inventory['unmarked_consumers'] === []
            && is_file($this->artifactPath())
            && file_get_contents($this->artifactPath()) === $this->toJson($inventory)
        );
    }
}
