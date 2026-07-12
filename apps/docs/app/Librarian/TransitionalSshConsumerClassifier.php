<?php

declare(strict_types=1);

namespace App\Librarian;

use RuntimeException;

final readonly class TransitionalSshConsumerClassifier
{
    private const string TRANSITIONAL_MARKER = '@orbit-ssh-lane transitional-ssh';

    private const string PROVISIONING_MARKER = '@orbit-ssh-lane provisioning-ssh';

    /**
     * @param  array<string, string>  $consumers
     * @return array{
     *     provisioning_ssh: list<array{path: string, marker_line: int}>,
     *     transitional_ssh: list<array{path: string, marker_line: int}>,
     *     unmarked_consumers: list<string>,
     * }
     */
    public function classify(array $consumers): array
    {
        $provisioning = [];
        $transitional = [];
        $unmarked = [];

        foreach ($consumers as $path => $contents) {
            $transitionalLine = $this->markerLine($contents, self::TRANSITIONAL_MARKER);
            $provisioningLine = $this->markerLine($contents, self::PROVISIONING_MARKER);

            if ($transitionalLine !== null && $provisioningLine !== null) {
                throw new RuntimeException("SSH consumer {$path} declares both execution-lane markers.");
            }

            if ($transitionalLine !== null) {
                $transitional[] = ['path' => $path, 'marker_line' => $transitionalLine];

                continue;
            }

            if ($provisioningLine !== null) {
                $provisioning[] = ['path' => $path, 'marker_line' => $provisioningLine];

                continue;
            }

            $unmarked[] = $path;
        }

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

    private function markerLine(string $contents, string $marker): ?int
    {
        foreach (explode("\n", $contents) as $index => $line) {
            if (str_contains($line, $marker)) {
                return $index + 1;
            }
        }

        return null;
    }
}
