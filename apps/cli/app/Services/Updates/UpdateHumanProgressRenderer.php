<?php

declare(strict_types=1);

namespace App\Services\Updates;

use Symfony\Component\Console\Output\OutputInterface;

final class UpdateHumanProgressRenderer
{
    private const array STEP_LABELS = [
        'pull_source' => 'Download binary',
    ];

    public function begin(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('┌  Updating Orbit');

        foreach (self::STEP_LABELS as $label) {
            $output->writeln("○  {$label}");
        }

        $output->writeln('└  Working...');

        if ($output->isDecorated()) {
            $output->write("\e[?25l");
        }
    }

    /**
     * @param  array{successful: bool, exit_code: int, output: string}  $result
     */
    public function recordStep(OutputInterface $output, int $index, string $step, array $result): void
    {
        $label = self::STEP_LABELS[$step];

        if ($result['successful']) {
            $this->replaceStepLine($output, $index, "●  {$label}  completed");

            return;
        }

        $message = $result['output'] !== ''
            ? $result['output']
            : "exit code {$result['exit_code']}";

        $this->replaceStepLine($output, $index, "●  {$label}  {$message}");
    }

    public function finish(OutputInterface $output, bool $successful): void
    {
        $this->replaceFooter($output, $successful ? '└  Done' : '└  Failed');

        if ($output->isDecorated()) {
            $output->write("\e[?25h");
            $output->writeln('');
        }
    }

    private function replaceStepLine(OutputInterface $output, int $index, string $line): void
    {
        if (! $output->isDecorated()) {
            $output->writeln($line);

            return;
        }

        $up = count(self::STEP_LABELS) - $index + 1;
        $output->write("\e[{$up}A\e[2K\r{$line}\e[{$up}B\r");
    }

    private function replaceFooter(OutputInterface $output, string $line): void
    {
        if (! $output->isDecorated()) {
            $output->writeln($line);

            return;
        }

        $output->write("\e[1A\e[2K\r{$line}\e[1B\r");
    }
}
