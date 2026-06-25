<?php

declare(strict_types=1);

namespace App\Commands\Php;

use Symfony\Component\Console\Output\OutputInterface;

final class PhpUseHumanDriftNotes
{
    /**
     * @param  array<string, mixed>  $response
     */
    public static function render(OutputInterface $output, array $response): void
    {
        foreach (self::messages($response) as $message) {
            $output->writeln('  Drift detected: '.$message);
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return list<string>
     */
    private static function messages(array $response): array
    {
        $meta = $response['success']['meta'] ?? null;

        if (! is_array($meta)) {
            return [];
        }

        $warnings = $meta['warnings'] ?? null;

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            $message = $warning['message'] ?? null;

            if (! is_string($message) || trim($message) === '') {
                continue;
            }

            $messages[] = trim($message);
        }

        return $messages;
    }
}
