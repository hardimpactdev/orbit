<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Symfony\Component\Console\Output\OutputInterface;

class LiveIncusStepTree
{
    /**
     * @param  list<string>  $steps
     */
    public function renderInitial(OutputInterface $output, string $title, array $steps): void
    {
        $output->writeln("  ┌  {$title}");

        foreach ($steps as $step) {
            $output->writeln('  │');
            $output->writeln("  ○  {$step}");
        }

        $output->writeln('  │');
        $output->writeln('  └  Working...');
    }

    public function renderComplete(OutputInterface $output, string $footer): void
    {
        $output->writeln("  └  {$footer}");
    }
}
