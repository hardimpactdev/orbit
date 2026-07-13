<?php

declare(strict_types=1);

namespace App\Librarian;

use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class TransitionalSshConsumerClassifier
{
    private const string TRANSITIONAL_MARKER = '@orbit-ssh-lane transitional-ssh';

    private const string PROVISIONING_MARKER = '@orbit-ssh-lane provisioning-ssh';

    public function __construct(
        private TransitionalSshConsumerFinder $consumerFinder,
    ) {}

    /**
     * @param  array<string, string>  $consumers
     * @return array{
     *     provisioning_ssh: list<array{path: string, call_line: int, marker_line: int, edge: string}>,
     *     transitional_ssh: list<array{path: string, call_line: int, marker_line: int, edge: string}>,
     *     unmarked_consumers: list<array{path: string, call_line: int, edge: string}>,
     * }
     */
    public function classify(array $consumers): array
    {
        $provisioning = [];
        $transitional = [];
        $unmarked = [];

        foreach ($consumers as $path => $contents) {
            $markers = $this->laneMarkers($contents);
            $consumedMarkerLines = [];

            foreach ($this->consumerFinder->edgesIn($path, $contents) as $edge) {
                $attachedMarkers = $this->attachedMarkers($edge['call_line'], $markers);

                if ($attachedMarkers === []) {
                    $unmarked[] = $edge;

                    continue;
                }

                $lanes = array_values(array_unique(array_column($attachedMarkers, 'lane')));

                if (count($lanes) > 1) {
                    throw new RuntimeException("SSH consumer {$path} declares both execution-lane markers.");
                }

                foreach ($attachedMarkers as $marker) {
                    $consumedMarkerLines[$marker['line']] = true;
                }

                $classifiedEdge = [
                    'path' => $edge['path'],
                    'call_line' => $edge['call_line'],
                    'marker_line' => max(array_column($attachedMarkers, 'line')),
                    'edge' => $edge['edge'],
                ];

                if ($lanes[0] === 'transitional-ssh') {
                    $transitional[] = $classifiedEdge;

                    continue;
                }

                $provisioning[] = $classifiedEdge;
            }

            foreach (array_keys($markers) as $line) {
                if (array_key_exists($line, $consumedMarkerLines)) {
                    continue;
                }

                throw new RuntimeException("SSH lane marker {$path}:{$line} is not attached to an SSH call edge.");
            }
        }

        $this->sortClassifiedEdges($provisioning);
        $this->sortClassifiedEdges($transitional);
        $this->sortUnmarkedEdges($unmarked);

        return [
            'provisioning_ssh' => $provisioning,
            'transitional_ssh' => $transitional,
            'unmarked_consumers' => $unmarked,
        ];
    }

    public function transitionalMarker(): string
    {
        return self::TRANSITIONAL_MARKER;
    }

    public function provisioningMarker(): string
    {
        return self::PROVISIONING_MARKER;
    }

    /**
     * @return array<int, list<string>>
     */
    private function laneMarkers(string $contents): array
    {
        $markers = [];

        foreach (explode("\n", $contents) as $index => $line) {
            $lineNumber = $index + 1;

            if (str_contains($line, self::TRANSITIONAL_MARKER)) {
                $markers[$lineNumber][] = 'transitional-ssh';
            }

            if (str_contains($line, self::PROVISIONING_MARKER)) {
                $markers[$lineNumber][] = 'provisioning-ssh';
            }
        }

        return $markers;
    }

    /**
     * @param  array<int, list<string>>  $markers
     * @return list<array{line: int, lane: string}>
     */
    private function attachedMarkers(int $callLine, array $markers): array
    {
        $attached = [];
        $line = $callLine - 1;

        while (array_key_exists($line, $markers)) {
            foreach ($markers[$line] as $lane) {
                $attached[] = [
                    'line' => $line,
                    'lane' => $lane,
                ];
            }

            $line--;
        }

        return $attached;
    }

    /**
     * @param  list<array{path: string, call_line: int, marker_line: int, edge: string}>  $edges
     */
    private function sortClassifiedEdges(array &$edges): void
    {
        usort(
            $edges,
            static fn (array $left, array $right): int => (
                [
                    $left['path'],
                    $left['call_line'],
                    $left['edge'],
                ] <=> [
                    $right['path'],
                    $right['call_line'],
                    $right['edge'],
                ]
            ),
        );
    }

    /**
     * @param  list<array{path: string, call_line: int, edge: string}>  $edges
     */
    private function sortUnmarkedEdges(array &$edges): void
    {
        usort(
            $edges,
            static fn (array $left, array $right): int => (
                [
                    $left['path'],
                    $left['call_line'],
                    $left['edge'],
                ] <=> [
                    $right['path'],
                    $right['call_line'],
                    $right['edge'],
                ]
            ),
        );
    }
}
